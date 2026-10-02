<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Actions;

use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduledTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduleRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Engine\ContextFactory;
use RoundlyConsulting\Lifecycle\Engine\GuardPipeline;
use RoundlyConsulting\Lifecycle\Engine\LockedSubject;
use RoundlyConsulting\Lifecycle\Engine\Mode;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Support\ActorResolver;
use RoundlyConsulting\Lifecycle\Support\Clock;

/**
 * Schedules a transition to run at an instant, as the system, if the subject is still in
 * today's state then (leaving the state cancels it). One pending schedule per transition:
 * scheduling again replaces it. Who may schedule is checked now; time limits, quotas and
 * rate limits when it runs.
 */
final readonly class ScheduleTransitionAction
{
    public function __construct(
        private LockedSubject $locked,
        private GuardPipeline $pipeline,
        private ContextFactory $contexts,
        private ScheduleBook $schedules,
        private ActorResolver $actors,
    ) {}

    public function execute(ScheduleRequest $request): ScheduledTransition
    {
        $actor = $this->actors->resolve($request->actor, $request->system);

        return $this->locked->run($request->subject, $request->lifecycle, function (LifecycleState $record, CompiledDefinition $definition) use ($request, $actor): ScheduledTransition {
            $subject = $request->subject;
            $current = $definition->key($subject->getRawOriginal($request->lifecycle));
            $transition = $this->pipeline->resolve($definition, $request->transition, null, $current);

            if ($transition instanceof Denial) {
                throw TransitionDeniedException::because(Decision::deny($transition));
            }

            $evaluation = $this->contexts->evaluation(
                $definition,
                new TransitionRequest($subject, $request->lifecycle, $transition->name, actor: $request->actor, system: $request->system, reason: $request->reason, payload: $request->payload),
                $transition,
                $current,
                Mode::Apply,
                $record,
                $actor,
            );

            $decision = $this->pipeline->evaluateSchedule($evaluation);

            if ($decision->denied()) {
                throw TransitionDeniedException::because($decision);
            }

            $context = array_filter(
                ['reason' => $request->reason, 'payload' => $evaluation->storedContext],
                static fn (mixed $value): bool => $value !== null && $value !== [],
            );

            $schedule = $this->schedules->replace($subject, $request->lifecycle, $transition->name, [
                'kind' => ScheduleKind::Transition,
                'transition' => $transition->name,
                'for_state' => $current,
                'status' => ScheduleStatus::Pending,
                'due_at' => Clock::utc($request->at),
                'scheduled_by_type' => $actor?->getMorphClass(),
                'scheduled_by_id' => $actor?->getKey(),
                'context' => $context === [] ? null : $context,
            ], Clock::now());

            return $schedule->toScheduled($definition);
        });
    }
}
