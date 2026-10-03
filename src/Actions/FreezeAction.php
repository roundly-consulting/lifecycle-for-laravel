<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Lifecycle\DataTransferObjects\FreezeRequest;
use RoundlyConsulting\Lifecycle\Engine\GuardPipeline;
use RoundlyConsulting\Lifecycle\Engine\LockedSubject;
use RoundlyConsulting\Lifecycle\Events\LifecycleFrozen;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Freezes a lifecycle: every transition is refused `frozen` (unless it `ignoresFreeze()`),
 * scheduled ones are deferred. A reason longer than `history.reason_max_length` is a usage error. Freezing a frozen lifecycle updates its end and reason; the
 * event fires only on a change. Returns whether anything changed. Authorization is the
 * host's — the actor is recorded for audit only.
 */
final readonly class FreezeAction
{
    public function __construct(
        private LockedSubject $locked,
        private Dispatcher $events,
        private ActorResolver $actors,
    ) {}

    public function execute(FreezeRequest $request): bool
    {
        if ($request->reason !== null && mb_strlen($request->reason) > GuardPipeline::reasonMaxLength()) {
            throw InvalidLifecycleUsageException::invalidRequest('a freeze reason has at most '.GuardPipeline::reasonMaxLength().' characters (history.reason_max_length)');
        }

        $actor = $this->actors->resolve($request->actor, false);

        return $this->locked->run($request->subject, $request->lifecycle, function (LifecycleState $record) use ($request, $actor): bool {
            $now = Clock::now();
            $until = $request->until === null ? null : Clock::utc($request->until);
            $frozen = $record->isFrozen($now);

            $sameEnd = $record->frozen_until === null ? $until === null : $until !== null && $record->frozen_until->equalTo($until);

            if ($frozen && $sameEnd && $record->frozen_reason === $request->reason) {
                return false;
            }

            $record->forceFill([
                'frozen_at' => $frozen ? $record->frozen_at : $now,
                'frozen_until' => $until,
                'frozen_reason' => $request->reason,
                'frozen_by_type' => $actor?->getMorphClass(),
                'frozen_by_id' => $actor?->getKey(),
            ])->save();

            $this->events->dispatch(new LifecycleFrozen($request->subject, $request->lifecycle, $until, $request->reason, $actor));

            return true;
        });
    }
}
