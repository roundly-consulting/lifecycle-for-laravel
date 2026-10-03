<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * A host whose binary log uses `binlog_format=STATEMENT` cannot write at READ COMMITTED. Only
 * a quota'd transition switches, so creation and every other transition keep working; the
 * quota'd one fails with an error that names the fix, and the opt-out makes it work.
 */
it('fails only quota-counting transactions on a statement binlog, with a clear error and an opt-out', function (): void {
    $connection = DB::connection();

    if ((int) $connection->selectOne('select @@log_bin as log_bin')->log_bin === 0) {
        $this->markTestSkipped('binary logging is off on this server');
    }

    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a', 'b', 'c'])->initial('a');
        $l->state('b')->quota(1, 'user_id');
        $l->transition('go')->from('a')->to('b');
        $l->transition('side')->from('a')->to('c');
    });
    $format = $connection->selectOne('select @@session.binlog_format as format')->format;
    $connection->statement("set session binlog_format = 'STATEMENT'");

    try {
        [$first, $second, $third] = Document::factory()->count(3)->create(['user_id' => 7])->all();
        $third->transition('side');

        try {
            $first->transition('go');
            $failure = null;
        } catch (InvalidLifecycleConfigurationException $exception) {
            $failure = $exception;
        }

        expect($third->status)->toBe('c')
            ->and($failure)->not->toBeNull()
            ->and($failure?->getMessage())->toContain('lifecycle.transactions.mysql_read_committed')
            ->and($failure?->getPrevious())->toBeInstanceOf(QueryException::class)
            ->and($first->status)->toBe('a');

        config()->set('lifecycle.transactions.mysql_read_committed', false);
        $first->transition('go');

        expect($first->status)->toBe('b')
            ->and(fn () => $second->transition('go'))->toThrow(TransitionDeniedException::class, 'The limit of 1');
    } finally {
        $connection->statement("set session binlog_format = '{$format}'");
    }
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'mysql only')->group('mysql');
