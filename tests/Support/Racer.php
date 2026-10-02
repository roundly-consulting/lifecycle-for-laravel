<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RuntimeException;
use Throwable;

/**
 * A second session in a forked process, for real-engine races (credits precedent): the
 * child registers its own connection, never touches the parent's (closing it would kill the
 * parent's server session), writes its outcome to a file and ends with SIGKILL — no shutdown
 * handlers, no destructors.
 */
final class Racer
{
    private string $file;

    private int $pid = 0;

    public function __construct()
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'lifecycle-race-');
    }

    public static function available(): bool
    {
        return in_array(DriverMatrix::driver(), ['pgsql', 'mysql'], true)
            && function_exists('pcntl_fork') && function_exists('posix_kill');
    }

    /**
     * @param  Closure(): string  $operation  returns the outcome to report
     */
    public function run(Closure $operation): self
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            $this->pid = $pid;

            return $this;
        }

        $outcome = 'error';

        try {
            config()->set('database.connections.racer', DriverMatrix::connectionConfig(DriverMatrix::driver()));
            DB::setDefaultConnection('racer');
            $outcome = $operation();
        } catch (Throwable $exception) {
            $outcome = 'error: '.$exception::class.': '.$exception->getMessage();
        }

        file_put_contents($this->file, $outcome);
        posix_kill(posix_getpid(), SIGKILL);

        return $this;
    }

    /**
     * Until the racer waits on a lock, or has already finished because nothing blocked it.
     */
    public function waitUntilBlockedOrDone(): self
    {
        config()->set('database.connections.probe', DriverMatrix::connectionConfig(DriverMatrix::driver()));
        DB::purge('probe');
        $probe = DB::connection('probe');

        $sql = DriverMatrix::driver() === 'pgsql'
            ? "select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"
            : 'select count(*) as n from performance_schema.data_lock_waits';

        for ($i = 0; $i < 1200; $i++) {
            clearstatcache();

            if (filesize($this->file) > 0) {
                return $this;
            }

            if ((int) $probe->selectOne($sql)->n > 0) {
                return $this;
            }

            usleep(25_000);
        }

        throw new RuntimeException('The racer neither blocked nor finished.');
    }

    public function outcome(): string
    {
        pcntl_waitpid($this->pid, $status);
        $outcome = (string) file_get_contents($this->file);
        @unlink($this->file);
        DB::purge('probe');

        return $outcome;
    }
}
