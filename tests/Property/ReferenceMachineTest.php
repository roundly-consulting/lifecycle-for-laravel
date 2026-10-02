<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Engine\RollbackPlanner;
use RoundlyConsulting\Lifecycle\Exceptions\ExpiryException;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Support\ReferenceMachine;

/**
 * Seeded random walks over transitions, rollbacks, freezes, expiry changes, sweeps and time
 * travel: after every step the engine (sqlite) must agree with the in-memory reference on the
 * state, the effective path, the counters, the pending expiry and the entry time.
 */
it('agrees with the reference machine on 200 random walks', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['draft', 'active', 'closed', 'expired', 'archived'])->initial('draft')->terminal('archived');
        $l->state('active')->ttl('10 days')->expiresVia('expire');
        $l->transition('publish')->from('draft')->to('active');
        $l->transition('close')->from('active')->to('closed');
        $l->transition('reopen')->from('closed')->to('active')->maxOccurrences(2);
        $l->transition('expire')->from('active')->to('expired')->systemOnly();
        $l->transition('reactivate')->from('expired')->to('active');
        $l->transition('archive')->from('*')->to('archived')->irreversible();
    });

    $random = new Randomizer(new Mt19937(20261002));
    $transitions = array_keys(ReferenceMachine::TRANSITIONS);
    $started = microtime(true);
    $seen = ['applied' => 0, 'rolled back' => 0, 'expired' => 0];

    for ($walk = 0; $walk < 200; $walk++) {
        LifecycleSchedule::query()->toBase()->delete();
        $now = CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC')->addDays($walk);
        Carbon::setTestNow($now);

        $document = Document::factory()->create();
        $reference = new ReferenceMachine($now);
        $trace = [];

        for ($step = 0; $step < 30; $step++) {
            $roll = $random->getInt(1, 100);
            $handle = Lifecycles::for($document);

            if ($roll <= 40) {
                // Mostly a move the reference considers possible (archive rarely: it ends the walk),
                // sometimes any name at all, to exercise the refusals.
                $possible = array_values(array_filter(
                    $transitions,
                    fn (string $t): bool => in_array($reference->state, ReferenceMachine::TRANSITIONS[$t][0], true) && $t !== 'archive',
                ));
                $name = $possible !== [] && $random->getInt(1, 100) <= 80
                    ? $possible[$random->getInt(0, count($possible) - 1)]
                    : $transitions[$random->getInt(0, count($transitions) - 1)];
                $trace[] = "apply {$name}";
                $expected = $reference->apply($name, $now);
                $actual = $handle->attempt($name)->succeeded;
            } elseif ($roll <= 50) {
                $trace[] = 'rollback';
                $expected = $reference->rollback(null, $now);
                $actual = attemptRollback(fn () => $handle->rollback());
            } elseif ($roll <= 55) {
                $path = $reference->path();
                $position = $random->getInt(0, count($path) - 1);
                $ids = array_reverse(app(RollbackPlanner::class)->path($document, 'status')->pluck('id')->all());
                $trace[] = "rollbackTo #{$position}";
                $expected = $reference->rollback($position, $now);
                $actual = attemptRollback(fn () => $handle->rollbackTo((int) $ids[$position]));
            } elseif ($roll <= 63) {
                $trace[] = $reference->frozen ? 'unfreeze' : 'freeze';
                $expected = true;
                $actual = $reference->frozen ? $handle->unfreeze() : $handle->freeze();
                $reference->frozen = ! $reference->frozen;
            } elseif ($roll <= 78) {
                $now = $now->addSeconds($random->getInt(0, 15 * 86400));
                Carbon::setTestNow($now);
                $trace[] = 'travel to '.$now->toDateTimeString();
                $expected = $actual = true;
            } elseif ($roll <= 83) {
                $at = $now->addSeconds($random->getInt(-86400, 20 * 86400));
                $trace[] = 'expireAt '.$at->toDateTimeString();
                $expected = $reference->expireAt($at);
                $actual = attemptExpiry(fn () => $handle->expireAt($at));
            } elseif ($roll <= 88) {
                $days = $random->getInt(1, 5);
                $trace[] = "extend {$days}";
                $expected = $reference->extend($days);
                $actual = attemptExpiry(fn () => $handle->extend($days.' days'));
            } else {
                $trace[] = 'sweep';
                $reference->sweep($now);
                Lifecycles::sweep();
                $expected = $actual = true;
            }

            if ($actual === true && str_starts_with(end($trace) ?: '', 'apply')) {
                $seen['applied']++;
            }

            if ($actual === true && str_starts_with(end($trace) ?: '', 'rollback')) {
                $seen['rolled back']++;
            }

            $document->refresh();
            $context = "walk {$walk}, step {$step}: ".implode(' → ', $trace);
            $record = LifecycleState::query()->where('subject_id', $document->id)->sole();
            $engineExpiry = LifecycleSchedule::query()->where('subject_id', $document->id)->where('pending_slot', '@expiry')->where('status', 'pending')->value('expires_at');
            $enginePath = LifecycleTransition::query()->where('subject_id', $document->id)->orderBy('id')->get()
                ->filter(fn (LifecycleTransition $row): bool => $row->kind->isOnEffectivePath() && ! LifecycleTransition::query()->where('reverts_id', $row->id)->exists())
                ->map(fn (LifecycleTransition $row): string => ReferenceMachine::describe([
                    'kind' => $row->kind->value, 'transition' => $row->transition, 'from' => $row->from_state, 'to' => $row->to_state,
                ]))->values()->all();

            expect($actual)->toBe($expected, $context)
                ->and($document->status)->toBe($reference->state, $context)
                ->and($record->entered_at->toDateTimeString())->toBe($reference->enteredAt->toDateTimeString(), $context)
                ->and($record->counters ?? [])->toEqual($reference->counters, $context)
                ->and($enginePath)->toBe($reference->describePath(), $context)
                ->and($engineExpiry === null ? null : substr((string) $engineExpiry, 0, 19))->toBe($reference->pendingExpiry()?->toDateTimeString(), $context);
        }
    }

    Carbon::setTestNow();
    $seen['expired'] = LifecycleTransition::query()->where('kind', 'expiry')->count();

    // Not a vacuous walk: every kind of change actually happened, many times.
    // Seed 20261002 gives 798 applied transitions, 280 rollbacks and 27 expiries.
    expect($seen['applied'])->toBeGreaterThan(500)
        ->and($seen['rolled back'])->toBeGreaterThan(100)
        ->and($seen['expired'])->toBeGreaterThan(20)
        ->and(microtime(true) - $started)->toBeLessThan(30.0);
})->group('property');

function attemptRollback(Closure $call): bool
{
    try {
        $call();

        return true;
    } catch (RollbackDeniedException) {
        return false;
    }
}

function attemptExpiry(Closure $call): bool
{
    try {
        $call();

        return true;
    } catch (ExpiryException) {
        return false;
    }
}
