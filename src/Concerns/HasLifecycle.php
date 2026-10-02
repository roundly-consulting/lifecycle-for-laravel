<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Concerns;

use BackedEnum;
use Carbon\CarbonInterval;
use Closure;
use DateInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\Engine\ScheduleBook;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\LifecycleHandle;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Support\ScheduleModel;
use RoundlyConsulting\Lifecycle\Support\SoftDeletion;
use RoundlyConsulting\Lifecycle\Support\StateModel;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;

/**
 * Gives a LifecycleSubject its model hooks, relations, transition sugar and query scopes.
 * Every behaviour goes through LifecycleManager, so `Lifecycles::fake()` sees it.
 *
 * The method names below are reserved on the model: a relation or attribute named
 * `lifecycle` or `transition` must be renamed.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements LifecycleSubject
 */
trait HasLifecycle
{
    public static function bootHasLifecycle(): void
    {
        static::creating(static fn (Model $subject) => self::lifecycleManager()->assignInitialState($subject));
        static::created(static fn (Model $subject) => self::lifecycleManager()->initialize($subject));
        static::updating(static fn (Model $subject) => self::lifecycleManager()->guardDirectWrite($subject));
        static::saved(static fn (Model $subject) => self::lifecycleManager()->subjectSaved($subject));
        static::deleted(static fn (Model $subject) => self::lifecycleManager()->subjectDeleted($subject, SoftDeletion::isForceDeleting($subject)));
        // Model::restored() exists only on SoftDeletes models; registering the event directly
        // keeps a plain model bootable.
        static::registerModelEvent('restored', static fn (Model $subject) => self::lifecycleManager()->subjectRestored($subject));
    }

    /**
     * @return MorphMany<LifecycleState, $this>
     */
    public function lifecycleStates(): MorphMany
    {
        return $this->morphMany(StateModel::class(), 'subject');
    }

    /**
     * @return MorphMany<LifecycleTransition, $this>
     */
    public function lifecycleHistory(): MorphMany
    {
        return $this->morphMany(TransitionModel::class(), 'subject');
    }

    /**
     * @return MorphMany<LifecycleSchedule, $this>
     */
    public function lifecycleSchedules(): MorphMany
    {
        return $this->morphMany(ScheduleModel::class(), 'subject');
    }

