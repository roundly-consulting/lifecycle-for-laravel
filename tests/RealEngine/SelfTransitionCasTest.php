<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * MySQL counts changed rows, not matched rows: the second identical stamp write in one
 * second changes nothing and reports 0. A self-transition must not read that as a lost race.
 */
it('applies a stamped self-transition twice in one second on mysql', function (): void {
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
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'mysql only')->group('mysql');
