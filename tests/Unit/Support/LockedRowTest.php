<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;
use RoundlyConsulting\Lifecycle\Support\LockedRow;

$key = ['subject_type' => 'listing', 'lifecycle' => 'status', 'quota' => 'q', 'scope_key' => '[1]'];

it('inserts a missing row once and locks the existing one afterwards', function () use ($key): void {
    $first = LockedRow::firstOrInsert(QuotaLock::query(), $key);
    $inserts = 0;
    DB::listen(function ($query) use (&$inserts): void {
        $inserts += str_starts_with(strtolower($query->sql), 'insert') ? 1 : 0;
    });

    $second = LockedRow::firstOrInsert(QuotaLock::query(), $key);

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and($inserts)->toBe(0)
        ->and(QuotaLock::query()->count())->toBe(1);
});

it('creates a missing mutex row once and locks it', function () use ($key): void {
    $first = LockedRow::mutex(QuotaLock::query(), $key);
    $second = LockedRow::mutex(QuotaLock::query(), $key);

    expect($second->id)->toBe($first->id)
        ->and($first->created_at)->not->toBeNull()
        ->and(QuotaLock::query()->count())->toBe(1);
});

it('rethrows a unique violation when the winner cannot be read back', function () use ($key): void {
    QuotaLock::creating(function () use ($key): void {
        DB::table('lifecycle_quota_locks')->insert($key);
    });

    LockedRow::firstOrInsert(QuotaLock::query(), $key);
})->throws(UniqueConstraintViolationException::class);
