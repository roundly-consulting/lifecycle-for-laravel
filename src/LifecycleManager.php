<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Accessors\DefinitionsAccessor;
use RoundlyConsulting\Lifecycle\Actions\AdoptLifecycleAction;
use RoundlyConsulting\Lifecycle\Actions\AdoptModelLifecyclesAction;
use RoundlyConsulting\Lifecycle\Actions\ApplyTransitionAction;
use RoundlyConsulting\Lifecycle\Actions\CheckTransitionAction;
use RoundlyConsulting\Lifecycle\Actions\InitializeLifecycleAction;
use RoundlyConsulting\Lifecycle\Actions\ListAvailableTransitionsAction;
use RoundlyConsulting\Lifecycle\Actions\SubjectDeletedAction;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionAttempt;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Support\WriteGuard;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The package's public API: the root behind the Lifecycles facade, injectable by its own
 * class-string, bound as a singleton. Every method is thin — it resolves one action through
 * the container and calls `execute()` — so host container overrides and the fake both apply.
 * Model traits call this manager, never an action, so `Lifecycles::fake()` sees them too.
 *
 * Not final on purpose: LifecycleFake extends it, so code that constructor-injects this
 * class still type-checks under `Lifecycles::fake()`.
 */
class LifecycleManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * A handle on one lifecycle of a subject (the primary one when none is named).
     */
    public function for(Model $subject, ?string $lifecycle = null): LifecycleHandle
    {
        return new LifecycleHandle($this, $subject, $this->registry()->lifecycleName($subject, $lifecycle));
    }

    /**
     * Class-level operations on one lifecycle of a model.
     *
     * @param  class-string<Model>  $class
     */
    public function model(string $class, ?string $lifecycle = null): ModelLifecycle
    {
        return new ModelLifecycle($this, $class, $this->registry()->lifecycleName($class, $lifecycle));
    }

    public function definitions(): DefinitionsAccessor
    {
        return new DefinitionsAccessor($this->container);
    }

    public function apply(TransitionRequest $request): TransitionResult
    {
        return $this->container->make(ApplyTransitionAction::class)->execute($request);
    }

    /**
     * Like `apply()`, with a refusal returned as a value instead of thrown.
     */
    public function attempt(TransitionRequest $request): TransitionAttempt
    {
        try {
            return new TransitionAttempt(true, $this->apply($request), Decision::allow());
        } catch (TransitionDeniedException $exception) {
            return new TransitionAttempt(false, null, $exception->decision());
        }
    }

    public function check(TransitionRequest $request): Decision
    {
        return $this->container->make(CheckTransitionAction::class)->execute($request);
    }

    /**
     * @return list<AvailableTransition>
     */
    public function available(AvailableTransitionsQuery $query): array
    {
        return $this->container->make(ListAvailableTransitionsAction::class)->execute($query);
    }

    /**
     * Reconcile one subject with its stored state; true when anything changed.
     */
    public function adopt(Model $subject, ?string $lifecycle = null): bool
    {
        return $this->container->make(AdoptLifecycleAction::class)
            ->execute($subject, $this->registry()->lifecycleName($subject, $lifecycle));
    }

    /**
     * Reconcile every row of a model; returns how many changed.
     *
     * @param  class-string<Model>  $class
     */
    public function adoptAll(string $class, ?string $lifecycle = null, int $chunk = 500, bool $scheduleExpiry = true): int
    {
        return $this->container->make(AdoptModelLifecyclesAction::class)
            ->execute($class, $this->registry()->lifecycleName($class, $lifecycle), $chunk, $scheduleExpiry);
    }

    /**
     * Run code that may change lifecycle attributes directly, with strict writes on. The
     * change is adopted (recorded) when the model is saved.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function allowDirectWrites(Closure $callback): mixed
    {
        return $this->container->make(WriteGuard::class)->allowDirectWrites($callback);
    }

    /**
     * `creating`: a NULL lifecycle attribute gets the initial state; a declared value is kept
     * (factories may create subjects in any state); an undeclared one throws. Pure — no DB.
     *
     * @internal
     */
    public function assignInitialState(Model $subject): void
    {
        $registry = $this->registry();
        $attributes = $subject->getAttributes();

        foreach (array_keys($registry->definitionsOf($subject)) as $lifecycle) {
            $definition = $registry->of($subject, (string) $lifecycle);
            $raw = $attributes[$lifecycle] ?? null;

            $attributes[$lifecycle] = $definition->encode($raw ?? $definition->initial);
        }

        $subject->setRawAttributes($attributes);
    }

    /**
     * `created`: record, initial history row and expiry.
     *
     * @internal
     */
    public function initialize(Model $subject): void
    {
        $this->container->make(InitializeLifecycleAction::class)->execute($subject);
    }

    /**
     * `updating`: with strict writes on, a direct change of a lifecycle attribute throws
     * unless inside `allowDirectWrites()`; an allowed one must still be a declared state.
     * Pure — no DB.
     *
     * @internal
     */
    public function guardDirectWrite(Model $subject): void
    {
        $guard = $this->container->make(WriteGuard::class);

        if ($guard->inEngine()) {
            return;
        }

        $registry = $this->registry();

        foreach (array_keys($registry->definitionsOf($subject)) as $lifecycle) {
            $lifecycle = (string) $lifecycle;

            if (! $subject->isDirty($lifecycle)) {
                continue;
            }

            if (Config::boolean('lifecycle.strict_writes', true) && ! $guard->allowsDirectWrites()) {
                throw DirectStateWriteException::for($subject, $lifecycle);
            }

            $raw = $subject->getAttributes()[$lifecycle] ?? null;

            if ($raw === null) {
                throw UnknownStateException::notInitialized($subject, $lifecycle);
            }

            $registry->of($subject, $lifecycle)->key($raw);
        }
    }

    /**
     * `saved`: a lifecycle attribute changed by an allowed direct write is adopted right away,
     * so model saves never leave drift behind.
     *
     * @internal
     */
    public function subjectSaved(Model $subject): void
    {
        if ($this->container->make(WriteGuard::class)->inEngine()) {
            return;
        }

        foreach (array_keys($this->registry()->definitionsOf($subject)) as $lifecycle) {
            $lifecycle = (string) $lifecycle;

            if ($subject->isDirty($lifecycle) && $subject->wasChanged($lifecycle)) {
                $this->container->make(AdoptLifecycleAction::class)->execute($subject, $lifecycle);
            }
        }
    }

    /**
     * `deleted`: purge on a force delete.
     *
     * @internal
     */
    public function subjectDeleted(Model $subject, bool $forced): void
    {
        $this->container->make(SubjectDeletedAction::class)->execute($subject, $forced);
    }

    protected function registry(): DefinitionRegistry
    {
        return $this->container->make(DefinitionRegistry::class);
    }
}
