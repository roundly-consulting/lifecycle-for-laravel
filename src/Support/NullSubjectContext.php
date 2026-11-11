<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\RestoresSubjectContext;

/**
 * The subject context for apps without tenancy: runs the callback as it is.
 */
final class NullSubjectContext implements RestoresSubjectContext
{
    public function run(Model $subject, Closure $callback): mixed
    {
        return $callback();
    }
}
