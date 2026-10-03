<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The one place that resolves the configured schedule model (`lifecycle.models.schedule`).
 * Instances are bound to the subject's connection: package rows live in the subject's
 * database and are written in the subject's transaction.
 *
 * @internal
 */
final class ScheduleModel
{
    /**
     * @return class-string<LifecycleSchedule>
     */
    public static function class(): string
    {
        $class = ModelResolver::for('lifecycle.models.schedule', LifecycleSchedule::class);

        if (! is_a($class, LifecycleSchedule::class, true)) {
            throw InvalidLifecycleConfigurationException::modelMustExtend('lifecycle.models.schedule', LifecycleSchedule::class);
        }

        return $class;
    }

    public static function newFor(Model $subject): LifecycleSchedule
    {
        $class = self::class();
        $model = new $class;
        $model->setConnection($subject->getConnectionName());

        return $model;
    }

    /**
     * @return Builder<LifecycleSchedule>
     */
    public static function queryFor(Model $subject): Builder
    {
        return self::newFor($subject)->newQuery();
    }

    /**
     * Rows on one connection (the default when null) — the sweep's view: package rows live on
     * their subject's connection, so a subject on another connection is swept with
     * `lifecycle:sweep --database=<connection>`.
     *
     * @return Builder<LifecycleSchedule>
     */
    public static function query(?string $connection = null): Builder
    {
        $class = self::class();
        $model = new $class;

        if ($connection !== null) {
            $model->setConnection($connection);
        }

        return $model->newQuery();
    }

    /**
     * Rows of one subject's lifecycle.
     *
     * @return Builder<LifecycleSchedule>
     */
    public static function of(Model $subject, string $lifecycle): Builder
    {
        return self::queryFor($subject)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('lifecycle', $lifecycle);
    }
}
