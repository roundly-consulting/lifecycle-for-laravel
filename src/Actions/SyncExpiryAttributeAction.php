<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\LockedSubject;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Runs from the `saved` model event: when a state's expiry comes from an attribute and the
 * host changed that attribute, the pending expiry follows it (a null attribute clears it).
 * An expiry set with `expireAt()`/`extend()`/`renew()` is an override and stays, and so does a
 * stay cleared with `neverExpire()`.
 *
 * @internal
 */
final readonly class SyncExpiryAttributeAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private LockedSubject $locked,
        private ScheduleBook $schedules,
    ) {}

    public function execute(Model $subject, ?string $lifecycle = null): void
    {
        $lifecycles = $lifecycle === null ? array_keys($this->registry->definitionsOf($subject)) : [$lifecycle];

        foreach ($lifecycles as $name) {
            $name = (string) $name;

            if (! $this->expiryAttributeChanged($subject, $this->registry->of($subject, $name))) {
                continue;
            }

            // The TTL is the one of the state the locked row is in — a stale model may still
            // believe it is in another. A soft-deleted subject's rows follow too, and stay paused.
            $this->locked->run($subject, $name, function (LifecycleState $record, CompiledDefinition $definition) use ($subject, $name): void {
                $ttl = $definition->state($record->state)->ttl;

                if ($ttl === null || $ttl->attribute === null || ! $subject->wasChanged($ttl->attribute)) {
                    return;
                }

                $this->schedules->followAttribute($subject, $name, $record, $ttl, Clock::now());
            }, allowTrashed: true);
        }
    }

    private function expiryAttributeChanged(Model $subject, CompiledDefinition $definition): bool
    {
        foreach ($definition->expiringStates() as $state) {
            $attribute = $state->ttl?->attribute;

            if ($attribute !== null && $subject->wasChanged($attribute)) {
                return true;
            }
        }

        return false;
    }
}
