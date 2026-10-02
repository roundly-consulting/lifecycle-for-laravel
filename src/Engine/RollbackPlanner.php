<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Support\TransitionModel;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Decides a rollback before anything changes: which effective-path rows it reverts, and
 * every reason it may not — all of them checked first, so a rollback is all or nothing.
 *
 * @internal
 */
final readonly class RollbackPlanner
{
    public function __construct(
        private GuardPipeline $pipeline,
        private QuotaGate $quotas,
    ) {}

    public function plan(Model $subject, string $lifecycle, CompiledDefinition $definition, ?LifecycleState $record, RollbackRequest $request, ?Model $actor, bool $lock): RollbackPlan
    {
        $now = Clock::now();
        $current = $definition->key($subject->getRawOriginal($lifecycle) ?? ($subject->getAttributes()[$lifecycle] ?? null));
        $max = Config::using(InvalidLifecycleConfigurationException::class)->intBetween('lifecycle.rollback.max_steps', 1, 1000, 50);
        $params = ['state' => $definition->stateLabel($current), 'transition' => ''];

        $path = array_values($this->path($subject, $lifecycle)->limit($request->toHistoryId === null ? 1 : $max + 1)->get()->all());
        $targets = $path;

        if ($request->toHistoryId !== null) {
            $index = null;

            foreach ($path as $position => $row) {
                if ($row->id === $request->toHistoryId) {
                    $index = $position;
                }
            }

            if ($index === null) {
                $onPath = $this->path($subject, $lifecycle)->whereKey($request->toHistoryId)->exists();

                if ($onPath) {
                    throw InvalidLifecycleUsageException::tooManyRollbackSteps($max);
                }

                return new RollbackPlan([], Decision::deny(Denial::of(DenialCode::NotOnPath, $params, source: 'rollback')), null);
            }

            $targets = array_slice($path, 0, $index);
        }

        if ($targets === []) {
            return new RollbackPlan([], Decision::deny(Denial::of(DenialCode::NothingToRollback, $params, source: 'rollback')), null);
        }

        $denials = [];
        $top = $targets[0];
        $topTransition = $top->transition === null ? null : $definition->transition($top->transition);

        if ($top->to_state !== $current) {
            $denials[] = Denial::of(DenialCode::StateMismatch, $params, source: 'rollback');
        }

        if ($record !== null && ! ($topTransition !== null && $topTransition->ignoresFreeze) && $record->isFrozen($now)) {
            $denials[] = Denial::of(DenialCode::Frozen, $params, source: 'record');
        }

        $sealedAfter = $definition->state($current)->sealedAfter;

        if ($record !== null && $sealedAfter !== null && $now->greaterThanOrEqualTo(Durations::add($record->entered_at, $sealedAfter))) {
            $denials[] = Denial::of(DenialCode::Sealed, $params, source: 'record');
        }

        $window = Durations::nullableFromConfig('lifecycle.rollback.default_window');
        // The simulation starts from what the database holds now (not a model's cast-set original).
        $simulated = $request->force ? [] : Snapshotter::stored($subject, $this->snapshotKeys($targets));

        foreach ($targets as $row) {
            $transition = $row->transition === null ? null : $definition->transition($row->transition);
            $rowParams = [...$params, 'transition' => $transition?->label() ?? (string) $row->transition];

            if (! $row->kind->isReversibleKind() || $transition === null) {
                $denials[] = Denial::of(DenialCode::NotReversible, $rowParams, source: 'rollback');

                continue;
            }

            if ($transition->reversibility->irreversible) {
                $denials[] = Denial::of(DenialCode::Irreversible, $rowParams, source: 'rollback');

                continue;
            }

            if (! $transition->reversibility->isReversible()) {
                $denials[] = Denial::of(DenialCode::NotReversible, $rowParams, source: 'rollback');

                continue;
            }

            $within = $transition->reversibility->window ?? $window;

            if ($within !== null && $now->greaterThanOrEqualTo(Durations::add($row->occurred_at, $within))) {
                $denials[] = Denial::of(DenialCode::RollbackWindowPassed, $rowParams, source: 'rollback');
            }

            if (! $request->system) {
                $evaluation = new Evaluation(
                    definition: $definition,
                    context: new TransitionContext(
                        subject: $subject,
                        lifecycle: $lifecycle,
                        transition: $transition,
                        from: $definition->codec->tryDecode($row->to_state) ?? $definition->value($current),
                        to: $definition->codec->tryDecode((string) $row->from_state) ?? $definition->value($current),
                        actor: $actor,
                        system: false,
                        reason: $request->reason,
                        payload: [],
                        now: $now,
                        version: $record === null ? 0 : $record->version,
                    ),
                    mode: $lock ? Mode::Apply : Mode::Check,
                    record: $record,
                );

                array_push($denials, ...$this->pipeline->rollbackActor($evaluation));
            }

            if (! $request->force && $this->conflicts($subject, $row, $simulated)) {
                $denials[] = Denial::of(DenialCode::RollbackConflict, $rowParams, source: 'rollback');
            }
        }

        $final = (string) $targets[count($targets) - 1]->from_state;

        if ($denials === [] && $definition->hasState($final)) {
            $denials = $this->quotas->entering($subject, $lifecycle, $definition, $current, $final, $topTransition?->label() ?? '', $lock);
        }

        return new RollbackPlan($targets, Decision::from($denials), $final);
    }

    /**
     * The effective path, newest first: forward rows (initial, adopted, transitions, expiries,
     * scheduled) that no rollback row reverts.
     *
     * @return Builder<LifecycleTransition>
     */
    public function path(Model $subject, string $lifecycle): Builder
    {
        $model = TransitionModel::newFor($subject);
        $table = $model->getTable();
        $kinds = array_values(array_map(
            static fn (TransitionKind $kind): string => $kind->value,
            array_filter(TransitionKind::cases(), static fn (TransitionKind $kind): bool => $kind->isOnEffectivePath()),
        ));

        return TransitionModel::of($subject, $lifecycle)
            ->whereIn($table.'.kind', $kinds)
            ->whereNotExists(static fn (QueryBuilder $reverts) => $reverts->selectRaw('1')
                ->from($table.' as lifecycle_reverts')
                ->whereColumn('lifecycle_reverts.reverts_id', $table.'.id'))
            ->orderByDesc($table.'.id');
    }

    /**
     * @param  list<LifecycleTransition>  $targets
     * @return list<string>
     */
    private function snapshotKeys(array $targets): array
    {
        $keys = [];

        foreach ($targets as $row) {
            $after = $row->snapshot['after'] ?? null;

            foreach (is_array($after) ? array_keys($after) : [] as $key) {
                $keys[(string) $key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Compares the row's `after` snapshot with the values the newer reverts will leave
     * (starting from the stored row), then moves the simulation to its `before` values.
     *
     * @param  array<string, mixed>  $simulated
     */
    private function conflicts(Model $subject, LifecycleTransition $row, array &$simulated): bool
    {
        $snapshot = $row->snapshot;

        if (! is_array($snapshot)) {
            return false;
        }

        $after = is_array($snapshot['after'] ?? null) ? $snapshot['after'] : [];
        $before = is_array($snapshot['before'] ?? null) ? $snapshot['before'] : [];
        $conflict = false;

        foreach ($after as $attribute => $value) {
            $attribute = (string) $attribute;
            $current = $simulated[$attribute] ?? null;

            if (! Snapshotter::same($current, $value)) {
                $conflict = true;
            }
        }

        foreach ($before as $attribute => $value) {
            $simulated[(string) $attribute] = $value;
        }

        return $conflict;
    }
}
