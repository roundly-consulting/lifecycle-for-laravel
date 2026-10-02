<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\PlainModel;

/**
 * No SoftDeletes, no timestamps: the trait boots (no `restored()` static call), transitions
 * write only the state, and deleting purges.
 */
it('boots, saves and transitions a model without soft deletes or timestamps', function (): void {
    $model = PlainModel::factory()->create();

    $model->transition('authorize');

    expect($model->fresh()?->status)->toBe('authorized')
        ->and(Lifecycles::for($model)->version())->toBe(2);

    $model->delete();

    expect(LifecycleState::query()->count())->toBe(0);
});
