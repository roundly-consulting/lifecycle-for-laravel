<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Closure;

/**
 * Per-request (scoped) switches for the model hooks: `allowDirectWrites()` lets host code
 * change a lifecycle attribute directly; `engine()` marks the engine's own writes, during
 * which the trait's `updating`/`saved` hooks step aside so a handler's save never re-enters
 * the engine mid-mutation. Counters, reset in `finally`, so nesting and exceptions are safe.
 *
 * @internal
 */
final class WriteGuard
{
    private int $directWrites = 0;

    private int $engine = 0;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function allowDirectWrites(Closure $callback): mixed
    {
        $this->directWrites++;

        try {
            return $callback();
        } finally {
            $this->directWrites--;
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function engine(Closure $callback): mixed
    {
        $this->engine++;

        try {
            return $callback();
        } finally {
            $this->engine--;
        }
    }

    public function allowsDirectWrites(): bool
    {
        return $this->directWrites > 0;
    }

    public function inEngine(): bool
    {
        return $this->engine > 0;
    }
}
