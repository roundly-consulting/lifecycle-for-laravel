<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Lifecycle\DataTransferObjects\UnfreezeRequest;
use RoundlyConsulting\Lifecycle\Engine\LockedSubject;
use RoundlyConsulting\Lifecycle\Events\LifecycleUnfrozen;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Lifts a freeze; false when the lifecycle was not frozen (a lapsed freeze is cleared
 * silently). Overdue schedules run at the next sweep. Authorization is the host's.
 */
final readonly class UnfreezeAction
{
    public function __construct(
        private LockedSubject $locked,
        private Dispatcher $events,
        private ActorResolver $actors,
    ) {}

    public function execute(UnfreezeRequest $request): bool
    {
        $actor = $this->actors->resolve($request->actor, false);

        return $this->locked->run($request->subject, $request->lifecycle, function (LifecycleState $record) use ($request, $actor): bool {
            if ($record->frozen_at === null) {
                return false;
            }

            $frozen = $record->isFrozen(Clock::now());
            $until = $record->frozen_until;

            $record->forceFill([
                'frozen_at' => null,
                'frozen_until' => null,
                'frozen_reason' => null,
                'frozen_by_type' => null,
                'frozen_by_id' => null,
            ])->save();

            if ($frozen) {
                $this->events->dispatch(new LifecycleUnfrozen($request->subject, $request->lifecycle, $until, $request->reason, $actor));
            }

            return $frozen;
        });
    }
}
