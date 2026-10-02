<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Testing;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\FreezeRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionAttempt;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRecord;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\DataTransferObjects\UnfreezeRequest;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\TransitionDefinition;
use RoundlyConsulting\Lifecycle\Engine\GuardPipeline;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Installed by `Lifecycles::fake()`. A manager subtype, so injected managers are faked too.
 *
 * It evaluates the real compiled definition's structural checks (unknown transition, wrong
 * source, terminal state, system-only) against the in-memory attribute and, when they pass,
 * changes that attribute in memory — no database writes, no events, no jobs. DB-backed
 * checks (guards, limits, quotas) are skipped; steer outcomes with `denyNext()` / `deny()`.
 * The model hooks that only touch the in-memory model (initial state, strict writes) keep
 * their real behaviour; the DB-touching ones do nothing.
 */
final class LifecycleFake extends LifecycleManager
{
    /** @var list<RecordedCall> */
    private array $calls = [];

    /** @var array<string, Denial> */
    private array $sticky = [];

    /** @var array<string, Denial> */
    private array $once = [];

    private int $sequence = 0;

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    /**
     * Deny the next application of `$transition` (one-shot).
     */
    public function denyNext(string $transition, DenialCode|string $code = DenialCode::GuardFailed, ?string $message = null): static
    {
        $this->once[$transition] = Denial::of($code, ['transition' => $transition, 'state' => ''], $message, source: 'fake');

        return $this;
    }

    /**
     * Deny every application of `$transition` from now on.
     */
    public function deny(string $transition, DenialCode|string $code, ?string $message = null): static
    {
        $this->sticky[$transition] = Denial::of($code, ['transition' => $transition, 'state' => ''], $message, source: 'fake');

        return $this;
    }

    public function apply(TransitionRequest $request): TransitionResult
    {
        $this->guardPersisted($request->subject);
        $definition = $this->definitions()->of($request->subject, $request->lifecycle);
        $from = $this->current($request->subject, $request->lifecycle, $definition);
        $transition = $this->evaluate($request, $definition, $from, consume: true);

        if ($transition instanceof Decision) {
            $this->calls[] = new RecordedCall('apply', $request, denied: $transition);

            throw TransitionDeniedException::because($transition);
        }

        $subject = $request->subject;
        $attributes = $subject->getAttributes();
        $attributes[$request->lifecycle] = $definition->encode($transition->to);
        $subject->setRawAttributes($attributes);
        $subject->syncOriginalAttribute($request->lifecycle);

        $this->sequence++;

        $result = new TransitionResult(
            subject: $subject,
            lifecycle: $request->lifecycle,
            transition: $transition->name,
            from: $definition->value($from),
            to: $definition->value($transition->to),
            record: new TransitionRecord(
                id: $this->sequence,
                lifecycle: $request->lifecycle,
                kind: TransitionKind::Transition,
                transition: $transition->name,
                from: $definition->value($from),
                to: $definition->value($transition->to),
                actorType: $request->actor?->getMorphClass(),
                actorId: $request->actor?->getKey(),
                system: $request->system,
                reason: $request->reason,
                context: $request->payload,
                snapshot: null,
                version: $this->sequence,
                revertsId: null,
                scheduleId: null,
                occurredAt: Clock::now(),
                reverted: false,
            ),
        );

        $this->calls[] = new RecordedCall('apply', $request, $result);

        return $result;
    }

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
        $this->guardPersisted($request->subject);
        $definition = $this->definitions()->of($request->subject, $request->lifecycle);
        $outcome = $this->evaluate($request, $definition, $this->current($request->subject, $request->lifecycle, $definition), consume: false);

