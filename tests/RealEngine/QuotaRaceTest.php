<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Support\Racer;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Eight sessions enter a quota of five for the same user at once: exactly five make it.
 */
it('admits exactly the quota under concurrent entries', function (): void {
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
    sort($outcomes);

    expect(array_count_values($outcomes))->toBe(['denied:quota_exceeded' => 3, 'entered' => 5])
        ->and(Document::query()->where('status', 'b')->count())->toBe(5);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
