<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ExpiryChangeRequest;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Engine\LockedSubject;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Enums\ExpiryChange;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\ExpiryException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\Durations;

/**
 * Changes the pending expiry of the current stay: `Set` an instant, `Extend` it, `Renew` it
 * from now (by the state's TTL unless an interval is given), or `Clear` it. Each replaces
 * the expiry row, so warnings start over; the new row is an override that survives a later
 * change of the expiry attribute. Returns the new expiry (null when cleared). Authorization
 * is the host's.
 */
final readonly class ChangeExpiryAction
{
    public function __construct(
        private LockedSubject $locked,
        private ScheduleBook $schedules,
        private ActorResolver $actors,
    ) {}

    public function execute(ExpiryChangeRequest $request): ?CarbonImmutable
    {
        $actor = $this->actors->resolve($request->actor, false);

        return $this->locked->run($request->subject, $request->lifecycle, function (LifecycleState $record, CompiledDefinition $definition) use ($request, $actor): ?CarbonImmutable {
            $now = Clock::now();
            $state = $definition->key($request->subject->getRawOriginal($request->lifecycle));
            $ttl = $definition->state($state)->ttl ?? throw ExpiryException::stateCannotExpire($state);
            $pending = $this->schedules->open($request->subject, $request->lifecycle, ScheduleBook::EXPIRY_SLOT);

            if ($request->change === ExpiryChange::Clear) {
                if ($pending !== null) {
                    $this->schedules->finish($pending, ScheduleStatus::Cancelled, ScheduleOutcome::Cancelled, $now);
                }

                return null;
            }

            $expiresAt = match ($request->change) {
                ExpiryChange::Set => Clock::utc($request->at ?? throw InvalidLifecycleUsageException::invalidRequest('expireAt() needs an instant')),
                ExpiryChange::Extend => Durations::add(
                    $pending->expires_at ?? throw ExpiryException::noPendingExpiry($state),
                    $request->interval ?? throw InvalidLifecycleUsageException::invalidRequest('extend() needs an interval'),
                ),
                ExpiryChange::Renew => Durations::add($now, $request->interval ?? $ttl->interval ?? throw ExpiryException::noTtl($state)),
            };

            $this->schedules->replace(
                $request->subject,
                $request->lifecycle,
                ScheduleBook::EXPIRY_SLOT,
                $this->schedules->expiryValues($ttl, $state, $expiresAt, $pending?->created_by_transition_id, true, $actor),
                $now,
            );

            return $expiresAt;
        });
    }
}
