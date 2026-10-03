<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-request (scoped) switches for the model hooks: `allowDirectWrites()` lets host code
 * change a lifecycle attribute directly; `engine()` marks the one (subject row, lifecycle) the
 * engine is writing, whose `saved` hook (adoption, expiry sync) steps aside so a handler's save
 * never re-enters the engine mid-mutation. Every other model — and every other lifecycle of the
 * same model — keeps its hooks inside handlers. Counters, reset in `finally`, so nesting and
 * exceptions are safe.
 *
 * @internal
 */
final class WriteGuard
{
    private int $directWrites = 0;

    /** @var array<string, int<1, max>> */
    private array $engine = [];

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
    public function engine(Model $subject, string $lifecycle, Closure $callback): mixed
    {
        $key = self::key($subject, $lifecycle);
        $this->engine[$key] = ($this->engine[$key] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            $depth = $this->engine[$key] - 1;

            if ($depth > 0) {
                $this->engine[$key] = $depth;
            } else {
                unset($this->engine[$key]);
            }
        }
    }

    public function allowsDirectWrites(): bool
    {
        return $this->directWrites > 0;
    }

    /**
     * Whether the engine is writing this lifecycle of this subject row (any instance of it).
     */
    public function inEngine(Model $subject, string $lifecycle): bool
    {
        return array_key_exists(self::key($subject, $lifecycle), $this->engine);
    }

    private static function key(Model $subject, string $lifecycle): string
    {
        return json_encode([$subject->getConnectionName(), $subject->getMorphClass(), $subject->getKey(), $lifecycle], JSON_THROW_ON_ERROR);
    }
}
