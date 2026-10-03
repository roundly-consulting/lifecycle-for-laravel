<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Support\Transactions;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Runs from the `deleted` model event. A soft delete pauses the subject's pending
 * schedules; a force delete (or deleting a model without soft deletes) purges its records,
 * history and schedules unless `history.purge_on_force_delete` is off.
 *
 * @internal
 */
final readonly class SubjectDeletedAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private ScheduleBook $schedules,
    ) {}

    public function execute(Model $subject, bool $forced): void
    {
        if (! $forced) {
            // Soft-deleted: pending schedules wait until the subject is restored.
            Transactions::run($subject, fn () => $this->schedules->pause($subject));

            return;
        }

        if (! Config::using(InvalidLifecycleConfigurationException::class)->boolean('lifecycle.history.purge_on_force_delete', true)) {
            return;
        }

        Transactions::run($subject, function () use ($subject): void {
            foreach (array_keys($this->registry->definitionsOf($subject)) as $lifecycle) {
                // Query-builder deletes: history is append-only through Eloquent.
                TransitionModel::of($subject, (string) $lifecycle)->toBase()->delete();
                ScheduleModel::of($subject, (string) $lifecycle)->toBase()->delete();
                StateModel::of($subject, (string) $lifecycle)->toBase()->delete();
            }
        });
    }
}
