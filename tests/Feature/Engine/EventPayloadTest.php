<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * Events carry ids and states, never the payload, and survive serialisation for queued
 * listeners.
 */
it('keeps the payload out of events and serialises them', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->rules(['card' => 'required|string'])->sensitive('card')));
    Event::fake([LifecycleTransitioned::class, LifecycleTransitioning::class]);
    $document = Document::factory()->create();

    Lifecycles::for($document)->with(['card' => '4242 4242 4242 4242'])->apply('go');

    foreach ([LifecycleTransitioned::class, LifecycleTransitioning::class] as $class) {
        Event::assertDispatched($class, function (object $event): bool {
            $serialized = serialize($event);
            $copy = unserialize($serialized);

            return ! str_contains($serialized, '4242') && $copy instanceof $event && $copy->lifecycle === 'status';
        });
    }
});
