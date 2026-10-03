<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
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
 * The transitions leaving the current state, checked for the given actor — what a UI shows
 * as buttons. With `includeDenied` the refused ones come too, with their reasons. Input a
 * transition needs (a reason, payload fields) is reported, never treated as a denial.
 */
final readonly class ListAvailableTransitionsAction
{
    public function __construct(
        private DefinitionRegistry $registry,
        private GuardPipeline $pipeline,
        private ContextFactory $contexts,
        private StateRecords $records,
        private ActorResolver $actors,
    ) {}

    /**
     * @return list<AvailableTransition>
     */
    public function execute(AvailableTransitionsQuery $query): array
    {
        $subject = $query->subject;

        if (! $subject->exists) {
            throw SubjectNotPersistedException::for($subject);
        }

        $definition = $this->registry->of($subject, $query->lifecycle);
        $raw = $subject->getAttributes()[$query->lifecycle] ?? null;

        if ($raw === null) {
            throw UnknownStateException::notInitialized($subject, $query->lifecycle);
        }

        $current = $definition->key($raw);
        $record = $this->records->find($subject, $query->lifecycle);
        $actor = $this->actors->resolve($query->actor, $query->system);
        $available = [];

        $trashed = SoftDeletion::isTrashed($subject);

        foreach ($definition->transitionsFrom($current) as $transition) {
            $request = new TransitionRequest($subject, $query->lifecycle, transition: $transition->name, actor: $query->actor, system: $query->system);
            $decision = $trashed
                ? Decision::deny(GuardPipeline::trashed($definition, $current, $transition->name))
                : $this->pipeline->evaluate($this->contexts->evaluation($definition, $request, $transition, $current, Mode::Check, $record, $actor));

            if ($decision->denied() && ! $query->includeDenied) {
                continue;
            }

            $available[] = new AvailableTransition(
                name: $transition->name,
                label: $transition->label(),
                to: $definition->value($transition->to),
                toLabel: $definition->stateLabel($transition->to),
                allowed: $decision->allowed,
                denials: $decision->denials,
                requiresReason: $transition->requiresReason && ! $query->system,
                payloadFields: $transition->payloadFields(),
                availableAt: $decision->retryAfter,
                meta: $transition->meta,
            );
        }

        return $available;
    }
}
