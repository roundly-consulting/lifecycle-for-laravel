<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

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
