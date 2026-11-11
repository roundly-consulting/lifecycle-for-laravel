<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RoundlyConsulting\Lifecycle\Bridges\Teams\TeamsSubjectContext;
use RoundlyConsulting\Lifecycle\Contracts\RestoresSubjectContext;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Events\LifecycleExpiring;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectContextUnavailableException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Teams\Fixtures\TeamTicket;
use RoundlyConsulting\Lifecycle\Tests\Teams\Fixtures\TeamTicketLifecycle;
use RoundlyConsulting\Teams\DataTransferObjects\CreateTeamData;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    TeamTicketLifecycle::$seen = [];
    TeamTicketLifecycle::$escalations = true;
});

afterEach(fn () => Carbon::setTestNow());

function tenant(string $name): Team
{
    return Teams::create(new CreateTeamData(name: $name));
}

/**
 * A ticket of `$team`, moved through `$transitions` inside that team (as a request would).
 */
function tenantTicket(Team $team, string ...$transitions): TeamTicket
{
    return Teams::runAs($team, static function () use ($transitions): TeamTicket {
        $ticket = TeamTicket::query()->create([]);

        foreach ($transitions as $transition) {
            $ticket->transition($transition);
        }

        return $ticket;
    });
}

function tenantStatus(TeamTicket $ticket): ?string
{
    return TeamTicket::withoutTeamScope()->findOrFail($ticket->id)->status;
}

function tenantAt(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
}

it('binds the teams subject context', function (): void {
    expect(app(RestoresSubjectContext::class))->toBeInstanceOf(TeamsSubjectContext::class);

    $this->artisan('about', ['--only' => 'lifecycle'])->expectsOutputToContain('teams')->assertSuccessful();
});

it('closes the resolved tickets of two teams, each as its own team, from a console sweep', function (): void {
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $a = tenantTicket($acme, 'open', 'resolve');
    $b = tenantTicket($globex, 'open', 'resolve');

    tenantAt('2026-10-09 10:00:00');
    $result = Lifecycles::schedules()->runDue();

    expect($result->executed)->toBe(2)
        ->and($result->errored)->toBe(0)
        ->and(tenantStatus($a))->toBe('closed')
        ->and(tenantStatus($b))->toBe('closed')
        ->and(TeamTicketLifecycle::$seen)->toEqualCanonicalizing([
            ['closed', $a->id, $acme->getKey()],
            ['job:closed', $a->id, $acme->getKey()],
            ['closed', $b->id, $globex->getKey()],
            ['job:closed', $b->id, $globex->getKey()],
        ])
        // The console's own context is put back: none.
        ->and(Teams::current())->toBeNull();
});

it('runs each subject as its own team even when the sweep runs inside another team', function (): void {
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $a = tenantTicket($acme, 'open', 'resolve');

    tenantAt('2026-10-09 10:00:00');
    $result = Teams::runAs($globex, function () use ($globex) {
        $result = Lifecycles::sweep();

        // Restored after every schedule, so nothing leaks back into the caller.
        expect(Teams::current()?->getKey())->toBe($globex->getKey());

        return $result;
    });

    expect($result->executed)->toBe(1)
        ->and(tenantStatus($a))->toBe('closed')
        ->and(TeamTicketLifecycle::$seen[0])->toBe(['closed', $a->id, $acme->getKey()])
        ->and(Teams::current())->toBeNull();
});

it('runs queued schedules as the subject team and hands that team to the jobs they dispatch', function (): void {
    config()->set('queue.default', 'database');
    config()->set('lifecycle.schedules.queue.enabled', true);
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $a = tenantTicket($acme, 'open', 'resolve');
    $b = tenantTicket($globex, 'open', 'resolve');

    tenantAt('2026-10-09 10:00:00');

    expect(Lifecycles::sweep()->queued)->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(2);

    // A worker: no team context of its own. Its memory ceiling is the whole test process's (the
    // default 128 MB stops it before the first job when the suite runs in one process).
    Teams::setCurrent(null);
    $worked = Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);

    expect($worked)->toBe(0);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(tenantStatus($a))->toBe('closed')
        ->and(tenantStatus($b))->toBe('closed')
        ->and(TeamTicketLifecycle::$seen)->toEqualCanonicalizing([
            ['closed', $a->id, $acme->getKey()],
            ['job:closed', $a->id, $acme->getKey()],
            ['closed', $b->id, $globex->getKey()],
            ['job:closed', $b->id, $globex->getKey()],
        ]);
});

it('skips a schedule whose team was deleted, reports it once in the sweep table and runs it once the team is back', function (): void {
    Exceptions::fake();
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $a = tenantTicket($acme, 'open', 'resolve');
    $b = tenantTicket($globex, 'open', 'resolve');
    $acme->delete();

    tenantAt('2026-10-09 10:00:00');
    expect(Artisan::call('lifecycle:sweep'))->toBe(0);
    $output = Artisan::output();
    $row = LifecycleSchedule::query()->where('subject_id', $a->id)->where('pending_slot', '@expiry')->sole();

    expect($output)->toContain('context missing')
        ->and($output)->toMatch('/\|\s+0\s+\|\s+1\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+1\s+\|/')
        ->and($output)->toContain('1 schedule(s) put off')
        ->and(tenantStatus($a))->toBe('resolved')
        ->and(tenantStatus($b))->toBe('closed')
        ->and($row->status)->toBe(ScheduleStatus::Pending)
        ->and($row->attempts)->toBe(0)
        ->and($row->last_denial)->toBe('context_missing');

    // Not an error loop: the next sweeps leave it alone until retry_after.
    expect(Lifecycles::sweep()->total())->toBe(0);
    Exceptions::assertNothingReported();

    Team::withTrashed()->findOrFail($acme->getKey())->restore();
    tenantAt('2026-10-09 10:05:00');

    expect(Lifecycles::sweep()->executed)->toBe(1)
        ->and(tenantStatus($a))->toBe('closed')
        ->and(TeamTicketLifecycle::$seen[2] ?? null)->toBe(['closed', $a->id, $acme->getKey()]);
});

