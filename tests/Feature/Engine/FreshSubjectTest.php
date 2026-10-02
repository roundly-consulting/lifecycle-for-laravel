<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * Under the lock the in-memory subject is refreshed from the stored row for every attribute
 * the caller has not dirtied, so guards decide on the stored truth.
 */
it('shows guards the locked row, not a stale model', function (): void {
    $seen = [];
    defineDocumentLifecycle(function (LifecycleBuilder $l) use (&$seen): void {
        baseLifecycle($l, go: function (TransitionBuilder $go) use (&$seen): void {
            $go->when(function (TransitionContext $c) use (&$seen): bool {
                $seen[] = $c->subject->getAttribute('title').'|'.$c->subject->getAttribute('quantity');

                return true;
            });
        });
    });

    $document = Document::factory()->create(['title' => 'old', 'quantity' => 1]);
    Document::query()->whereKey($document->id)->update(['title' => 'new']);
    $document->quantity = 5;

    $document->transition('go');

    expect($seen)->toBe(['new|5'])
        ->and($document->fresh()?->quantity)->toBe(5)
        ->and($document->fresh()?->title)->toBe('new');
});

it('refuses a dirty lifecycle attribute', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));
    $document = Document::factory()->create();
    $document->status = 'b';

    $document->transition('go');
})->throws(InvalidLifecycleUsageException::class, 'has unsaved changes');
