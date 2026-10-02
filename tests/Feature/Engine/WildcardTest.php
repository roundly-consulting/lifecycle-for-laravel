<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Ticket;

it('applies a wildcard transition from every non-terminal state', function (string $setup): void {
    $listing = Listing::factory()->create();

    if ($setup !== 'draft') {
        $listing->transition('publish');
    }

    if ($setup === 'closed') {
        $listing->transition('close');
    }

    expect($listing->transition('archive')->transition)->toBe('archive')
        ->and(Lifecycles::for($listing)->is('archived'))->toBeTrue();
})->with(['draft', 'active', 'closed']);

it('excludes the target and listed states from a wildcard', function (): void {
    $ticket = Ticket::factory()->create();

    expect(Lifecycles::for($ticket)->asSystem()->check('close')->codes())->toBe(['not_from_current_state']);

    $ticket->transition('open');

    expect(Lifecycles::for($ticket)->asSystem()->apply('close')->to)->toBe('closed');
});

it('allows an explicit self-transition', function (): void {
    $ticket = Ticket::factory()->create();
    $ticket->transition('open');
    $ticket->transition('wait');

    $result = $ticket->transition('nudge');

    expect($result->from)->toBe('waiting')
        ->and($result->to)->toBe('waiting')
        ->and(Lifecycles::for($ticket)->state())->toBe('waiting')
        ->and(Lifecycles::for($ticket)->allowedStates())->toBe(['waiting', 'resolved']);
});
