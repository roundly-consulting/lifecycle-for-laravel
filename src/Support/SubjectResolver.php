<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownLifecycleException;

/**
 * Maps a stored morph type (alias or class name) back to a lifecycle subject class.
 *
 * @internal
 */
final class SubjectResolver
{
    /**
     * @return class-string<Model&LifecycleSubject>
     */
    public static function classFor(string $type): string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, LifecycleSubject::class)) {
            throw UnknownLifecycleException::notASubject($type);
        }

        return $class;
    }

    /**
     * The subject row, ignoring global scopes (a tenant scope must not hide it from a
     * console sweep) — including soft-deleted rows.
     */
    public static function find(string $type, int|string $id): ?Model
    {
        $class = self::classFor($type);

        return (new $class)->newQueryWithoutScopes()->find($id);
    }
}
