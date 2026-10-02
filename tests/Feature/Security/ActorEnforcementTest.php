<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

/**
 * Fleet theme 1: actor rules apply on every path, not only the one a UI happens to use.
 */
beforeEach(function (): void {
    Gate::define('approve', fn (User $user): bool => $user->admin === true);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->ability('approve')));
});

it('enforces the ability on every path', function (Closure $call): void {
    $document = Document::factory()->create();
    $member = User::factory()->create();

    expect(fn () => $call($document, $member))->toThrow(TransitionDeniedException::class, 'not authorized');
})->with([
    'apply' => [fn (Document $d, User $u) => Lifecycles::for($d)->by($u)->apply('go')],
    'transitionTo' => [fn (Document $d, User $u) => Lifecycles::for($d)->by($u)->transitionTo('b')],
    'trait' => [function (Document $d, User $u): void {
        test()->actingAs($u);
        $d->transition('go');
    }],
    'attempt' => [function (Document $d, User $u): void {
        $attempt = Lifecycles::for($d)->by($u)->attempt('go');

        throw new TransitionDeniedException($attempt->decision->first()?->message ?? '');
    }],
]);

it('lets an authorized actor through', function (): void {
    $document = Document::factory()->create();

    expect(Lifecycles::for($document)->by(User::factory()->create(['admin' => true]))->apply('go')->to)->toBe('b');
});
