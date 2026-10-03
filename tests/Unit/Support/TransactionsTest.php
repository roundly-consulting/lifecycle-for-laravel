<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Support\Transactions;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;

function queryException(int $code): QueryException
{
    $pdo = new PDOException('refused');
    $pdo->errorInfo = ['HY000', $code, 'refused'];

    return new QueryException('mysql', 'insert into listings', [], $pdo);
}

it('turns a statement-binlog refusal of a switched transaction into a configuration error', function (): void {
    $previous = queryException(1665);
    $translated = Transactions::translate($previous, switched: true);

    expect($translated)->toBeInstanceOf(InvalidLifecycleConfigurationException::class)
        ->and($translated->getMessage())->toContain('binlog_format=STATEMENT')
        ->and($translated->getMessage())->toContain('lifecycle.transactions.mysql_read_committed')
        ->and($translated->getPrevious())->toBe($previous);
});

it('leaves every other exception unchanged', function (Throwable $exception, bool $switched): void {
    expect(Transactions::translate($exception, $switched))->toBe($exception);
})->with([
    'the same error, not switched' => [fn () => queryException(1665), false],
    'another error code' => [fn () => queryException(1213), true],
    'not a query exception' => [fn () => new RuntimeException('boom'), true],
]);

it('reads the attempts from the transactions group', function (): void {
    expect(Transactions::attempts())->toBe(3);

    config()->set('lifecycle.transactions.attempts', 7);

    expect(Transactions::attempts())->toBe(7);

    config()->set('lifecycle.transactions.attempts', 11);

    expect(fn () => Transactions::attempts())->toThrow(InvalidLifecycleConfigurationException::class);
});

/**
 * @return array<string, array{0: DatabaseDriver|null, 1: int, 2: bool, 3: bool, 4: bool}>
 */
function switchMatrix(): array
{
    $rows = [];

    foreach ([DatabaseDriver::Mysql, DatabaseDriver::Mariadb, DatabaseDriver::Pgsql, DatabaseDriver::Sqlite, null] as $driver) {
        foreach ([0, 1] as $level) {
            foreach ([true, false] as $countsQuotas) {
                foreach ([true, false] as $enabled) {
                    $expected = ($driver === DatabaseDriver::Mysql || $driver === DatabaseDriver::Mariadb)
                        && $level === 0 && $countsQuotas && $enabled;
                    $name = sprintf('%s, level %d, %s, %s', $driver->value ?? 'unknown', $level, $countsQuotas ? 'quota' : 'no quota', $enabled ? 'on' : 'off');
                    $rows[$name] = [$driver, $level, $countsQuotas, $enabled, $expected];
                }
            }
        }
    }

    return $rows;
}

it('switches only for a quota count in an outermost MySQL/MariaDB transaction with the flag on', function (?DatabaseDriver $driver, int $level, bool $countsQuotas, bool $enabled, bool $expected): void {
    expect(Transactions::shouldSwitch($driver, $level, $countsQuotas, $enabled))->toBe($expected);
})->with(switchMatrix());

it('pins the switch matrix to exactly two switching rows', function (): void {
    expect(switchMatrix())->toHaveCount(40)
        ->and(array_keys(array_filter(switchMatrix(), static fn (array $row): bool => $row[4])))
        ->toBe(['mysql, level 0, quota, on', 'mariadb, level 0, quota, on']);
});
