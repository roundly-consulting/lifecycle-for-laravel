<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

/**
 * Without rollback rules, undoing a transition needs what applying it needed; with them,
 * only those.
 */
beforeEach(function (): void {
    Gate::define('publish', fn (User $user): bool => $user->admin === true);
    Gate::define('undo', fn (User $user): bool => $user->name === 'editor');
});

it('enforces the reverted transition own actor rules by default', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b'])->initial('a')->terminal('b');
        $l->transition('publish')->from('a')->to('b')->ability('publish');
    });
    $document = Document::factory()->create();
    Lifecycles::for($document)->by(User::factory()->create(['admin' => true]))->apply('publish');

    expect(Lifecycles::for($document)->by(User::factory()->create())->canRollback()->codes())->toBe(['unauthorized'])
        ->and(Lifecycles::for($document)->canRollback()->codes())->toBe(['actor_required'])
        ->and(Lifecycles::for($document)->asSystem()->canRollback()->allowed)->toBeTrue()
        ->and(Lifecycles::for($document)->by(User::factory()->create(['admin' => true]))->canRollback()->allowed)->toBeTrue();
});

it('enforces only the rollback rules when declared', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b'])->initial('a')->terminal('b');
        $l->transition('publish')->from('a')->to('b')->ability('publish')->rollbackRequires('undo')
            ->rollbackGuard(fn (TransitionContext $c): bool => $c->reason !== 'no');
    });
    $document = Document::factory()->create();
    Lifecycles::for($document)->by(User::factory()->create(['admin' => true]))->apply('publish');
    $editor = User::factory()->create(['name' => 'editor']);

    expect(Lifecycles::for($document)->by($editor)->canRollback()->allowed)->toBeTrue()
        ->and(Lifecycles::for($document)->by($editor)->because('no')->canRollback()->codes())->toBe(['guard_failed'])
        ->and(Lifecycles::for($document)->by(User::factory()->create(['admin' => true]))->canRollback()->codes())->toBe(['unauthorized'])
        ->and(Lifecycles::for($document)->canRollback()->codes())->toBe(['actor_required']);
});

it('lets a rollback guard alone decide', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b'])->initial('a')->terminal('b');
        $l->transition('publish')->from('a')->to('b')->rollbackGuard(fn (): bool => false);
    });
    $document = Document::factory()->create();
    $document->transition('publish');

    expect(Lifecycles::for($document)->canRollback()->codes())->toBe(['guard_failed']);
});
