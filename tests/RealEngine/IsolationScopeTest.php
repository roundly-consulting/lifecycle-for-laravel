<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Only a transaction that counts a quota switches MySQL to READ COMMITTED: creation, a
 * transition into a state without quotas, freezes, schedules, expiry changes, adoption and
 * delete/restore run at the host's isolation. Pins the `countsQuotas` argument of the three
 * callers that pass one (apply, rollback, the sweep).
 */
function isolationScopeLifecycle(): void
{
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b', 'c', 'd'])->initial('a')->terminal('d');
        $l->state('b')->quota(5, 'user_id');
        $l->state('c')->ttl('1 day')->expiresVia('back');
        $l->transition('go')->from('a')->to('b')->allowSystem();
        $l->transition('plain')->from('a')->to('c')->allowSystem();
        $l->transition('back')->from('c')->to('a')->allowSystem();
        $l->transition('end')->from('*')->to('d');
    });
}

/**
 * How many `set transaction isolation level` statements a flow sends.
 */
function isolationSwitches(Closure $flow): int
{
    $switches = 0;
    $listening = true;

    DB::listen(function (QueryExecuted $query) use (&$switches, &$listening): void {
        if ($listening && str_contains(strtolower($query->sql), 'set transaction isolation level')) {
            $switches++;
        }
    });

    try {
        $flow();
    } finally {
        $listening = false;
    }

    return $switches;
}

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC'));
    isolationScopeLifecycle();
});

afterEach(fn () => Carbon::setTestNow());

it('keeps the host isolation for every flow that counts no quota', function (string $flow, Closure $run): void {
    expect(isolationSwitches($run))->toBe(0, $flow);
})->with([
    'create' => ['create', fn () => Document::factory()->create(['user_id' => 1])],
    'transition into a state without quotas' => ['plain', function (): void {
        $document = Document::factory()->create(['user_id' => 1]);
        $document->transition('plain');
    }],
    'freeze' => ['freeze', function (): void {
        $document = Document::factory()->create(['user_id' => 1]);
        Lifecycles::for($document)->freeze();
    }],
    'schedule' => ['schedule', function (): void {
        $document = Document::factory()->create(['user_id' => 1]);
        Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::now()->addHour());
    }],
    'renew' => ['renew', function (): void {
        $document = Document::factory()->create(['user_id' => 1, 'status' => 'c']);
        Lifecycles::for($document)->renew();
    }],
    'adopt' => ['adopt', function (): void {
        $document = Document::factory()->create(['user_id' => 1]);
        Document::query()->whereKey($document->id)->update(['status' => 'c']);
        Lifecycles::for($document->fresh() ?? $document)->adopt();
    }],
    'soft delete and restore' => ['delete', function (): void {
        $document = Document::factory()->create(['user_id' => 1]);
        $document->delete();
        $document->restore();
    }],
])->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'mysql only')->group('mysql');

it('switches once for a transition into a quota\'d state', function (): void {
    $document = Document::factory()->create(['user_id' => 1]);

    expect(isolationSwitches(fn () => $document->transition('go')))->toBe(1);
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'mysql only')->group('mysql');

it('switches once for a rollback on a definition with quotas', function (): void {
    $document = Document::factory()->create(['user_id' => 1]);
    $document->transition('plain');

    expect(isolationSwitches(fn () => Lifecycles::for($document)->rollback()))->toBe(1);
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'mysql only')->group('mysql');

it('switches once for the sweep of a scheduled transition into a quota\'d state', function (): void {
    $document = Document::factory()->create(['user_id' => 1]);
    Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::now()->addMinutes(30));
    Carbon::setTestNow(CarbonImmutable::now()->addHour());

    expect(isolationSwitches(fn () => Lifecycles::sweep()->executed))->toBe(1)
        ->and($document->fresh()?->status)->toBe('b');
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'mysql only')->group('mysql');
