<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Lifecycle\Casts\UtcDateTime;
use RoundlyConsulting\Lifecycle\Database\Factories\LifecycleTransitionFactory;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRecord;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Exceptions\HistoryIsAppendOnlyException;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;

/**
 * One append-only history row. Updating or deleting it through Eloquent throws; pruning
 * and force-delete purging use query-builder deletes.
 *
 * Swappable via `lifecycle.models.transition` — not final on purpose.
 *
 * @property int $id
 * @property string $subject_type
 * @property int|string $subject_id
 * @property string $lifecycle
 * @property TransitionKind $kind
 * @property string|null $transition
 * @property string|null $from_state
 * @property string $to_state
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property bool $is_system
 * @property string|null $reason
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $snapshot
 * @property CarbonImmutable|null $previous_entered_at
 * @property array<string, mixed>|null $counter_before
 * @property int $version
 * @property int|null $reverts_id
 * @property int|null $schedule_id
 * @property string|null $idempotency_key
 * @property CarbonImmutable $occurred_at
 */
class LifecycleTransition extends Model
{
    /** @use HasFactory<LifecycleTransitionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'lifecycle_transitions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TransitionKind::class,
            'is_system' => 'boolean',
            'context' => 'array',
            'snapshot' => 'array',
            'counter_before' => 'array',
            'previous_entered_at' => UtcDateTime::class,
            'occurred_at' => UtcDateTime::class,
            'version' => 'integer',
            'reverts_id' => 'integer',
            'schedule_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (LifecycleTransition $transition): never {
            throw HistoryIsAppendOnlyException::cannotUpdate($transition->id);
        });

        static::deleting(static function (LifecycleTransition $transition): never {
            throw HistoryIsAppendOnlyException::cannotDelete($transition->id);
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
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The row this rollback row reverted.
     *
     * @return BelongsTo<LifecycleTransition, $this>
     */
    public function reverts(): BelongsTo
    {
        return $this->belongsTo(TransitionModel::class(), 'reverts_id');
    }

    /**
     * The rollback row that reverted this row, if any.
     *
     * @return HasOne<LifecycleTransition, $this>
     */
    public function revertedBy(): HasOne
    {
        return $this->hasOne(TransitionModel::class(), 'reverts_id');
    }

    /**
     * @return BelongsTo<LifecycleSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ScheduleModel::class(), 'schedule_id');
    }

    public function toRecord(CompiledDefinition $definition, ?bool $reverted = null): TransitionRecord
    {
        return new TransitionRecord(
            id: $this->id,
            lifecycle: $this->lifecycle,
            kind: $this->kind,
            transition: $this->transition,
            from: $this->from_state === null ? null : ($definition->codec->tryDecode($this->from_state) ?? $this->from_state),
            to: $definition->codec->tryDecode($this->to_state) ?? $this->to_state,
            actorType: $this->actor_type,
            actorId: $this->actor_id,
            system: $this->is_system,
            reason: $this->reason,
            context: $this->context ?? [],
            snapshot: $this->snapshot,
            version: $this->version,
            revertsId: $this->reverts_id,
            scheduleId: $this->schedule_id,
            occurredAt: $this->occurred_at,
            reverted: $reverted ?? ($this->relationLoaded('revertedBy') ? $this->revertedBy !== null : $this->revertedBy()->exists()),
        );
    }

    protected static function newFactory(): LifecycleTransitionFactory
    {
        return LifecycleTransitionFactory::new();
    }
}
