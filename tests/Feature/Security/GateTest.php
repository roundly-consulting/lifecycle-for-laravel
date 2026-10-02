<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * A missing actor is never "a guest the Gate allowed".
 */
it('requires an actor when an ability is declared, even if the gate would allow guests', function (): void {
    Gate::define('publish', fn (?object $user = null): bool => true);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->ability('publish')));

    expect(Lifecycles::for(Document::factory()->create())->check('go')->codes())->toBe(['actor_required']);
});
