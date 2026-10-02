<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

/**
 * Every package transaction: on the subject's connection, retried after a deadlock up to
 * `transaction_attempts` times — only when the package opened the outermost transaction (a
 * nested level cannot be retried; the host's transaction is lost).
 *
 * On MySQL/MariaDB the package's own transactions run at READ COMMITTED, the isolation the
 * engine is designed for (pgsql's default): every statement reads a fresh snapshot, so the
 * quota count after the mutex sees the previous holder's commit without a locking read —
 * which, on a table the optimizer scans, would lock the rows of entrants still waiting for
 * the mutex and deadlock with them. Inside a host transaction the host's isolation applies.
 *
 * @internal
 */
final class Transactions
{
    use DetectsConcurrencyErrors;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Model $subject, Closure $callback): mixed
    {
        $connection = $subject->getConnection();
        $attempts = self::attempts();

        for ($attempt = 1; ; $attempt++) {
            $outermost = $connection->transactionLevel() === 0;

            if ($outermost && self::readsCommitted($connection)) {
                $connection->statement('set transaction isolation level read committed');
            }

            try {
                return $connection->transaction($callback);
            } catch (Throwable $exception) {
                if (! $outermost || $attempt >= $attempts || ! (new self)->causedByConcurrencyError($exception)) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @return int<1, max>
     */
    public static function attempts(): int
    {
        return max(1, Config::using(InvalidLifecycleConfigurationException::class)
            ->intBetween('lifecycle.transaction_attempts', 1, 10, 3));
    }

    /**
     * MySQL/MariaDB: the package switches its own transactions to READ COMMITTED.
     */
    public static function readsCommitted(Connection $connection): bool
    {
        $driver = DatabaseDriver::tryFrom($connection->getDriverName());

        return $driver === DatabaseDriver::Mysql || $driver === DatabaseDriver::Mariadb;
    }
}
