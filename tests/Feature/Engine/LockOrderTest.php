<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
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

    expect($locks)->toBe([
        ['listings', 1],
        ['lifecycle_states', 1],
        ['lifecycle_transitions', 1],
    ]);
})->group('sqlite');

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

    // Missing mutex rows are locked twice (probe, then after the insert): same table, same depth.
    expect(array_slice($locks, 0, 2))->toBe([['documents', 1], ['lifecycle_states', 1]])
        ->and(array_unique(array_map(fn (array $lock): string => $lock[0].'@'.$lock[1], array_slice($locks, 2))))->toBe(['lifecycle_quota_locks@1']);
})->group('sqlite');
