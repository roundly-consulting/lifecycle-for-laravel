<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

it('lists the history newest first with reverted flags', function (): void {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->by($user)->because('go live')->apply('publish');
    Lifecycles::for($listing)->rollback();

    $history = Lifecycles::for($listing)->history();

    expect($history->map(fn ($r) => $r->kind)->all())->toBe([TransitionKind::Rollback, TransitionKind::Transition, TransitionKind::Initial])
        ->and($history[1]->reverted)->toBeTrue()
        ->and($history[1]->to)->toBe(ListingStatus::Active)
        ->and($history[1]->actorId)->toEqual($user->id)
        ->and($history[1]->reason)->toBe('go live')
        ->and($history[0]->revertsId)->toBe($history[1]->id)
        ->and(Lifecycles::for($listing)->history(1))->toHaveCount(1)
        ->and(Lifecycles::for($listing)->lastTransition()?->kind)->toBe(TransitionKind::Rollback)
        ->and(Lifecycles::for(new Listing)->history())->toHaveCount(0);
});