        return $outcome instanceof Decision ? $outcome : Decision::allow();
    }

    /**
     * @return list<AvailableTransition>
     */
    public function available(AvailableTransitionsQuery $query): array
    {
        $definition = $this->definitions()->of($query->subject, $query->lifecycle);
        $current = $this->current($query->subject, $query->lifecycle, $definition);
        $available = [];

        foreach ($definition->transitionsFrom($current) as $transition) {
            $decision = $this->check(new TransitionRequest($query->subject, $query->lifecycle, $transition->name, actor: $query->actor, system: $query->system));

            if ($decision->denied() && ! $query->includeDenied) {
                continue;
            }

            $available[] = new AvailableTransition(
                name: $transition->name,
                label: $transition->label(),
                to: $definition->value($transition->to),
                toLabel: $definition->stateLabel($transition->to),
                allowed: $decision->allowed,
                denials: $decision->denials,
                requiresReason: $transition->requiresReason && ! $query->system,
                payloadFields: $transition->payloadFields(),
                availableAt: null,
                meta: $transition->meta,
            );
        }

        return $available;
    }

    public function freeze(FreezeRequest $request): bool
    {
        $this->calls[] = new RecordedCall('freeze', $request, true);

        return true;
    }

    public function unfreeze(UnfreezeRequest $request): bool
    {
        $this->calls[] = new RecordedCall('unfreeze', $request, true);

        return true;
    }

    public function adopt(Model $subject, ?string $lifecycle = null): bool
    {
        $this->calls[] = new RecordedCall('adopt', $subject, false);

        return false;
    }

    public function adoptAll(string $class, ?string $lifecycle = null, int $chunk = 500, bool $scheduleExpiry = true): int
    {
        $this->calls[] = new RecordedCall('adoptAll', $this->model($class, $lifecycle), 0);

        return 0;
    }

    /**
     * @internal
     */
    public function initialize(Model $subject): void {}

    /**
     * @internal
     */
    public function subjectSaved(Model $subject): void {}

    /**
     * @internal
     */
    public function subjectDeleted(Model $subject, bool $forced): void {}

    /**
     * @return list<RecordedCall>
     */
    public function recorded(): array
    {
        return $this->calls;
    }

    /**
     * @param  (Closure(TransitionResult): bool)|null  $callback
     */
    public function assertTransitioned(Model $subject, ?string $transition = null, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->transitions($subject, $transition, $callback),
            sprintf('Expected [%s] to be transitioned%s, but it was not.', $subject::class, $transition === null ? '' : " via [{$transition}]"),
        );
    }

    public function assertTransitionedTo(Model $subject, BackedEnum|string|int $state, ?string $lifecycle = null): void
    {
        $matching = array_filter(
            $this->transitions($subject, null, null),
            function (TransitionResult $result) use ($state, $lifecycle): bool {
                if ($lifecycle !== null && $result->lifecycle !== $lifecycle) {
                    return false;
                }

                $definition = $this->definitions()->of($result->subject, $result->lifecycle);

                return $definition->codec->tryKey($state) === $definition->key($result->to);
            },
        );

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] to be transitioned to the given state, but it was not.', $subject::class));
    }

    public function assertNotTransitioned(Model $subject, ?string $transition = null): void
    {
        PHPUnit::assertEmpty(
            $this->transitions($subject, $transition, null),
            sprintf('Expected [%s] not to be transitioned%s, but it was.', $subject::class, $transition === null ? '' : " via [{$transition}]"),
        );
    }

    public function assertNothingTransitioned(): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->result instanceof TransitionResult));

        PHPUnit::assertSame(0, $count, sprintf('Expected nothing to be transitioned, but %d transition(s) were applied.', $count));
    }

    public function assertTransitionDenied(Model $subject, ?string $transition = null, DenialCode|string|null $code = null): void
    {
        $matching = array_filter($this->calls, static function (RecordedCall $call) use ($subject, $transition, $code): bool {
            return $call->denied !== null
                && $call->request instanceof TransitionRequest
                && $call->request->subject->is($subject)
                && ($transition === null || $call->request->transition === $transition)
                && ($code === null || $call->denied->has($code));
        });

        PHPUnit::assertNotEmpty($matching, sprintf('Expected a transition of [%s] to be denied, but none was.', $subject::class));
    }

    public function assertFrozen(Model $subject, ?string $lifecycle = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->requests('freeze', $subject, $lifecycle),
            sprintf('Expected [%s] to be frozen, but it was not.', $subject::class),
        );
    }

    public function assertUnfrozen(Model $subject, ?string $lifecycle = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->requests('unfreeze', $subject, $lifecycle),
            sprintf('Expected [%s] to be unfrozen, but it was not.', $subject::class),
        );
    }

    public function assertNothingFrozen(): void
    {
        $count = count(array_filter($this->calls, static fn (RecordedCall $call): bool => $call->method === 'freeze'));

        PHPUnit::assertSame(0, $count, sprintf('Expected nothing to be frozen, but %d freeze(s) were recorded.', $count));
    }

    public function assertAdopted(?Model $subject = null): void
    {
        $matching = array_filter($this->calls, static function (RecordedCall $call) use ($subject): bool {
            return in_array($call->method, ['adopt', 'adoptAll'], true)
                && ($subject === null || ($call->request instanceof Model && $call->request->is($subject)));
        });

        PHPUnit::assertNotEmpty($matching, 'Expected a lifecycle adoption, but none was recorded.');
    }

    /**
     * Recorded requests of one method for a subject (and lifecycle).
     *
     * @return list<object>
     */
    private function requests(string $method, Model $subject, ?string $lifecycle): array
    {
        $requests = [];

        foreach ($this->calls as $call) {
            $request = $call->request;

            if ($call->method === $method && property_exists($request, 'subject') && $request->subject instanceof Model
                && $request->subject->is($subject)
                && ($lifecycle === null || (property_exists($request, 'lifecycle') && $request->lifecycle === $lifecycle))) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * @param  (Closure(TransitionResult): bool)|null  $callback
     * @return list<TransitionResult>
     */
    private function transitions(Model $subject, ?string $transition, ?Closure $callback): array
    {
        $results = [];

        foreach ($this->calls as $call) {
            $result = $call->result;

            if ($result instanceof TransitionResult && $result->subject->is($subject)
                && ($transition === null || $result->transition === $transition)
                && ($callback === null || $callback($result))) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * The real structural checks (rows 1–3 and 7) plus the fake's own denials.
     */
    private function evaluate(TransitionRequest $request, CompiledDefinition $definition, string $current, bool $consume): Decision|TransitionDefinition
    {
        $transition = $this->container->make(GuardPipeline::class)
            ->resolve($definition, $request->transition, $request->target, $current);

        if ($transition instanceof Denial) {
            return Decision::deny($transition);
        }

        $params = ['transition' => $transition->label(), 'state' => $definition->stateLabel($current)];

        if (! $request->system && $transition->systemOnly) {
            return Decision::deny(Denial::of(DenialCode::SystemOnly, $params, source: 'context'));
        }

        if ($request->system && ! $transition->allowsSystem()) {
            return Decision::deny(Denial::of(DenialCode::SystemNotAllowed, $params, source: 'context'));
        }

        $denial = $this->once[$transition->name] ?? $this->sticky[$transition->name] ?? null;

        if ($consume) {
            unset($this->once[$transition->name]);
        }

        return $denial === null ? $transition : Decision::deny($denial);
    }

    private function guardPersisted(Model $subject): void
    {
        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }
    }

    private function current(Model $subject, string $lifecycle, CompiledDefinition $definition): string
    {
        $raw = $subject->getAttributes()[$lifecycle] ?? null;

        return $raw === null ? throw UnknownStateException::notInitialized($subject, $lifecycle) : $definition->key($raw);
    }
}
