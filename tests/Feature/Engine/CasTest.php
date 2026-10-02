<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Exceptions\ConcurrentTransitionException;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('throws when the compare-and-swap finds the state already changed', function (): void {
    $listing = Listing::factory()->create();

    Event::listen(LifecycleTransitioning::class, function (LifecycleTransitioning $event): void {
        // Simulates a writer that bypassed the lock.
        Listing::query()->whereKey($event->subject->getKey())->toBase()->update(['status' => 'closed']);
    });

    expect(fn () => $listing->transition('publish'))->toThrow(ConcurrentTransitionException::class, 'was changed concurrently')
        ->and($listing->status)->toBe(ListingStatus::Draft)
        ->and(LifecycleTransition::query()->count())->toBe(1);
});

it('applies a stamped self-transition twice in the same second', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('touch')->from('b')->to('b')->allowSelf();
        $l->state('b')->stamps('published_at');
    });
    $document = Document::factory()->create();
    $document->transition('go');

    $document->transition('touch');
    $document->transition('touch');

    expect(LifecycleTransition::query()->where('transition', 'touch')->count())->toBe(2);
    Carbon::setTestNow();
});
