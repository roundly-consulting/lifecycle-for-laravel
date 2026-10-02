<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * On MySQL the package's own transactions run at READ COMMITTED (a second plain read sees a
 * row another session committed in between); inside a host transaction the host's
 * REPEATABLE READ snapshot stays.
 */
it('runs its own transactions at read committed and leaves the host ones alone', function (): void {
    config()->set('database.connections.other', DriverMatrix::connectionConfig('mysql'));
    $seen = [];

    Event::listen(LifecycleTransitioning::class, function () use (&$seen): void {
        $before = DB::table('users')->count();
        DB::connection('other')->table('users')->insert(['name' => 'committed elsewhere']);
        $seen[] = DB::table('users')->count() - $before;
    });

    $listing = Listing::factory()->create();
    $listing->transition('publish');
    DB::transaction(function () use ($listing): void {
        DB::table('users')->count();
        $listing->transition('close');
    });
    DB::purge('other');

    expect($seen)->toBe([1, 0]);
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'mysql only')->group('mysql');
