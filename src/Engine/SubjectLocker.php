<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectTrashedException;
use RoundlyConsulting\Lifecycle\Support\SoftDeletion;

/**
 * Lock order step one: the subject row, `FOR UPDATE`, before any package row. The locked
 * row's values are copied into the in-memory subject for every attribute the caller has not
 * dirtied, so guards, quota scopes, snapshots and stamps decide on the stored truth.
 *
 * @internal
 */
final readonly class SubjectLocker
{
    public function lock(Model $subject, bool $allowTrashed = false): Model
    {
        $stored = $subject->newQueryWithoutScopes()->whereKey($subject->getKey())->lockForUpdate()->first();

        if ($stored === null) {
            throw SubjectNotPersistedException::for($subject);
        }

        if (! $allowTrashed && SoftDeletion::isTrashed($stored)) {
            throw SubjectTrashedException::for($subject);
        }

        $fresh = $stored->getAttributes();
        $dirty = $subject->getDirty();

        $subject->setRawAttributes($fresh, true);
        $subject->setRawAttributes([...$fresh, ...$dirty]);

        return $stored;
    }
}
