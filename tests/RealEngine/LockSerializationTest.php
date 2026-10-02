<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Deterministic: while a transition's transaction is open, a second session cannot lock the
 * subject row (it times out instead of reading around the lock).
 */
it('holds the subject row lock until the transition commits', function (): void {
    $listing = Listing::factory()->create();
    config()->set('database.connections.second', DriverMatrix::connectionConfig(DriverMatrix::driver()));
    $second = DB::connection('second');

    if (DriverMatrix::driver() === 'pgsql') {
        $second->statement("set lock_timeout = '400ms'");
    } else {
        $second->statement('set session innodb_lock_wait_timeout = 1');
    }

    $blocked = false;

    DB::transaction(function () use ($listing, $second, &$blocked): void {
        $listing->transition('publish');

        try {
            $second->transaction(fn () => $second->table('listings')->where('id', $listing->id)->lockForUpdate()->first());
        } catch (QueryException) {
            $blocked = true;
        }
    });

    $after = $second->transaction(fn () => $second->table('listings')->where('id', $listing->id)->lockForUpdate()->value('status'));
    DB::purge('second');

    expect($blocked)->toBeTrue()
        ->and($after)->toBe('active');
})->skip(fn (): bool => ! in_array(DriverMatrix::driver(), ['pgsql', 'mysql'], true), 'needs a real engine')->group('real-engine');
