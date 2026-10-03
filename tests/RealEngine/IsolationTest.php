<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * On MySQL a package transaction that counts a quota runs at READ COMMITTED (a second plain
 * read sees a row another session committed in between). A transition into a state without
 * quotas keeps the host's REPEATABLE READ, and so does every call inside a host transaction.
 */
it('reads committed only in its own quota-counting transactions', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(5, 'user_id'));
    config()->set('database.connections.other', DriverMatrix::connectionConfig('mysql'));
    $seen = [];

    Event::listen(LifecycleTransitioning::class, function () use (&$seen): void {
        $before = DB::table('users')->count();
        DB::connection('other')->table('users')->insert(['name' => 'committed elsewhere']);
        $seen[] = DB::table('users')->count() - $before;
    });

    [$first, $second] = Document::factory()->count(2)->create(['user_id' => 1])->all();
    $first->transition('go');
    $first->transition('finish');
    DB::transaction(function () use ($second): void {
        DB::table('users')->count();
        $second->transition('go');
    });
    DB::purge('other');

    expect($seen)->toBe([1, 0, 0]);
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'mysql only')->group('mysql');
