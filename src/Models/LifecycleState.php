<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Lifecycle\Casts\UtcDateTime;
use RoundlyConsulting\Lifecycle\Database\Factories\LifecycleStateFactory;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;

/**
 * The state record of one (subject, lifecycle): a mirror of the host attribute used to
 * detect drift, plus the entry time, version, occurrence counters and freeze.
 *
 * Swappable via `lifecycle.models.state` — not final on purpose.
 *
 * @property int $id
 * @property string $subject_type
 * @property int|string $subject_id
 * @property string $lifecycle
 * @property string $state
 * @property string|null $previous_state
 * @property CarbonImmutable $entered_at
 * @property int $version
 * @property array<string, array<string, mixed>>|null $counters
 * @property CarbonImmutable|null $frozen_at
 * @property CarbonImmutable|null $frozen_until
 * @property string|null $frozen_reason
 * @property string|null $frozen_by_type
 * @property int|string|null $frozen_by_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LifecycleState extends Model
{
    /** @use HasFactory<LifecycleStateFactory> */
    use HasFactory;

    protected $table = 'lifecycle_states';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_at' => UtcDateTime::class,
            'frozen_at' => UtcDateTime::class,
            'frozen_until' => UtcDateTime::class,
            'counters' => 'array',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (LifecycleState $state): void {
            if ($state->version < 0) {
                throw InvalidLifecycleUsageException::invalidRecord('a state record version cannot be negative');
            }
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function frozenBy(): MorphTo
    {
        return $this->morphTo('frozen_by');
    }

    /**
     * Frozen when a freeze is set and has no end, or ends in the future.
     */
    public function isFrozen(CarbonImmutable $now): bool
    {
        return $this->frozen_at !== null && ($this->frozen_until === null || $this->frozen_until->greaterThan($now));
    }

    protected static function newFactory(): LifecycleStateFactory
    {
        return LifecycleStateFactory::new();
    }
}
