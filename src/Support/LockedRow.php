<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;

/**
 * The package's replacement for `createOrFirst()`: the row with a unique key, created when
 * missing, locked `FOR UPDATE` until the transaction ends. `createOrFirst()` falls back to a
 * plain read, which under MySQL REPEATABLE READ can miss the very row whose unique violation
 * it just hit; every re-read here is a locking (current) read.
 *
 * @internal
 */
final class LockedRow
{
    /**
     * A per-subject row (state record, expiry slot), always called with the subject row
     * already locked — so no two transactions insert the same key at once. Inserted through
     * Eloquent, so a host's swapped model sees its model events.
     *
     * The first read is a locking read on pgsql/sqlite. On MySQL/MariaDB a locking read of a
     * missing key takes a gap lock, and two subjects' first inserts into one gap deadlock — so
     * MySQL reads plainly first and then locks the found row by its key; a row the plain read's
     * snapshot missed surfaces as a unique violation and is re-read with a locking read.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $attributes  the unique key
     * @param  array<string, mixed>  $values  extra values for a fresh row
     * @return TModel
     */
    public static function firstOrInsert(Builder $query, array $attributes, array $values = []): Model
    {
        $existing = self::isMysql($query)
            ? self::lockFound((clone $query)->where($attributes)->first(), $query)
            : (clone $query)->where($attributes)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $query->getModel()->getConnection()->transaction(static function () use ($query, $attributes, $values): Model {
                $model = $query->getModel()->newInstance([...$attributes, ...$values]);
                $model->save();

                return $model;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return (clone $query)->where($attributes)->lockForUpdate()->first() ?? throw $exception;
        }
    }

    /**
     * A cross-subject mutex row (quota partitions), raced for by many transactions at once.
     * An existing row is locked by its unique key (a record lock only — the steady state).
     * A missing one is inserted with the query builder so a duplicate never ends in a shared
     * lock upgraded to exclusive: `INSERT … ON DUPLICATE KEY UPDATE` (exclusive lock directly)
     * on MySQL/MariaDB, `INSERT … ON CONFLICT DO NOTHING` elsewhere (pgsql waits for a racing
     * insert to commit) — then locked with a fresh statement. Racing first inserts of one key
     * can still deadlock on MySQL's gap locks; the transaction retry settles them, because the
     * retry finds the row.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  non-empty-array<non-empty-string, mixed>  $attributes  the unique key
     * @return TModel
     */
    public static function mutex(Builder $query, array $attributes): Model
    {
        $existing = (clone $query)->where($attributes)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        $model = $query->getModel()->newInstance($attributes);

        if ($model->usesTimestamps()) {
            $model->updateTimestamps();
        }

        self::isMysql($query)
            ? $query->toBase()->upsert([$model->getAttributes()], array_keys($attributes), [array_key_first($attributes)])
            : $query->toBase()->insertOrIgnore([$model->getAttributes()]);

        return (clone $query)->where($attributes)->lockForUpdate()->firstOrFail();
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel|null  $found
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private static function lockFound(?Model $found, Builder $query): ?Model
    {
        return $found === null ? null : (clone $query)->whereKey($found->getKey())->lockForUpdate()->first();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function isMysql(Builder $query): bool
    {
        $driver = DatabaseDriver::tryFrom($query->getModel()->getConnection()->getDriverName());

        return $driver === DatabaseDriver::Mysql || $driver === DatabaseDriver::Mariadb;
    }
}
