<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\LockedSubject;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Runs from the `saved` model event: when a state's expiry comes from an attribute and the
 * host changed that attribute, the pending expiry follows it (a null attribute clears it).
 * An expiry set with `expireAt()`/`extend()`/`renew()` is an override and stays.
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

        foreach ($lifecycles as $lifecycle) {
            $lifecycle = (string) $lifecycle;
            $definition = $this->registry->of($subject, $lifecycle);
            $raw = $subject->getAttributes()[$lifecycle] ?? null;
            $ttl = $raw === null ? null : $definition->state($definition->key($raw))->ttl;

            if ($ttl === null || $ttl->attribute === null || ! $subject->wasChanged($ttl->attribute)) {
                continue;
            }

            $this->locked->run($subject, $lifecycle, function (LifecycleState $record, CompiledDefinition $definition) use ($subject, $lifecycle, $ttl): void {
                $now = Clock::now();
                $pending = $this->schedules->open($subject, $lifecycle, ScheduleBook::EXPIRY_SLOT);

                if ($pending !== null && $pending->is_override) {
                    return;
                }

                $expiresAt = $this->schedules->resolveExpiry($subject, $ttl, $now);

                if ($expiresAt === null) {
                    if ($pending !== null) {
                        $this->schedules->finish($pending, ScheduleStatus::Cancelled, ScheduleOutcome::Cancelled, $now);
                    }

                    return;
                }

                $this->schedules->replace(
                    $subject,
                    $lifecycle,
                    ScheduleBook::EXPIRY_SLOT,
                    $this->schedules->expiryValues($ttl, $record->state, $expiresAt, $pending?->created_by_transition_id, false),
                    $now,
                );
            });
        }
    }
}
