<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Context;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Contracts\RestoresSubjectContext;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectContextUnavailableException;

/**
 * Records every subject the package acts on through the context, marks whether the callback
 * ran inside it, and refuses the subjects listed in `$unavailable` (a tenant that is gone).
 */
final class SpySubjectContext implements RestoresSubjectContext
{
    /** @var list<string> "<morph>#<key>" of each subject run, in order */
    public static array $ran = [];

    /** @var list<int|string> keys whose context is gone */
    public static array $unavailable = [];

    public static bool $inside = false;

    public static function reset(): void
    {
        self::$ran = [];
        self::$unavailable = [];
        self::$inside = false;
    }

    public function run(Model $subject, Closure $callback): mixed
    {
        $key = $subject->getKey();

        if (in_array($key, self::$unavailable, true)) {
            throw SubjectContextUnavailableException::teamMissing($subject, 404);
        }

        self::$ran[] = $subject->getMorphClass().'#'.(is_scalar($key) ? (string) $key : '?');
        self::$inside = true;

        try {
            return $callback();
        } finally {
            self::$inside = false;
        }
    }
}
