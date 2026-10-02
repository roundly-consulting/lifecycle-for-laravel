<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * `Artisan::call()` passes real ints, the console strings: both must be honoured.
 */
it('honours integer options given as ints or strings', function (int|string $limit): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));

    foreach (range(1, 3) as $i) {
        Lifecycles::for(Document::factory()->create())->asSystem()->schedule('go', CarbonImmutable::parse('2026-01-01', 'UTC'));
    }

    expect(Artisan::call('lifecycle:sweep', ['--limit' => $limit, '--no-warnings' => true]))->toBe(0)
        ->and(Artisan::output())->toMatch('/\|\s+0\s+\|\s+2\s+\|/')
        ->and(Document::query()->where('status', 'b')->count())->toBe(2);
})->with(['int' => 2, 'string' => '2']);

it('refuses an invalid integer option', function (mixed $limit): void {
    expect(Artisan::call('lifecycle:sweep', ['--limit' => $limit]))->toBe(1)
        ->and(Artisan::output())->toContain('--limit must be an integer of at least 1');
})->with(['zero' => 0, 'text' => 'many']);

it('sweeps with warnings and the queue flag', function (): void {
    expect(Artisan::call('lifecycle:sweep', ['--queue' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('warned');
});
