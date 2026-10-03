<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\GuardPipeline;
use RoundlyConsulting\Lifecycle\Engine\RollbackPlanner;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;
use RoundlyConsulting\Lifecycle\Support\SoftDeletion;

/**
 * Whether a rollback would be allowed right now — the same checks without locks or writes. A
 * soft-deleted subject is refused `subject_trashed` (where `rollback()` throws).
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

        $definition = $this->registry->of($subject, $request->lifecycle);

        if (SoftDeletion::isTrashed($subject)) {
            $raw = $subject->getAttributes()[$request->lifecycle] ?? null;

            return Decision::deny(GuardPipeline::trashed($definition, $raw === null ? null : $definition->key($raw), null));
        }

        return $this->planner->plan(
            $subject,
            $request->lifecycle,
            $definition,
            $this->records->find($subject, $request->lifecycle),
            $request,
            $this->actors->resolve($request->actor, $request->system),
            lock: false,
        )->decision;
    }
}
