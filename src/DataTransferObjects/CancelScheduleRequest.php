<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

final readonly class CancelScheduleRequest
{
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public string $transition,
    ) {}
}
