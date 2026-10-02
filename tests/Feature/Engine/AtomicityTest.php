<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\DataTransferObjects\StateHookContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * A throwing handler, hook or listener rolls everything back — state, history, record — and
 * hands the caller back the model exactly as it was.
 */
it('rolls back and restores the in-memory model when something throws', function (string $where): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($where): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->snapshots('price')->handledBy(function (TransitionContext $c) use ($where): void {
            $c->subject->setAttribute('price', '99.00');
            $c->subject->save();

            if ($where === 'handler') {
                throw new RuntimeException('handler failed');
            }
        }));

        $l->state('b')->onEnter(function (StateHookContext $c) use ($where): void {
            if ($where === 'hook') {
                throw new RuntimeException('hook failed');
            }
        });
    });

    if ($where === 'listener') {
        Event::listen(LifecycleTransitioning::class, fn () => throw new RuntimeException('listener failed'));
    }

    Event::fake([LifecycleTransitioned::class]);
    $document = Document::factory()->create(['price' => '10.00', 'title' => 'unsaved edit']);
    $document->title = 'dirty title';
    $before = [$document->getAttributes(), $document->getRawOriginal()];

    expect(fn () => $document->transition('go'))->toThrow(RuntimeException::class, $where.' failed');

    $fresh = $document->fresh();

    expect([$document->getAttributes(), $document->getRawOriginal()])->toBe($before)
        ->and($document->status)->toBe('a')
        ->and($fresh?->status)->toBe('a')
        ->and((float) $fresh?->price)->toBe(10.0)
        ->and(LifecycleTransition::query()->count())->toBe(1)
        ->and(LifecycleState::query()->sole()->version)->toBe(1);

    Event::assertNotDispatched(LifecycleTransitioned::class);
})->with(['handler', 'hook', 'listener']);
