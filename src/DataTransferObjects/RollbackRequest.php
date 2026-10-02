<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * Undo the last transition (`toHistoryId` null) or roll back to a history row. `force`
 * skips the snapshot-conflict check only.
 */
final readonly class RollbackRequest
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?int $toHistoryId = null,
        public ?Model $actor = null,
        public bool $system = false,
        public ?string $reason = null,
        public bool $force = false,
    ) {}
}
