<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\UuidListing;

/**
 * uuid subjects with bigint actors: `key_type` and `actor_key_type` are separate settings.
 */
it('runs the lifecycle of a uuid-keyed subject with a bigint actor', function (): void {
    $subject = UuidListing::factory()->create();
    $actor = User::factory()->create();

    Lifecycles::for($subject)->by($actor)->apply('open');

    $row = $subject->lifecycleHistory()->latest('id')->firstOrFail();

    expect($subject->fresh()?->status)->toBe('open')
        ->and(LifecycleState::query()->sole()->subject_id)->toBe($subject->id)
        ->and($row->actor_id)->toEqual($actor->id)
        ->and(Lifecycles::for($subject)->version())->toBe(2);
});