    public function lifecycle(?string $lifecycle = null): LifecycleHandle
    {
        return self::lifecycleManager()->for($this, $lifecycle);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transition(string $name, array $payload = [], ?string $lifecycle = null): TransitionResult
    {
        return $this->lifecycle($lifecycle)->with($payload)->apply($name);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transitionTo(BackedEnum|string|int $state, array $payload = [], ?string $lifecycle = null): TransitionResult
    {
        return $this->lifecycle($lifecycle)->with($payload)->transitionTo($state);
    }

    public function canTransition(string $name, ?string $lifecycle = null): bool
    {
        return $this->lifecycle($lifecycle)->can($name);
    }

    public function canTransitionTo(BackedEnum|string|int $state, ?string $lifecycle = null): bool
    {
        return $this->lifecycle($lifecycle)->canTransitionTo($state);
    }

    /**
     * @param  Builder<static>  $query
     * @param  BackedEnum|string|int|list<BackedEnum|string|int>  $states
     */
    public function scopeWhereState(Builder $query, BackedEnum|string|int|array $states, ?string $lifecycle = null): void
    {
        $handle = $this->lifecycle($lifecycle);

        $query->whereIn($query->qualifyColumn($handle->lifecycle), self::encodeLifecycleStates($handle, $states));
    }

    /**
     * @param  Builder<static>  $query
     * @param  BackedEnum|string|int|list<BackedEnum|string|int>  $states
     */
    public function scopeWhereNotState(Builder $query, BackedEnum|string|int|array $states, ?string $lifecycle = null): void
    {
        $handle = $this->lifecycle($lifecycle);

        $query->whereNotIn($query->qualifyColumn($handle->lifecycle), self::encodeLifecycleStates($handle, $states));
    }

    /**
     * Subjects whose lifecycle is frozen right now.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereFrozen(Builder $query, ?string $lifecycle = null): void
    {
        $now = Clock::format(Clock::now());

        $this->whereLifecycleRecord($query, $lifecycle, static function (QueryBuilder $records, string $table) use ($now): void {
            $records->whereNotNull($table.'.frozen_at')
                ->where(static fn (QueryBuilder $until) => $until->whereNull($table.'.frozen_until')->orWhere($table.'.frozen_until', '>', $now));
        });
    }

    /**
     * Subjects that have been in their current state for at least `$atLeast`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereInStateFor(Builder $query, CarbonInterval|DateInterval|string $atLeast, ?string $lifecycle = null): void
    {
        $cutoff = Clock::format(Durations::sub(Clock::now(), Durations::parse($atLeast)));

        $this->whereLifecycleRecord($query, $lifecycle, static function (QueryBuilder $records, string $table) use ($cutoff): void {
            $records->where($table.'.entered_at', '<=', $cutoff);
        });
    }

    /**
     * Subjects whose pending expiry has passed (in grace or not) — expired before the sweep ran.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereExpired(Builder $query, ?string $lifecycle = null): void
    {
        $now = Clock::format(Clock::now());

        $this->whereLifecycleExpiry($query, $lifecycle, static fn (QueryBuilder $rows, string $table) => $rows->where($table.'.expires_at', '<=', $now));
    }

    /**
     * Subjects without a passed pending expiry.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereNotExpired(Builder $query, ?string $lifecycle = null): void
    {
        $now = Clock::format(Clock::now());

        $this->whereLifecycleExpiry($query, $lifecycle, static fn (QueryBuilder $rows, string $table) => $rows->where($table.'.expires_at', '<=', $now), not: true);
    }

    /**
     * Subjects that expire after now and within `$within`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereExpiringWithin(Builder $query, CarbonInterval|DateInterval|string $within, ?string $lifecycle = null): void
    {
        $now = Clock::now();
        $until = Clock::format(Durations::add($now, Durations::parse($within)));
        $from = Clock::format($now);

        $this->whereLifecycleExpiry($query, $lifecycle, static fn (QueryBuilder $rows, string $table) => $rows
            ->where($table.'.expires_at', '>', $from)
            ->where($table.'.expires_at', '<=', $until));
    }

    /**
     * Subjects past their expiry but still within its grace period.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereInGrace(Builder $query, ?string $lifecycle = null): void
    {
        $now = Clock::format(Clock::now());

        $this->whereLifecycleExpiry($query, $lifecycle, static fn (QueryBuilder $rows, string $table) => $rows
            ->where($table.'.expires_at', '<=', $now)
            ->where($table.'.due_at', '>', $now));
    }

    /**
     * Eager-load the state records and open schedules, so handles and resources read them
     * without a query per subject.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithLifecycle(Builder $query): void
    {
        $query->with([
            'lifecycleStates',
            'lifecycleSchedules' => static fn ($schedules) => $schedules->whereIn('status', [ScheduleStatus::Pending->value, ScheduleStatus::Paused->value]),
        ]);
    }

    /**
     * A correlated `exists` on the pending expiry row of one lifecycle.
     *
     * @param  Builder<static>  $query
     * @param  Closure(QueryBuilder, string): mixed  $constraint
     */
    private function whereLifecycleExpiry(Builder $query, ?string $lifecycle, Closure $constraint, bool $not = false): void
    {
        $name = $this->lifecycle($lifecycle)->lifecycle;
        $table = ScheduleModel::newFor($this)->getTable();
        $morph = $this->getMorphClass();
        $key = $this->getQualifiedKeyName();

        $query->whereExists(static function (QueryBuilder $rows) use ($table, $morph, $key, $name, $constraint): void {
            $rows->selectRaw('1')
                ->from($table)
                ->where($table.'.subject_type', $morph)
                ->whereColumn($table.'.subject_id', $key)
                ->where($table.'.lifecycle', $name)
                ->where($table.'.pending_slot', ScheduleBook::EXPIRY_SLOT)
                ->where($table.'.status', ScheduleStatus::Pending->value);

            $constraint($rows, $table);
        }, not: $not);
    }

    /**
     * A correlated `exists` on the subject's state record of one lifecycle.
     *
     * @param  Builder<static>  $query
     * @param  Closure(QueryBuilder, string): void  $constraint
     */
    private function whereLifecycleRecord(Builder $query, ?string $lifecycle, Closure $constraint): void
    {
        $name = $this->lifecycle($lifecycle)->lifecycle;
        $table = StateModel::newFor($this)->getTable();
        $morph = $this->getMorphClass();
        $key = $this->getQualifiedKeyName();

        $query->whereExists(static function (QueryBuilder $records) use ($table, $morph, $key, $name, $constraint): void {
            $records->selectRaw('1')
                ->from($table)
                ->where($table.'.subject_type', $morph)
                ->whereColumn($table.'.subject_id', $key)
                ->where($table.'.lifecycle', $name);

            $constraint($records, $table);
        });
    }

    /**
     * Declared states only (an undeclared one throws), as raw bound values.
     *
     * @param  BackedEnum|string|int|list<BackedEnum|string|int>  $states
     * @return list<string|int>
     */
    private static function encodeLifecycleStates(LifecycleHandle $handle, BackedEnum|string|int|array $states): array
    {
        $definition = $handle->definition();

        return array_map(
            static fn (BackedEnum|string|int $state): string|int => $definition->encode($state),
            is_array($states) ? $states : [$states],
        );
    }

    private static function lifecycleManager(): LifecycleManager
    {
        return App::make(LifecycleManager::class);
    }
}
