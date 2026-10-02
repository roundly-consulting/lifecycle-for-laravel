<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/**
 * The global lock order (§3.7.2): the subject row first, then the subject's package rows.
 * SQLite compiles `FOR UPDATE` to nothing, so the recording grammar marks each lock and the
 * table names are read from the recorded SQL, in order.
 */
function recordLocks(Closure $flow): array
{
    $connection = DB::connection();
    $connection->setQueryGrammar(new LockRecordingGrammar($connection));
    LockRecorder::flush();
    LockRecorder::listenForMarkers();

    $flow();

    return array_map(function (array $lock): array {
        preg_match('/from\s+"?([a-z_]+)"?/i', $lock['sql'], $table);

        return [$table[1] ?? '?', $lock['transactionDepth']];
    }, LockRecorder::recorded());
}

it('locks the subject before its package rows when applying', function (): void {
    $listing = Listing::factory()->create();

    $locks = recordLocks(fn () => $listing->lifecycle()->idempotencyKey('k')->apply('publish'));

    expect($locks[0])->toBe(['listings', 1])
        ->and($locks[1])->toBe(['lifecycle_states', 1])
        ->and(subjectRowsOnly(array_slice($locks, 2)))->toBeTrue()
        ->and(array_column($locks, 0))->toContain('lifecycle_transitions', 'lifecycle_schedules');
})->group('sqlite');

/**
 * Per-subject package rows only (record, schedules, history) — their relative order under the
 * subject lock cannot form a cycle — and all inside the transaction.
 *
 * @param  list<array{0: string, 1: int}>  $locks
 */
function subjectRowsOnly(array $locks): bool
{
    foreach ($locks as [$table, $depth]) {
        if (! in_array($table, ['lifecycle_states', 'lifecycle_schedules', 'lifecycle_transitions'], true) || $depth < 1) {
            return false;
        }
    }

    return true;
}

it('locks the subject before its record when freezing', function (): void {
    $listing = Listing::factory()->create();

    $locks = recordLocks(fn () => $listing->lifecycle()->freeze());

    expect($locks)->toBe([
        ['listings', 1],
        ['lifecycle_states', 1],
    ]);
})->group('sqlite');

it('takes the quota mutex after the subject and its record', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(3, 'user_id'));
    $document = Document::factory()->create(['user_id' => 1]);

    $locks = recordLocks(fn () => $document->transition('go'));

    // Missing mutex rows are locked twice (probe, then after the insert); schedule rows follow.
    $quota = array_keys(array_filter($locks, fn (array $lock): bool => $lock[0] === 'lifecycle_quota_locks'));

    expect(array_slice($locks, 0, 2))->toBe([['documents', 1], ['lifecycle_states', 1]])
        ->and($quota)->toBe([2, 3])
        ->and(subjectRowsOnly(array_slice($locks, 4)))->toBeTrue();
})->group('sqlite');

it('locks the subject first when a sweep runs a schedule', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));
    $document = Document::factory()->create();
    Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::parse('2026-01-01', 'UTC'));

    $locks = recordLocks(fn () => Lifecycles::sweep());

    expect($locks[0])->toBe(['documents', 1])
        ->and($locks[1])->toBe(['lifecycle_states', 1])
        ->and($locks[2])->toBe(['lifecycle_schedules', 1])
        ->and(subjectRowsOnly(array_slice($locks, 1)))->toBeTrue();
})->group('sqlite');
