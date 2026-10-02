<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\RollbackPlanner;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;

/**
 * Whether a rollback would be allowed right now — the same checks without locks or writes.
 */
final readonly class CheckRollbackAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private StateRecords $records,
        private RollbackPlanner $planner,
        private ActorResolver $actors,
    ) {}

    public function execute(RollbackRequest $request): Decision
    {
        $subject = $request->subject;

        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }

        return $this->planner->plan(
            $subject,
            $request->lifecycle,
            $this->registry->of($subject, $request->lifecycle),
            $this->records->find($subject, $request->lifecycle),
            $request,
            $this->actors->resolve($request->actor, $request->system),
            lock: false,
        )->decision;
    }
}
