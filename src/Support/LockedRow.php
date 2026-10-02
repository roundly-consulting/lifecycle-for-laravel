<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The package's replacement for `createOrFirst()`: lock the row when it exists; otherwise
 * insert it inside a savepoint and, when a concurrent writer won the insert, re-read it with
 * a locking (current) read. `createOrFirst()` falls back to a plain read, which under MySQL
 * REPEATABLE READ can miss the very row whose unique violation it just hit.
 *
 * @internal
 */
final class LockedRow
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $attributes  the unique key
     * @param  array<string, mixed>  $values  extra values for a fresh row
     * @return TModel
     */
    public static function firstOrInsert(Builder $query, array $attributes, array $values = []): Model
    {
        $existing = (clone $query)->where($attributes)->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $query->getConnection()->transaction(static function () use ($query, $attributes, $values): Model {
                $model = $query->getModel()->newInstance([...$attributes, ...$values]);
                $model->save();

                return $model;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return (clone $query)->where($attributes)->lockForUpdate()->first() ?? throw $exception;
        }
    }
}
