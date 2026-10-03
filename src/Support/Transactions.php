<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

/**
 * Every package transaction: on the subject's connection, retried after a deadlock up to
 * `transactions.attempts` times — only when the package opened the outermost transaction (a
 * nested level cannot be retried; the host's transaction is lost).
 *
 * On MySQL/MariaDB a transaction that counts a quota (`$countsQuotas`) runs at READ
 * COMMITTED: the count after the quota mutex is then a plain statement with a fresh snapshot
 * that sees the previous holder's commit, as on pgsql. Under REPEATABLE READ the count would
 * have to be a locking read, which on a table the optimizer scans locks the rows of entrants
 * still waiting for the mutex and deadlocks with them. Every other package transaction keeps
 * the host's isolation; `transactions.mysql_read_committed = false` keeps it for quotas too
 * (the count then takes locks — see QuotaGate::countTakesLocks()).
 *
 * @internal
 */
final class Transactions
{
    use DetectsConcurrencyErrors;

    /**
     * MySQL error 1665: a write at READ COMMITTED refused because binary logging uses
     * `binlog_format=STATEMENT`.
     */
    private const int STATEMENT_BINLOG_ERROR = 1665;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Model $subject, Closure $callback, bool $countsQuotas = false): mixed
    {
        $connection = $subject->getConnection();
        $attempts = self::attempts();
        $enabled = Config::using(InvalidLifecycleConfigurationException::class)->boolean('lifecycle.transactions.mysql_read_committed', true);

        for ($attempt = 1; ; $attempt++) {
            $level = $connection->transactionLevel();
            $switch = self::shouldSwitch(DatabaseDriver::tryFrom($connection->getDriverName()), $level, $countsQuotas, $enabled);

            try {
                if (! $switch) {
                    return $connection->transaction($callback);
                }

                $connection->statement('set transaction isolation level read committed');

                return Container::getInstance()->make(Isolation::class)
                    ->run($connection->getName() ?? '', static fn (): mixed => $connection->transaction($callback));
            } catch (Throwable $exception) {
                $exception = self::translate($exception, $switch);

                if ($level !== 0 || $attempt >= $attempts || ! (new self)->causedByConcurrencyError($exception)) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Whether this attempt switches to READ COMMITTED: MySQL/MariaDB only, only when the
     * package opens the outermost transaction (inside a host transaction the host's isolation
     * stays), only when it counts a quota, and only while the opt-out is not set.
     */
    public static function shouldSwitch(?DatabaseDriver $driver, int $transactionLevel, bool $countsQuotas, bool $enabled): bool
    {
        return ($driver === DatabaseDriver::Mysql || $driver === DatabaseDriver::Mariadb)
            && $transactionLevel === 0
            && $countsQuotas
            && $enabled;
    }

    /**
     * A STATEMENT-binlog refusal of a switched transaction, as a configuration error that names
     * the fix; anything else unchanged.
     */
    public static function translate(Throwable $exception, bool $switched): Throwable
    {
        if ($switched && $exception instanceof QueryException
            && (int) ($exception->errorInfo[1] ?? 0) === self::STATEMENT_BINLOG_ERROR) {
            return InvalidLifecycleConfigurationException::statementBinlog($exception);
        }

        return $exception;
    }

    /**
     * @return int<1, max>
     */
    public static function attempts(): int
    {
        return max(1, Config::using(InvalidLifecycleConfigurationException::class)
            ->integer('lifecycle.transactions.attempts', 3, 1, 10));
    }
}
