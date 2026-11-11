<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Teams\Fixtures;

use RoundlyConsulting\Lifecycle\DataTransferObjects\StateHookContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Teams\Facades\Teams;

/**
 * new → open → resolved, which closes itself after 7 days (warned a day before); `escalate`
 * is scheduled and refused while `$escalations` is off. Entering `closed` / `escalated` records
 * the team the transition ran as and dispatches a job that records its own.
 */
final class TeamTicketLifecycle extends LifecycleDefinition
{
    public static bool $escalations = true;

    /** @var list<array{string, int, int|string|null}> what ran as which team: [what, ticket, team] */
    public static array $seen = [];

    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(['new', 'open', 'resolved', 'escalated', 'closed'])
            ->initial('new')
            ->terminal('closed');

        $lifecycle->state('resolved')
            ->ttl('7 days')
            ->warnBefore('1 day')
            ->expiresVia('close');

        foreach (['closed', 'escalated'] as $state) {
            $lifecycle->state($state)->onEnter(static function (StateHookContext $context) use ($state): void {
                $ticket = (int) $context->subject->getKey();
                self::$seen[] = [$state, $ticket, Teams::current()?->getKey()];
                RecordTeamJob::dispatch($state, $ticket);
            });
        }

        $lifecycle->transition('open')->from('new')->to('open');
        $lifecycle->transition('resolve')->from('open')->to('resolved');
        $lifecycle->transition('escalate')->from('open')->to('escalated')->allowSystem()
            ->when(static fn (): bool => self::$escalations, 'escalations_off');
        $lifecycle->transition('close')->from('resolved')->to('closed')->systemOnly();
    }
}
