<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;

/**
 * `'status' => AsLifecycleState::class`: reads the attribute as the definition's state (enum
 * case or string) and writes only declared states, as their raw backing value. Optional — a
 * plain enum cast works too.
 *
 * @implements CastsAttributes<BackedEnum|string, BackedEnum|string|int>
 */
final class AsLifecycleState implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): BackedEnum|string|null
    {
        return $value === null ? null : App::make(DefinitionRegistry::class)->of($model, $key)->decode($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string|int|null
    {
        return $value === null ? null : App::make(DefinitionRegistry::class)->of($model, $key)->encode($value);
    }
}
