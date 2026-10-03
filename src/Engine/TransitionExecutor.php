<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Lifecycle\Contracts\StateHook;
use RoundlyConsulting\Lifecycle\Contracts\TransitionHandler;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\StateHookContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\StateDefinition;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitionDenied;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Exceptions\ConcurrentTransitionException;
use RoundlyConsulting\Lifecycle\Exceptions\IdempotencyConflictException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;
use RoundlyConsulting\Lifecycle\Support\Transactions;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;
use RoundlyConsulting\Lifecycle\Support\WriteGuard;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

/**
 * Applies one transition: lock the subject, reconcile its record, re-run the whole pipeline
 * under the lock, then write the state with a compare-and-swap, run hooks and the handler,
 * append history, update the record and schedules — all in one transaction on the
 * subject's connection. Any failure rolls everything back and restores the in-memory model.
 *
 * @internal
 */
final readonly class TransitionExecutor
{
    public function __construct(
        private Container $container,
        private DefinitionRegistry $registry,
        private Dispatcher $events,
        private GuardPipeline $pipeline,
        private ContextFactory $contexts,
        private StateRecords $records,
        private SubjectLocker $locker,
        private ScheduleBook $schedules,
        private ActorResolver $actors,
        private WriteGuard $writes,
    ) {}

    public function apply(TransitionRequest $request): TransitionResult
    {
        $subject = $request->subject;
        $lifecycle = $request->lifecycle;

        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }

        $definition = $this->registry->of($subject, $lifecycle);

        if ($subject->isDirty($lifecycle)) {
            throw InvalidLifecycleUsageException::dirtyStateAttribute($subject::class, $lifecycle);
        }

        if ($request->idempotencyKey !== null) {
            $replay = $this->replay($request, $definition, lock: false);

            if ($replay !== null) {
                return $replay;
            }
        }

        $restore = RestorePoint::capture($subject);
        $actor = $this->actors->resolve($request->actor, $request->system);

        try {
            $result = Transactions::run($subject, function () use ($request, $definition, $restore, $actor): TransitionResult {
                // A retried attempt (deadlock) starts from the caller's model, not the last attempt's.
                $restore->restore();

                $this->locker->lock($request->subject);
                $record = $this->records->lock($request->subject, $request->lifecycle, $definition)->record;

                if ($request->idempotencyKey !== null) {
                    $replay = $this->replay($request, $definition, lock: true);

                    if ($replay !== null) {
                        $restore->restore();

                        return $replay;
                    }
                }

                $current = $definition->key($request->subject->getRawOriginal($request->lifecycle));
                $transition = $this->pipeline->resolve($definition, $request->transition, $request->target, $current);

                if ($transition instanceof Denial) {
                    throw TransitionDeniedException::because(Decision::deny($transition));
                }

                $evaluation = $this->contexts->evaluation($definition, $request, $transition, $current, Mode::Apply, $record, $actor);
                $decision = $this->pipeline->evaluate($evaluation);

                if ($decision->denied()) {
                    throw TransitionDeniedException::because($decision);
                }

                $limited = $this->pipeline->consumeRateLimits($evaluation);

                if ($limited->denied()) {
                    throw TransitionDeniedException::because($limited);
                }

                return $this->perform($evaluation, $record, TransitionKind::Transition, $request->idempotencyKey);
            }, countsQuotas: $definition->quotasOnTarget($request->transition, $request->target));
        } catch (TransitionDeniedException $exception) {
            $restore->restore();
            $this->events->dispatch(new LifecycleTransitionDenied(
                subjectType: $subject->getMorphClass(),
                subjectId: StateRecords::key($subject),
                lifecycle: $lifecycle,
                transition: $request->transition,
                actor: $actor,
                denials: $exception->denials(),
            ));

            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            $restore->restore();
            $replay = $request->idempotencyKey === null ? null : $this->replay($request, $definition, lock: true);

            return $replay ?? throw $exception;
        } catch (Throwable $exception) {
            $restore->restore();

            throw $exception;
        }

        RestorePoint::forgetRelations($subject);

        return $result;
    }

    /**
     * Steps h–r: the write itself, inside the caller's transaction, with the subject locked
     * and the pipeline passed.
     *
     * @param  array<string, mixed>  $extraContext  schedule metadata stored beside the payload
     */
    public function perform(Evaluation $evaluation, LifecycleState $record, TransitionKind $kind, ?string $idempotencyKey = null, array $extraContext = []): TransitionResult
    {
        $context = $evaluation->context;
        $definition = $evaluation->definition;
        $subject = $context->subject;
        $lifecycle = $context->lifecycle;
        $transition = $context->transition;
        $from = $evaluation->fromKey();
        $to = $transition->to;
        $self = $from === $to;
        $now = $context->now;
        $target = $definition->state($to);

        // The quota counted the stored partition: an entry may not also move the subject to another.
        $this->guardQuotaScope($subject, $target, $self);

        $this->events->dispatch(new LifecycleTransitioning(
            $subject, $lifecycle, $transition->name, $context->from, $context->to, $context->actor, $context->system,
        ));

        $captured = array_values(array_unique([...$transition->snapshots, ...$target->stamps]));
        $before = Snapshotter::capture($subject, $captured);

        $this->write($subject, $lifecycle, $definition, $from, $to, $target->stamps);

        $this->writes->engine($subject, $lifecycle, function () use ($definition, $context, $from, $to, $self, $target): void {
            if (! $self) {
                foreach ($definition->state($from)->onExit as $hook) {
                    $this->runHook($hook, new StateHookContext($context->subject, $context->lifecycle, $context->from ?? $definition->value($from), $context));
                }
            }

            if ($context->transition->handler !== null) {
                $this->runHandler($context->transition->handler, $context);
            }

            if (! $self) {
                foreach ($definition->state($to)->onEnter as $hook) {
                    $this->runHook($hook, new StateHookContext($context->subject, $context->lifecycle, $context->to, $context));
                }
            }

            $this->guardQuotaScope($context->subject, $target, $self);

            // The compare-and-swap wrote this attribute: a handler or hook may not change it.
            if ($context->subject->isDirty($context->lifecycle)) {
                throw InvalidLifecycleUsageException::dirtyStateAttribute($context->subject::class, $context->lifecycle);
            }

            if ($context->subject->isDirty()) {
                $context->subject->save();
            }
        });

        $after = Snapshotter::stored($subject, $captured);
        $stored = Config::boolean('lifecycle.history.store_payload', true) ? $evaluation->storedContext : [];
        $stored = [...$stored, ...$extraContext];
        $version = $record->version + 1;

        $row = TransitionModel::newFor($subject);
        $row->forceFill([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'lifecycle' => $lifecycle,
            'kind' => $kind,
            'transition' => $transition->name,
            'from_state' => $from,
            'to_state' => $to,
            'actor_type' => $context->actor?->getMorphClass(),
            'actor_id' => $context->actor?->getKey(),
            'is_system' => $context->system,
            'reason' => $context->reason,
            'context' => $stored === [] ? null : $stored,
            'snapshot' => $captured === [] ? null : ['before' => $before, 'after' => $after],
            'previous_entered_at' => $record->entered_at,
            'counter_before' => CounterBook::entry($record->counters, $transition->name),
            'version' => $version,
            'schedule_id' => $context->scheduleId,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => $now,
        ])->save();

        $record->forceFill([
            'state' => $to,
            'previous_state' => $from,
            'entered_at' => $self ? $record->entered_at : $now,
            'version' => $version,
            'counters' => CounterBook::increment($record->counters, $transition->name, $now),
        ])->save();

        if ($self) {
            $this->schedules->stay($subject, $lifecycle, $definition, $record, $row->id, $now);
        } else {
            $this->schedules->leave($subject, $lifecycle, $from, $row->id, $now);
            $this->schedules->enter($subject, $lifecycle, $definition, $to, $row->id, $now);
        }

        $this->events->dispatch(new LifecycleTransitioned(
            subject: $subject,
            subjectType: $subject->getMorphClass(),
            subjectId: StateRecords::key($subject),
            lifecycle: $lifecycle,
            kind: $kind,
            transition: $transition->name,
            from: $context->from,
            to: $context->to,
            actor: $context->actor,
            system: $context->system,
            historyId: $row->id,
            version: $version,
        ));

        return new TransitionResult(
            subject: $subject,
            lifecycle: $lifecycle,
            transition: $transition->name,
            from: $context->from,
            to: $context->to,
            record: $row->toRecord($definition, reverted: false),
        );
    }

    /**
     * The compare-and-swap. A self-transition writes only stamps and `updated_at` (MySQL
     * counts changed rows, so a no-op state write would report 0); every other write has
     * `:to ≠ :from`, so a matched row is a changed row on every driver.
     *
     * @param  list<string>  $stamps
     */
    public function write(Model $subject, string $lifecycle, CompiledDefinition $definition, string $from, string $to, array $stamps): void
    {
        $self = $from === $to;
        $values = [];

        if (! $self) {
            $values[$lifecycle] = $definition->encode($to);
        }

        if ($stamps !== [] || $subject->usesTimestamps()) {
            $timestamp = $subject->freshTimestampString();

            foreach ($stamps as $column) {
                $values[$column] = $timestamp;
            }

            $updatedAt = $subject->usesTimestamps() ? $subject->getUpdatedAtColumn() : null;

            if ($updatedAt !== null) {
                $values[$updatedAt] = $timestamp;
            }
        }

        if ($values === []) {
            return;
        }

        $query = $subject->newQueryWithoutScopes()->whereKey($subject->getKey());

        if (! $self) {
            $query->where($lifecycle, $subject->getRawOriginal($lifecycle));
        }

        $affected = $query->toBase()->update($values);

        if (! $self && $affected !== 1) {
            throw ConcurrentTransitionException::lostRace($subject, $lifecycle);
        }

        $attributes = $subject->getAttributes();

        foreach ($values as $column => $value) {
            $attributes[$column] = $value;
        }

        $subject->setRawAttributes($attributes);
        $subject->syncOriginalAttributes(array_keys($values));
    }

    /**
     * A transition entering a state with quotas may not change a scope column — neither the
     * caller's unsaved edit nor a handler's: the count and the mutex were for the stored values.
     */
    private function guardQuotaScope(Model $subject, StateDefinition $target, bool $self): void
    {
        if ($self) {
            return;
        }

        foreach ($target->quotas as $quota) {
            foreach ($quota->scope as $column) {
                if ($subject->isDirty($column)) {
                    throw InvalidLifecycleUsageException::quotaScopeChanged($subject::class, $column);
                }
            }
        }
    }

    /**
     * @param  StateHook|class-string<StateHook>|Closure  $hook
     */
    public function runHook(StateHook|string|Closure $hook, StateHookContext $context): void
    {
        if ($hook instanceof Closure) {
            $hook($context);

            return;
        }

        $resolved = is_string($hook) ? $this->container->make($hook) : $hook;

        if (! $resolved instanceof StateHook) {
            throw InvalidLifecycleUsageException::invalidExtension('state hook', $hook);
        }

        $resolved($context);
    }

    /**
     * @param  TransitionHandler|class-string<TransitionHandler>|Closure  $handler
     */
    public function runHandler(TransitionHandler|string|Closure $handler, TransitionContext $context): void
    {
        if ($handler instanceof Closure) {
            $handler($context);

            return;
        }

        $resolved = is_string($handler) ? $this->container->make($handler) : $handler;

        if (! $resolved instanceof TransitionHandler) {
            throw InvalidLifecycleUsageException::invalidExtension('transition handler', $handler);
        }

        $resolved->handle($context);
    }

    /**
     * An earlier application with the same idempotency key: its result, unchanged — no
     * guards, writes, events or rate-limit hits. The same key with another transition is
     * a conflict.
     */
    private function replay(TransitionRequest $request, CompiledDefinition $definition, bool $lock): ?TransitionResult
    {
        $query = TransitionModel::of($request->subject, $request->lifecycle)
            ->where('idempotency_key', $request->idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        $conflict = $request->transition !== null
            ? $row->transition !== $request->transition
            : $row->to_state !== $definition->key($request->target);

        if ($conflict) {
            throw IdempotencyConflictException::for(
                (string) $request->idempotencyKey,
                $row->transition,
                $request->transition ?? self::describe($request->target),
            );
        }

        return new TransitionResult(
            subject: $request->subject,
            lifecycle: $request->lifecycle,
            transition: $row->transition,
            from: $row->from_state === null ? null : $definition->value($row->from_state),
            to: $definition->value($row->to_state),
            record: $row->toRecord($definition),
            replayed: true,
        );
    }

    private static function describe(mixed $state): string
    {
        return $state instanceof BackedEnum ? (string) $state->value : (is_scalar($state) ? (string) $state : '');
    }
}
