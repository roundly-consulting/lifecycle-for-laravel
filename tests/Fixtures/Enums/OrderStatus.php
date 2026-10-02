<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums;

enum OrderStatus: int
{
    case Pending = 1;
    case Paid = 2;
    case Fulfilled = 3;
    case Cancelled = 4;

    public function label(): string
    {
        return 'Order '.strtolower($this->name);
    }
}
