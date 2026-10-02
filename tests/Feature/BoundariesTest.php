<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

/**
 * A limit `t` is reached when now ≥ t — except `notAfter`, whose deadline itself is still
 * allowed.
 */
afterEach(fn () => Carbon::setTestNow());

it('allows notBefore exactly at the instant and notAfter exactly at the deadline', function (string $now, bool $allowed): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->notBefore(fn () => CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'))
        ->notAfter(fn () => CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'))));
    $document = Document::factory()->create();

    Carbon::setTestNow(CarbonImmutable::parse($now, 'UTC'));

    expect(Lifecycles::for($document)->can('go'))->toBe($allowed);
})->with([
    'a second early' => ['2026-10-02 09:59:59', false],
    'at notBefore' => ['2026-10-02 10:00:00', true],
    'at notAfter' => ['2026-10-02 12:00:00', true],
    'a second late' => ['2026-10-02 12:00:01', false],
]);

it('ends a minimum dwell and a cooldown exactly at the limit', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l);
        $l->transition('back')->from('b')->to('a');
        $l->transition('undo')->from('b')->to('a')->cooldown('30 minutes')->ignoresMinDwell();
        $l->state('b')->minDwell('1 hour');
    });
    $document = Document::factory()->create();
    $document->transition('go');

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:59:59', 'UTC'));
    expect(Lifecycles::for($document)->check('back')->codes())->toBe(['min_dwell_not_reached']);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:00:00', 'UTC'));
    expect(Lifecycles::for($document)->can('back'))->toBeTrue();

    $document->transition('undo');
    $document->transition('go');

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:29:59', 'UTC'));
    expect(Lifecycles::for($document)->check('undo')->codes())->toBe(['cooldown_active']);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:30:00', 'UTC'));
    expect(Lifecycles::for($document)->can('undo'))->toBeTrue();
});

it('expires exactly at the expiry instant', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 09:59:59', 'UTC'));
    expect(Lifecycles::for($listing)->isExpired())->toBeFalse();

    Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'));
    expect(Lifecycles::for($listing)->isExpired())->toBeTrue();
});
