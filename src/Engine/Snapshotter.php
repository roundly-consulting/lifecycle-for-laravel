<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw attribute values (`getRawOriginal()` — the locked, stored row) captured before and
 * after a transition, so a rollback can restore them and detect later edits. Comparisons
 * pass both sides through the same JSON round trip, so each driver's own representation is
 * compared with itself.
 *
 * @internal
 */
final class Snapshotter
{
    /**
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    public static function capture(Model $subject, array $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            $values[$attribute] = self::normalise($subject->getRawOriginal($attribute));
        }

        return $values;
    }

    public static function normalise(mixed $value): mixed
    {
        return json_decode((string) json_encode($value), true);
    }

    public static function same(mixed $left, mixed $right): bool
    {
        return self::normalise($left) === self::normalise($right);
    }
}
