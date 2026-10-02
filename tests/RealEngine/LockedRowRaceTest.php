<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;
use RoundlyConsulting\Lifecycle\Support\LockedRow;
use RoundlyConsulting\Lifecycle\Tests\Support\Racer;

/**
 * Two sessions insert the same key for the first time: one row, and the loser — after the
 * unique violation inside its savepoint — reads the winner's row with a locking read.
 */
it('settles a concurrent first insert on one row', function (): void {
    $key = ['subject_type' => 'listing', 'lifecycle' => 'status', 'quota' => 'q', 'scope_key' => '[7]'];
    $racer = new Racer;
    $winner = null;

    DB::transaction(function () use ($key, $racer, &$winner): void {
        $winner = LockedRow::firstOrInsert(QuotaLock::query(), $key);

        $racer->run(function () use ($key): string {
            return DB::transaction(function () use ($key): string {
                $row = LockedRow::firstOrInsert(QuotaLock::on('racer'), $key);

                return ($row->wasRecentlyCreated ? 'inserted:' : 'found:').$row->id;
            });
        })->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('found:'.$winner?->id)
        ->and(QuotaLock::query()->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
