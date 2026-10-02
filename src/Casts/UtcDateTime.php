<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Support\Clock;
use Throwable;

/**
 * Every package datetime column is stored as a UTC `Y-m-d H:i:s` string, whatever the
 * application timezone, and read back as a UTC CarbonImmutable.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
final class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat(Clock::FORMAT, substr((string) $value, 0, 19), 'UTC');
        } catch (Throwable) {
            $parsed = null;
        }

        return $parsed instanceof CarbonImmutable ? $parsed : throw InvalidLifecycleUsageException::invalidDateTime($value);
    }

    /**
     * A DateTimeInterface is converted to UTC; a `Y-m-d H:i:s` string is taken as UTC already.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => Clock::format($value),
            CarbonImmutable::hasFormat($value, Clock::FORMAT) => $value,
            default => throw InvalidLifecycleUsageException::invalidDateTime($value),
        };
    }
}
