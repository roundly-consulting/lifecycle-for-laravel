<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Support;

use Closure;

/**
 * Which connections are inside a package transaction that switched MySQL/MariaDB to READ
 * COMMITTED (see Transactions::run()). The quota count reads it: only a transaction that is
 * known to read committed may count with a plain statement. Bound scoped — the depth lives
 * per request / job, never in a static.
 *
 * @internal
 */
final class Isolation
{
    /** @var array<string, int<1, max>> */
    private array $depth = [];

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $connection, Closure $callback): mixed
    {
        $this->depth[$connection] = ($this->depth[$connection] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            $depth = $this->depth[$connection] - 1;

            if ($depth > 0) {
                $this->depth[$connection] = $depth;
            } else {
                unset($this->depth[$connection]);
            }
        }
    }

    public function readsCommitted(string $connection): bool
    {
        return array_key_exists($connection, $this->depth);
    }
}
