<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\SecondaryDocument;

/**
 * Package rows live in the subject's database and are written in the subject's transaction.
 */
beforeEach(function (): void {
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);

    foreach ([__DIR__.'/../../../database/migrations', __DIR__.'/../../Fixtures/migrations'] as $path) {
        Artisan::call('migrate', ['--database' => 'secondary', '--path' => $path, '--realpath' => true]);
    }
});

it('writes package rows on the subject connection only', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));
    $document = SecondaryDocument::query()->create();

    $document->transition('go');

    expect(DB::connection('secondary')->table('lifecycle_transitions')->count())->toBe(2)
        ->and(DB::connection('secondary')->table('lifecycle_states')->value('state'))->toBe('b')
        ->and(LifecycleState::query()->count())->toBe(0);
});

it('rolls back on both connections when the handler throws', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->handledBy(function (TransitionContext $c): never {
        throw new RuntimeException('nope');
    })));
    $document = SecondaryDocument::query()->create();

    expect(fn () => $document->transition('go'))->toThrow(RuntimeException::class);

    expect(DB::connection('secondary')->table('lifecycle_transitions')->count())->toBe(1)
        ->and(DB::connection('secondary')->table('documents')->value('status'))->toBe('a')
        ->and(DB::table('lifecycle_transitions')->count())->toBe(0);
});

function failSchedule(?string $connection, int $id): void
{
    DB::connection($connection)->table('lifecycle_schedules')->where('id', $id)->update([
        'status' => 'failed', 'pending_slot' => null, 'outcome' => 'denied', 'finished_at' => '2026-10-01 00:00:00',
    ]);
}

it('retries the schedule of the subject it was asked for, on the subject connection', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));
    $at = CarbonImmutable::now()->addDay();
    $other = Document::factory()->create();
    $theirs = Lifecycles::for($other)->asSystem()->schedule('go', $at);
    failSchedule(null, $theirs->id);
    $mine = SecondaryDocument::query()->create();
    $ours = Lifecycles::for($mine)->asSystem()->schedule('go', $at);
    failSchedule('secondary', $ours->id);

    expect($theirs->id)->toBe($ours->id)
        ->and(Lifecycles::for($mine)->retryScheduled('go'))->toBeTrue()
        ->and(DB::connection('secondary')->table('lifecycle_schedules')->where('id', $ours->id)->value('status'))->toBe('pending')
        ->and(DB::table('lifecycle_schedules')->where('id', $theirs->id)->value('status'))->toBe('failed')
        ->and(Lifecycles::schedules()->retry($ours->id, 'secondary'))->toBeFalse();
});

it('sweeps the schedules of another connection only when asked to', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem(), finish: fn (TransitionBuilder $f) => $f->allowSystem());
        $l->state('b')->ttl('10 days')->warnBefore('2 days')->expiresVia('finish');
    });
    $scheduled = SecondaryDocument::query()->create();
    Lifecycles::for($scheduled)->asSystem()->schedule('go', CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));
    $expiring = SecondaryDocument::query()->create();
    $expiring->transition('go');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-11 10:00:00', 'UTC'));

    expect(Lifecycles::sweep()->total())->toBe(0)
        ->and(Lifecycles::schedules()->due()->count())->toBe(0)
        ->and(Lifecycles::schedules()->due(connection: 'secondary')->count())->toBe(1);

    $result = Lifecycles::sweep(connection: 'secondary');

    expect($result->executed)->toBe(1)
        ->and($result->warned)->toBe(1)
        ->and(SecondaryDocument::query()->find($scheduled->id)?->status)->toBe('b');

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'));

    expect(Artisan::call('lifecycle:sweep', ['--database' => 'secondary']))->toBe(0)
        ->and(SecondaryDocument::query()->find($expiring->id)?->status)->toBe('c')
        ->and(Lifecycles::schedules()->failed(connection: 'secondary'))->toHaveCount(0);

    Carbon::setTestNow();
});

it('queues a sweep of another connection with the connection on the job', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    config()->set('queue.default', 'sync');
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));
    $document = SecondaryDocument::query()->create();
    Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));

    expect(Lifecycles::sweep(queue: true, connection: 'secondary')->queued)->toBe(1)
        ->and(SecondaryDocument::query()->find($document->id)?->status)->toBe('b');

    Carbon::setTestNow();
});

it('prunes the history of another connection only when asked to', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));
    SecondaryDocument::query()->create()->transition('go');
    Carbon::setTestNow(CarbonImmutable::now()->addDays(40));

    expect(Lifecycles::prune(new PruneOptions(30, 30))->historyDeleted)->toBe(0)
        ->and(Lifecycles::prune(new PruneOptions(30, 30, connection: 'secondary'))->historyDeleted)->toBe(2)
        ->and(DB::connection('secondary')->table('lifecycle_transitions')->count())->toBe(0);

    Carbon::setTestNow();
});
