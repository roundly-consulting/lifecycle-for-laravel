<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Soft-delete facts about a subject without assuming it uses SoftDeletes.
 *
 * @internal
 */
final class SoftDeletion
{
    public static function uses(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    public static function column(Model $model): string
    {
        $constant = $model::class.'::DELETED_AT';
        $column = defined($constant) ? constant($constant) : 'deleted_at';

        return is_string($column) ? $column : 'deleted_at';
    }

    public static function qualifiedColumn(Model $model): string
    {
        return $model->qualifyColumn(self::column($model));
    }

    public static function isTrashed(Model $model): bool
    {
        return self::uses($model) && $model->getAttribute(self::column($model)) !== null;
    }

    /**
     * True for a plain model's delete and for a soft-delete model's forceDelete().
     */
    public static function isForceDeleting(Model $model): bool
    {
        return ! self::uses($model) || (method_exists($model, 'isForceDeleting') && $model->isForceDeleting() === true);
    }
}
