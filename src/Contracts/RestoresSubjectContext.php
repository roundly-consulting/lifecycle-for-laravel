<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectContextUnavailableException;

/**
 * Runs a callback inside a subject's tenant context (teams: `Teams::runAs()` of the subject's
 * team) and restores the previous context afterwards, also on an exception. Wraps everything
 * the package does to a subject outside the caller's own context: due schedules (inline and
 * queued), expiry warnings, retries and `adoptAll()` / `lifecycle:adopt`. Bind your own with
 * `lifecycle.subjects.context`.
 */
interface RestoresSubjectContext
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws SubjectContextUnavailableException when the subject's tenant is gone (its team
     *                                            was deleted, or the stored team is missing);
     *                                            the callback is not called
     */
    public function run(Model $subject, Closure $callback): mixed;
}
