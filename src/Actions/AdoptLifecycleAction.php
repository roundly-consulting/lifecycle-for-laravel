<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\RestorePoint;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Engine\SubjectLocker;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Support\Transactions;
use Throwable;

/**
 * Reconciles one subject with its stored state: creates a missing record, adopts a state
 * written outside the engine, initialises a NULL state. True when anything changed.
 * Authorization is the host's — nothing here checks an actor.
 */
final readonly class AdoptLifecycleAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private StateRecords $records,
        private SubjectLocker $locker,
    ) {}

    /**
     * `$allowTrashed`: adopt a soft-deleted subject too (its new schedules are paused) — what the
     * `saved` hook does for an allowed direct write on a trashed model.
     */
    public function execute(Model $subject, string $lifecycle, bool $scheduleExpiry = true, bool $allowTrashed = false): bool
    {
        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }

        $definition = $this->registry->of($subject, $lifecycle);
        $restore = RestorePoint::capture($subject);

        try {
            $changed = Transactions::run($subject, function () use ($subject, $lifecycle, $definition, $restore, $scheduleExpiry, $allowTrashed): bool {
                $restore->restore();
                $this->locker->lock($subject, $allowTrashed);

                return $this->records->lock($subject, $lifecycle, $definition, $scheduleExpiry)->changed;
            });
        } catch (Throwable $exception) {
            $restore->restore();

            throw $exception;
        }

        RestorePoint::forgetRelations($subject);

        return $changed;
    }
}
