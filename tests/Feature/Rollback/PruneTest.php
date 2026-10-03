<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneResult;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $listing->transition('close');
    Carbon::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    $listing->transition('reopen');
});

afterEach(fn () => Carbon::setTestNow());

it('prunes old history and finished schedules, never limits', function (): void {
    $result = Lifecycles::prune(new PruneOptions(30, 30));

    expect($result->historyDeleted)->toBe(3)
        ->and($result->schedulesDeleted)->toBe(1)
        ->and(LifecycleTransition::query()->count())->toBe(1)
        ->and(LifecycleSchedule::query()->where('status', 'pending')->count())->toBe(1)
        ->and(Lifecycles::for(Listing::query()->firstOrFail())->check('close')->allowed)->toBeTrue();
});

it('counts without deleting on a dry run', function (): void {
    expect(Lifecycles::prune(new PruneOptions(30, 30, dryRun: true)))->toEqual(new PruneResult(3, 1))
        ->and(LifecycleTransition::query()->count())->toBe(4);
});

it('uses the configured retention and prunes nothing by default for history', function (): void {
    expect(Lifecycles::prune(new PruneOptions)->historyDeleted)->toBe(0)
        ->and(LifecycleSchedule::query()->count())->toBe(1);

    config()->set('lifecycle.history.prune_after_days', 30);

    expect(Lifecycles::prune(new PruneOptions)->historyDeleted)->toBe(3);
});

it('prunes from the command with int or string options', function (int|string $days): void {
    expect(Artisan::call('lifecycle:prune', ['--history-days' => $days, '--schedule-days' => $days, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Would delete 3 history row(s) and 1 schedule row(s).')
        ->and(Artisan::call('lifecycle:prune', ['--history-days' => $days]))->toBe(0)
        ->and(Artisan::output())->toContain('Deleted 3 history row(s)');
})->with(['int' => 30, 'string' => '30']);

it('reports nothing to prune when no retention is configured', function (): void {
    config()->set('lifecycle.schedules.prune_after_days', null);

    expect(Artisan::call('lifecycle:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to prune');
});

it('refuses an invalid day count', function (): void {
    expect(Artisan::call('lifecycle:prune', ['--history-days' => 'soon']))->toBe(1);
});

it('drops rollback points older than the cutoff', function (): void {
    $listing = Listing::query()->firstOrFail();
    $old = LifecycleTransition::query()->where('transition', 'publish')->sole()->id;

    Lifecycles::prune(new PruneOptions(30, null));

    expect(Lifecycles::for($listing)->canRollback()->allowed)->toBeTrue();

    try {
        Lifecycles::for($listing)->rollbackTo($old);
        $this->fail('expected a denial');
    } catch (RollbackDeniedException $exception) {
        expect($exception->decision()->codes())->toBe(['not_on_path']);
    }
});

it('keeps the neverExpire() mark of a stay that is still running', function (): void {
    $listing = Listing::query()->sole();
    Lifecycles::for($listing)->neverExpire();
    $marked = Listing::factory()->create();
    $marked->transition('publish');
    Lifecycles::for($marked)->neverExpire();
    $marked->transition('close');
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-01 10:00:00', 'UTC'));

    Lifecycles::prune(new PruneOptions(null, 30));

    $marks = LifecycleSchedule::query()->where('is_override', true)->where('outcome', 'cancelled')->pluck('subject_id')->all();

    expect($marks)->toBe([$listing->id]);
});
