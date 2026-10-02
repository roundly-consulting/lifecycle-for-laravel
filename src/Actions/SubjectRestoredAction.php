<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Support\Transactions;

/**
 * Runs from the `restored` model event: the paused schedules of the subject are pending
 * again; overdue ones run at the next sweep.
 *
 * @internal
 */
final readonly class SubjectRestoredAction
{
    public function __construct(
        private ScheduleBook $schedules,
    ) {}

    public function execute(Model $subject): void
    {
        Transactions::run($subject, fn () => $this->schedules->resume($subject));
    }
}
