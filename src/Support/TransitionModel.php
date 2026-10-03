<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The one place that resolves the configured transition model (`lifecycle.models.transition`).
 * Instances are bound to the subject's connection: package rows live in the subject's
 * database and are written in the subject's transaction.
 *
 * @internal
 */
final class TransitionModel
{
    /**
     * @return class-string<LifecycleTransition>
     */
    public static function class(): string
    {
        // Strict: a missing class or one that does not extend the packaged model throws — it
        // never falls back to the packaged model.
        try {
            return ModelResolver::for('lifecycle.models.transition', LifecycleTransition::class);
        } catch (InvalidConfigurationException $exception) {
            throw InvalidLifecycleConfigurationException::modelMustExtend('lifecycle.models.transition', LifecycleTransition::class, $exception);
        }
    }

    public static function newFor(Model $subject): LifecycleTransition
    {
        $class = self::class();
        $model = new $class;
        $model->setConnection($subject->getConnectionName());

        return $model;
    }

    /**
     * @return Builder<LifecycleTransition>
     */
    public static function queryFor(Model $subject): Builder
    {
        return self::newFor($subject)->newQuery();
    }

    /**
     * Rows on one connection (the default when null) — pruning.
     *
     * @return Builder<LifecycleTransition>
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
     * @return Builder<LifecycleTransition>
     */
    public static function of(Model $subject, string $lifecycle): Builder
    {
        return self::queryFor($subject)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('lifecycle', $lifecycle);
    }
}
