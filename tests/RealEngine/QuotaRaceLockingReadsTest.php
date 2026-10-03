<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Support\Racer;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * With `transactions.mysql_read_committed` off, quota counts are locking reads at REPEATABLE
 * READ: a burst into one partition may deadlock (retried, then surfaced), but never admits
 * more than the quota. Asserts the invariant, not availability.
 */
it('never exceeds the quota with locking reads under concurrent entries', function (): void {
    config()->set('lifecycle.transactions.mysql_read_committed', false);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(5, 'user_id'));
    $documents = Document::factory()->count(8)->create(['user_id' => 42]);
    $files = [];
    $pids = [];

    foreach ($documents as $document) {
        $file = (string) tempnam(sys_get_temp_dir(), 'lifecycle-quota-');
        $pid = pcntl_fork();

        if ($pid === 0) {
            $outcome = 'error';

            try {
                config()->set('database.connections.racer', DriverMatrix::connectionConfig(DriverMatrix::driver()));
                DB::setDefaultConnection('racer');
                Document::on('racer')->findOrFail($document->id)->transition('go');
                $outcome = 'entered';
            } catch (TransitionDeniedException $exception) {
                $outcome = 'denied:'.implode(',', $exception->decision()->codes());
            } catch (Throwable $exception) {
                $outcome = 'error: '.$exception->getMessage();
            }

            file_put_contents($file, $outcome);
            posix_kill(posix_getpid(), SIGKILL);
        }

        $files[] = $file;
        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $outcomes = array_map(static fn (string $file): string => (string) file_get_contents($file), $files);
    array_map(unlink(...), $files);
    $entered = count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'entered'));
    $unexpected = array_filter($outcomes, static fn (string $outcome): bool => $outcome !== 'entered'
        && $outcome !== 'denied:quota_exceeded'
        && preg_match('/deadlock/i', $outcome) !== 1);

    expect($outcomes)->toHaveCount(8)
        ->and($unexpected)->toBe([])
        ->and($entered)->toBeLessThanOrEqual(5)
        ->and(Document::query()->where('status', 'b')->count())->toBe($entered);
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql' || ! Racer::available(), 'mysql and pcntl only')->group('mysql');
