<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Lifecycle\Casts\UtcDateTime;
use RoundlyConsulting\Lifecycle\Database\Factories\LifecycleScheduleFactory;
use RoundlyConsulting\Lifecycle\DataTransferObjects\ScheduledTransition;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\Enums\ScheduleOutcome;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;

/**
 * A future system transition: a TTL expiry or a scheduled transition. An open row holds
 * its slot; a finished one releases it.
 *
 * Swappable via `lifecycle.models.schedule` — not final on purpose.
 *
 * @property int $id
 * @property string $subject_type
 * @property int|string $subject_id
 * @property string $lifecycle
 * @property ScheduleKind $kind
 * @property string $transition
 * @property string $for_state
 * @property ScheduleStatus $status
 * @property string|null $pending_slot
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $next_warn_at
 * @property int $warnings_sent
 * @property int $attempts
 * @property string|null $last_denial
 * @property ScheduleOutcome|null $outcome
 * @property bool $is_override
 * @property string|null $scheduled_by_type
 * @property int|string|null $scheduled_by_id
 * @property array<string, mixed>|null $context
 * @property int|null $created_by_transition_id
 * @property int|null $cancelled_by_transition_id
 * @property CarbonImmutable|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LifecycleSchedule extends Model
{
    /** @use HasFactory<LifecycleScheduleFactory> */
    use HasFactory;

    protected $table = 'lifecycle_schedules';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ScheduleKind::class,
            'status' => ScheduleStatus::class,
            'outcome' => ScheduleOutcome::class,
            'due_at' => UtcDateTime::class,
            'expires_at' => UtcDateTime::class,
            'next_warn_at' => UtcDateTime::class,
            'finished_at' => UtcDateTime::class,
            'is_override' => 'boolean',
            'context' => 'array',
            'warnings_sent' => 'integer',
            'attempts' => 'integer',
            'created_by_transition_id' => 'integer',
            'cancelled_by_transition_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (LifecycleSchedule $schedule): void {
            $status = $schedule->getAttributeValue('status');
            $open = ! $status instanceof ScheduleStatus || $status->isOpen();

            if ($open === ($schedule->pending_slot === null)) {
                throw InvalidLifecycleUsageException::invalidRecord('an open schedule holds a slot and a finished one does not');
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
    public function scheduledBy(): MorphTo
    {
        return $this->morphTo('scheduled_by');
    }

    public function toScheduled(CompiledDefinition $definition): ScheduledTransition
    {
        return new ScheduledTransition(
            id: $this->id,
            lifecycle: $this->lifecycle,
            kind: $this->kind,
            transition: $this->transition,
            forState: $definition->codec->tryDecode($this->for_state) ?? $this->for_state,
            dueAt: $this->due_at,
            expiresAt: $this->expires_at,
            status: $this->status,
            attempts: $this->attempts,
            nextWarnAt: $this->next_warn_at,
        );
    }

    protected static function newFactory(): LifecycleScheduleFactory
    {
        return LifecycleScheduleFactory::new();
    }
}