it('skips a subject whose stored team does not exist', function (mixed $team): void {
    Exceptions::fake();
    $a = tenantTicket(tenant('Acme'), 'open', 'resolve');
    DB::table('team_tickets')->where('id', $a->id)->update(['team_id' => $team]);

    tenantAt('2026-10-09 10:00:00');
    $result = Lifecycles::sweep();

    expect($result->contextMissing)->toBe(1)
        ->and($result->errored)->toBe(0)
        ->and(tenantStatus($a))->toBe('resolved');

    Exceptions::assertNothingReported();
})->with([
    'a team id that was never stored' => [999999],
    'no team at all' => [null],
]);

it('fires expiry warnings as the subject team and puts off those of a deleted team', function (): void {
    Exceptions::fake();
    $seen = [];
    Event::listen(LifecycleExpiring::class, function (LifecycleExpiring $event) use (&$seen): void {
        $seen[] = [$event->subject->getKey(), Teams::current()?->getKey()];
    });
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $initech = tenant('Initech');
    $a = tenantTicket($acme, 'open', 'resolve');
    $b = tenantTicket($globex, 'open', 'resolve');
    $c = tenantTicket($initech, 'open', 'resolve');
    $initech->delete();

    tenantAt('2026-10-08 10:00:00');

    expect(Lifecycles::schedules()->warn())->toBe(2)
        ->and($seen)->toEqualCanonicalizing([[$a->id, $acme->getKey()], [$b->id, $globex->getKey()]])
        ->and(LifecycleSchedule::query()->where('subject_id', $c->id)->sole()->next_warn_at?->toDateTimeString())->toBe('2026-10-08 10:05:00')
        ->and(Teams::current())->toBeNull();

    Exceptions::assertNothingReported();
});

it('retries a failed schedule from any context and runs it as the subject team', function (): void {
    TeamTicketLifecycle::$escalations = false;
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $initech = tenant('Initech');
    $a = tenantTicket($acme, 'open');
    $c = tenantTicket($initech, 'open');
    $ids = [];

    foreach ([[$acme, $a], [$initech, $c]] as [$team, $ticket]) {
        $ids[] = Teams::runAs($team, fn () => Lifecycles::for($ticket)->asSystem()->schedule('escalate', CarbonImmutable::now())->id);
    }

    expect(Lifecycles::sweep()->failed)->toBe(2);

    TeamTicketLifecycle::$escalations = true;
    $initech->delete();

    // An admin acting from another team's request.
    [$retried, $gone] = Teams::runAs($globex, fn (): array => [
        Lifecycles::schedules()->retry($ids[0]),
        Lifecycles::schedules()->retry($ids[1]),
    ]);

    expect($retried)->toBeTrue()
        ->and($gone)->toBeFalse()
        ->and(LifecycleSchedule::query()->findOrFail($ids[1])->status)->toBe(ScheduleStatus::Failed)
        ->and(Lifecycles::sweep()->executed)->toBe(1)
        ->and(tenantStatus($a))->toBe('escalated')
        ->and(TeamTicketLifecycle::$seen[0])->toBe(['escalated', $a->id, $acme->getKey()]);
});

it('adopts every row as its own team and lists the rows of a deleted team', function (): void {
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $initech = tenant('Initech');
    $rows = [];

    foreach ([$acme, $globex, $initech] as $team) {
        $rows[] = (int) DB::table('team_tickets')->insertGetId(['team_id' => $team->getKey(), 'status' => null]);
    }

    $initech->delete();

    expect(Artisan::call('lifecycle:adopt', ['model' => TeamTicket::class]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('Adopted 2 subject(s).')
        ->and($output)->toContain('Skipped 1 subject(s)')
        ->and($output)->toContain('#'.$rows[2].'] belongs to team ['.$initech->getKey().']')
        ->and(DB::table('team_tickets')->orderBy('id')->pluck('status')->all())->toBe(['new', 'new', null])
        ->and(Teams::current())->toBeNull();
});

it('throws for a scoped subject whose team is gone, without calling the callback', function (): void {
    $acme = tenant('Acme');
    $a = tenantTicket($acme);
    $acme->delete();
    $called = false;

    expect(fn () => app(TeamsSubjectContext::class)->run($a, function () use (&$called): void {
        $called = true;
    }))->toThrow(SubjectContextUnavailableException::class, 'belongs to team ['.$acme->getKey().']')
        ->and($called)->toBeFalse();
});

it('restores the previous context when the callback throws', function (): void {
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    $a = tenantTicket($acme);

    Teams::runAs($globex, function () use ($a, $acme, $globex): void {
        expect(fn () => app(TeamsSubjectContext::class)->run($a, function () use ($acme): never {
            expect(Teams::current()?->getKey())->toBe($acme->getKey());

            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class, 'boom')
            ->and(Teams::current()?->getKey())->toBe($globex->getKey());
    });
});

it('runs a subject that is not team-scoped as it is', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    tenantAt('2026-11-04 10:00:00');

    expect(app(TeamsSubjectContext::class)->run($listing, fn (): ?Team => Teams::current()))->toBeNull()
        ->and(Lifecycles::sweep()->executed)->toBe(1)
        ->and(Lifecycles::for($listing->refresh())->state()->value)->toBe('expired');
});
