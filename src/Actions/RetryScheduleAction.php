<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Engine\SubjectLocker;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\SubjectResolver;
use RoundlyConsulting\Lifecycle\Support\Transactions;

/**
 * Puts a failed schedule back to pending with its attempts reset; it runs at the next
 * sweep. False when it is not failed, the subject is gone or has left the schedule's state,
 * or the slot is taken by another open schedule. Authorization is the host's.
 */
final readonly class RetryScheduleAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private SubjectLocker $locker,
        private StateRecords $records,
    ) {}

    public function execute(int $scheduleId): bool
    {
        $schedule = ScheduleModel::query()->find($scheduleId);
        $subject = $schedule === null ? null : SubjectResolver::find($schedule->subject_type, $schedule->subject_id);

        if ($subject === null) {
            return false;
        }

        return Transactions::run($subject, function () use ($subject, $scheduleId): bool {
            $this->locker->lock($subject);

            $schedule = ScheduleModel::queryFor($subject)->whereKey($scheduleId)->lockForUpdate()->first();

            if ($schedule === null || $schedule->status !== ScheduleStatus::Failed) {
                return false;
            }

            $record = $this->records->lock($subject, $schedule->lifecycle, $this->registry->of($subject, $schedule->lifecycle))->record;
            $slot = $schedule->kind === ScheduleKind::Expiry ? ScheduleBook::EXPIRY_SLOT : $schedule->transition;

            $taken = ScheduleModel::of($subject, $schedule->lifecycle)->where('pending_slot', $slot)->lockForUpdate()->exists();

            if ($record->state !== $schedule->for_state || $taken) {
                return false;
            }

            $schedule->forceFill([
                'status' => ScheduleStatus::Pending,
                'pending_slot' => $slot,
                'outcome' => null,
                'finished_at' => null,
                'attempts' => 0,
                'last_denial' => null,
            ])->save();

            return true;
        });
    }
}
