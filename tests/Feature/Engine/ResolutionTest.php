<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\AmbiguousTransitionException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('resolves the one transition to a target state', function (): void {
    $listing = Listing::factory()->create();

    $result = $listing->transitionTo(ListingStatus::Active);

    expect($result->transition)->toBe('publish')
        ->and($listing->canTransitionTo('closed'))->toBeTrue()
        ->and(Lifecycles::for($listing)->checkTransitionTo(ListingStatus::Draft)->codes())->toBe(['no_transition_to_state']);
});

it('refuses an ambiguous target and names the candidates', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('also_go')->from('a')->to('b'));

    try {
        Document::factory()->create()->transitionTo('b');
        $this->fail('expected an exception');
    } catch (AmbiguousTransitionException $exception) {
        expect($exception->names())->toBe(['go', 'also_go'])
            ->and($exception->getMessage())->toContain('Apply one of them by name');
    }
});

it('refuses an undeclared target state', function (): void {
    Listing::factory()->create()->transitionTo('gone');
})->throws(UnknownStateException::class);

it('refuses to leave a terminal state, by name or by target', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('archive');

    expect(fn () => $listing->transition('publish'))->toThrow(TransitionDeniedException::class, 'The current state "Archived" is final.')
        ->and(Lifecycles::for($listing)->checkTransitionTo(ListingStatus::Active)->codes())->toBe(['terminal_state'])
        ->and(Lifecycles::for($listing)->isTerminal())->toBeTrue();
});
