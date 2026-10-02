<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

final readonly class AvailableTransitionsQuery
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?Model $actor = null,
        public bool $system = false,
        public bool $includeDenied = false,
    ) {}
}
