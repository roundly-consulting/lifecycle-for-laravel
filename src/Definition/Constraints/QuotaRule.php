<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition\Constraints;

use Closure;

/**
 * At most `max` subjects of one model in a state, per combination of scope column values.
 */
final readonly class QuotaRule
{
    /**
     * @param  list<string>  $scope  scope columns, sorted
     */
    public function __construct(
        public int|Closure $max,
        public array $scope,
        public string $name,
    ) {}
}
