<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * A completed rollback: the state before and after, the rows it reverted (newest first) and
 * the rollback rows it appended.
 */
final readonly class RollbackResult
{
    /**
     * @param  list<TransitionRecord>  $reverted
     * @param  list<TransitionRecord>  $records
     */
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public BackedEnum|string $from,
        public BackedEnum|string $to,
        public array $reverted,
        public array $records,
    ) {}
}
