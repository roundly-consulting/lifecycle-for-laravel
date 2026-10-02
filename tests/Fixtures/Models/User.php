<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Factories\UserFactory;

final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['admin' => 'boolean'];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
