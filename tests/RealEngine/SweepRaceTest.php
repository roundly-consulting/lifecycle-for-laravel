<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Support\Racer;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Two sweepers over the same due schedules: every schedule runs exactly once (the subject
 * lock, the pending-status re-read and the unique `schedule_id` on history).
 */
it('runs each due schedule once under concurrent sweepers', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));

    foreach (Document::factory()->count(100)->create() as $document) {
        Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::parse('2026-01-01', 'UTC'));
    }

    $pids = [];
    $files = [];

    foreach (range(1, 2) as $sweeper) {
        $file = (string) tempnam(sys_get_temp_dir(), 'lifecycle-sweep-');
        $pid = pcntl_fork();

        if ($pid === 0) {
            $outcome = 'error';

            try {
                config()->set('database.connections.racer', DriverMatrix::connectionConfig(DriverMatrix::driver()));
                DB::setDefaultConnection('racer');
                $outcome = (string) Lifecycles::sweep()->executed;
            } catch (Throwable $exception) {
                $outcome = 'error: '.$exception->getMessage();
            }

            file_put_contents($file, $outcome);
            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
        $files[] = $file;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $executed = array_map(static fn (string $file): string => (string) file_get_contents($file), $files);
    array_map(unlink(...), $files);

    expect(array_sum(array_map(intval(...), $executed)))->toBe(100)
        ->and(LifecycleTransition::query()->where('kind', 'scheduled')->count())->toBe(100)
        ->and(LifecycleTransition::query()->whereNotNull('schedule_id')->distinct()->count('schedule_id'))->toBe(100)
        ->and(Document::query()->where('status', 'b')->count())->toBe(100);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
