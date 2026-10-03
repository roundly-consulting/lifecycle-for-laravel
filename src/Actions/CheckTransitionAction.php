<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Engine\ContextFactory;
use RoundlyConsulting\Lifecycle\Engine\GuardPipeline;
use RoundlyConsulting\Lifecycle\Engine\Mode;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;
use RoundlyConsulting\Lifecycle\Support\SoftDeletion;

/**
 * Whether a transition would be allowed right now — the same pipeline as `apply()`, without
 * locks or side effects. Advisory: `apply()` checks again under the lock. A soft-deleted subject
 * is refused `subject_trashed` (where `apply()` throws SubjectTrashedException).
 */
final readonly class CheckTransitionAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private GuardPipeline $pipeline,
        private ContextFactory $contexts,
        private StateRecords $records,
        private ActorResolver $actors,
    ) {}

    public function execute(TransitionRequest $request): Decision
    {
        $subject = $request->subject;

        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }

        $definition = $this->registry->of($subject, $request->lifecycle);
        $raw = $subject->getAttributes()[$request->lifecycle] ?? null;

        if ($raw === null) {
            throw UnknownStateException::notInitialized($subject, $request->lifecycle);
        }

        $current = $definition->key($raw);

        if (SoftDeletion::isTrashed($subject)) {
            return Decision::deny(GuardPipeline::trashed($definition, $current, $request->transition));
        }

        $transition = $this->pipeline->resolve($definition, $request->transition, $request->target, $current);

        if ($transition instanceof Denial) {
            return Decision::deny($transition);
        }

        return $this->pipeline->evaluate($this->contexts->evaluation(
            $definition,
            $request,
            $transition,
            $current,
            Mode::Check,
            $this->records->find($subject, $request->lifecycle),
            $this->actors->resolve($request->actor, $request->system),
        ));
    }
}
