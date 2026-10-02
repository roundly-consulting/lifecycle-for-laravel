<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions;

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * Plain strings, with labels, a self-transition and a wildcard minus exclusions.
 */
final class TicketLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->label('Support ticket')->meta(['team' => 'support']);

        $lifecycle->states(['new', 'open', 'waiting', 'resolved', 'closed'])
            ->initial('new')
            ->terminal('closed');

        $lifecycle->state('waiting')->label('Waiting on "customer"');
        $lifecycle->state('resolved')->label(static fn (): string => 'Resolved!');

        $lifecycle->transition('open')->from('new')->to('open')->label('Open ticket');
        $lifecycle->transition('wait')->from('open')->to('waiting');
        $lifecycle->transition('nudge')->from('waiting')->to('waiting')->allowSelf();
        $lifecycle->transition('resolve')->from('open', 'waiting')->to('resolved');
        $lifecycle->transition('reopen')->from('resolved')->to('open');
        $lifecycle->transition('close')->fromAnyExcept('new')->to('closed')->systemOnly();
    }
}
