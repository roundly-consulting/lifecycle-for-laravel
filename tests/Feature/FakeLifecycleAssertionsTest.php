<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\ExpiryChange;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\DualDocument;

/**
 * A model with two lifecycles that share transition names: every fake assertion that takes a
 * subject can name the lifecycle, so a call on one lifecycle never satisfies an assertion about
 * the other.
 */
beforeEach(function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem(), finish: fn (TransitionBuilder $finish) => $finish->allowSystem());
        $l->state('b')->ttl('1 day')->expiresVia('finish');
    });
});

function dualFake(): array
{
    $fake = Lifecycles::fake();
    $document = DualDocument::query()->create();

    return [$fake, $document];
}

it('scopes the transition assertions to a lifecycle', function (): void {
    [, $document] = dualFake();

    Lifecycles::for($document, 'review_status')->apply('go');

    Lifecycles::assertTransitioned($document, 'go', lifecycle: 'review_status');
    Lifecycles::assertNotTransitioned($document, 'go', lifecycle: 'status');

    expect(fn () => Lifecycles::assertTransitioned($document, 'go', lifecycle: 'status'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Lifecycles::assertNotTransitioned($document, 'go', lifecycle: 'review_status'))->toThrow(ExpectationFailedException::class);
});

it('scopes the denial, rollback, schedule, cancel, expiry and adoption assertions to a lifecycle', function (): void {
    $fake = Lifecycles::fake();
    $document = DualDocument::query()->create();
    $fake->denyNext('go');
    rescue(fn () => Lifecycles::for($document, 'status')->apply('go'), report: false);
    Lifecycles::for($document, 'review_status')->apply('go');
    Lifecycles::for($document, 'review_status')->rollback();
    Lifecycles::for($document, 'review_status')->apply('go');
    Lifecycles::for($document, 'review_status')->asSystem()->schedule('finish', CarbonImmutable::now()->addDay());
    Lifecycles::for($document, 'review_status')->cancelScheduled('finish');
    Lifecycles::for($document, 'review_status')->renew();
    Lifecycles::adopt($document, 'review_status');

    Lifecycles::assertTransitionDenied($document, 'go', lifecycle: 'status');
    Lifecycles::assertRolledBack($document, lifecycle: 'review_status');
    Lifecycles::assertScheduled($document, 'finish', lifecycle: 'review_status');
    Lifecycles::assertScheduleCancelled($document, 'finish', lifecycle: 'review_status');
    Lifecycles::assertExpiryChanged($document, ExpiryChange::Renew, lifecycle: 'review_status');
    Lifecycles::assertAdopted($document, lifecycle: 'review_status');

    expect(fn () => Lifecycles::assertTransitionDenied($document, 'go', lifecycle: 'review_status'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Lifecycles::assertRolledBack($document, lifecycle: 'status'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Lifecycles::assertScheduled($document, 'finish', lifecycle: 'status'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Lifecycles::assertScheduleCancelled($document, 'finish', lifecycle: 'status'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Lifecycles::assertExpiryChanged($document, lifecycle: 'status'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Lifecycles::assertAdopted($document, lifecycle: 'status'))->toThrow(ExpectationFailedException::class);
});

it('matches an adoption of every row of a model by lifecycle', function (): void {
    Lifecycles::fake();

    Lifecycles::model(DualDocument::class, 'review_status')->adopt();

    Lifecycles::assertAdopted(lifecycle: 'review_status');

    expect(fn () => Lifecycles::assertAdopted(lifecycle: 'status'))->toThrow(ExpectationFailedException::class);
});
