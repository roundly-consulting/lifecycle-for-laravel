<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\CancelScheduleRequest;
use RoundlyConsulting\Lifecycle\Engine\LockedSubject;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Cancels this subject's pending schedule of a transition; false when there is none.
 * Scoped to the subject — another subject's schedule is never touched. Authorization is the
 * host's.
 */
final readonly class CancelScheduledTransitionAction
{
    public function __construct(
        private LockedSubject $locked,
        private ScheduleBook $schedules,
    ) {}

    public function execute(CancelScheduleRequest $request): bool
    {
        return $this->locked->run($request->subject, $request->lifecycle, function () use ($request): bool {
            $open = $this->schedules->open($request->subject, $request->lifecycle, $request->transition);

            if ($open === null) {
                return false;
            }

            $this->schedules->finish($open, ScheduleStatus::Cancelled, ScheduleOutcome::Cancelled, Clock::now());

            return true;
        });
    }
}
