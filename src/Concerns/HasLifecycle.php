<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\LifecycleHandle;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
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
