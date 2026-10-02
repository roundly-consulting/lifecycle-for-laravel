<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
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
