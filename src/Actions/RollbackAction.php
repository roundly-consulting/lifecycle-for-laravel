<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackResult;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\RestorePoint;
use RoundlyConsulting\Lifecycle\Engine\RollbackExecutor;
use RoundlyConsulting\Lifecycle\Engine\RollbackPlanner;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Engine\SubjectLocker;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;
use RoundlyConsulting\Lifecycle\Support\Transactions;
use Throwable;

/**
 * Undoes the last transition, or every transition after a history row — all or nothing:
 * every row is checked first, and any refusal throws RollbackDeniedException with all the
 * reasons. A rollback is never itself undone (redo is a new forward transition).
 */
final readonly class RollbackAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private SubjectLocker $locker,
        private StateRecords $records,
        private RollbackPlanner $planner,
        private RollbackExecutor $executor,
        private ActorResolver $actors,
    ) {}

    public function execute(RollbackRequest $request): RollbackResult
    {
        $subject = $request->subject;

        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }

        $definition = $this->registry->of($subject, $request->lifecycle);
        $actor = $this->actors->resolve($request->actor, $request->system);
        $restore = RestorePoint::capture($subject);

        try {
            $result = Transactions::run($subject, function () use ($subject, $request, $definition, $actor, $restore): RollbackResult {
                $restore->restore();
                $this->locker->lock($subject);
                $record = $this->records->lock($subject, $request->lifecycle, $definition)->record;

                $plan = $this->planner->plan($subject, $request->lifecycle, $definition, $record, $request, $actor, lock: true);

                if ($plan->decision->denied()) {
                    throw RollbackDeniedException::because($plan->decision);
                }

                return $this->executor->execute($plan, $subject, $request->lifecycle, $definition, $record, $request, $actor);
            });
        } catch (Throwable $exception) {
            $restore->restore();

            throw $exception;
        }

        RestorePoint::forgetRelations($subject);

        return $result;
    }
}
