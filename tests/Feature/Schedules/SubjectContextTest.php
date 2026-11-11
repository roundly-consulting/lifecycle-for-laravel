<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RoundlyConsulting\Lifecycle\Accessors\DefinitionsAccessor;
use RoundlyConsulting\Lifecycle\Bridges\Teams\TeamsSubjectContext;
use RoundlyConsulting\Lifecycle\Contracts\RestoresSubjectContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\ScheduleStatus;
use RoundlyConsulting\Lifecycle\Events\LifecycleExpired;
use RoundlyConsulting\Lifecycle\Events\LifecycleExpiring;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectContextUnavailableException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleSchedule;
use RoundlyConsulting\Lifecycle\Support\Bridges;
use RoundlyConsulting\Lifecycle\Support\NullSubjectContext;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Context\SpySubjectContext;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
    SpySubjectContext::reset();
    config()->set('lifecycle.subjects.context', SpySubjectContext::class);
});

afterEach(fn () => Carbon::setTestNow());

function contextNow(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
}

function publishedListing(): Listing
{
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    return $listing;
}

function contextExpiryRow(Listing $listing): LifecycleSchedule
{
    return LifecycleSchedule::query()->where('subject_id', $listing->id)->where('pending_slot', '@expiry')->sole();
}

// The no-teams CI leg runs this with teams-for-laravel removed: the default is then no context.
it('defaults to the teams bridge when teams is installed, else to no context', function (): void {
    config()->set('lifecycle.subjects.context', null);

    expect(app(RestoresSubjectContext::class))->toBeInstanceOf(Bridges::available('teams') ? TeamsSubjectContext::class : NullSubjectContext::class)
        ->and(Bridges::available('nope'))->toBeFalse();
});

it('runs as it is with no subject context', function (): void {
    config()->set('lifecycle.subjects.context', NullSubjectContext::class);

    expect(app(RestoresSubjectContext::class))->toBeInstanceOf(NullSubjectContext::class)
        ->and((new NullSubjectContext)->run(new Listing, fn (): string => 'ran'))->toBe('ran');

    $listing = publishedListing();
    contextNow('2026-11-04 10:00:00');

    expect(Lifecycles::sweep()->executed)->toBe(1)
        ->and(Lifecycles::for($listing->refresh())->state()->value)->toBe('expired');
});

it('uses the configured subject context', function (): void {
    expect(app(RestoresSubjectContext::class))->toBeInstanceOf(SpySubjectContext::class);

    $this->artisan('about', ['--only' => 'lifecycle'])->expectsOutputToContain('SpySubjectContext')->assertSuccessful();

    config()->set('lifecycle.subjects.context', NullSubjectContext::class);
    $this->artisan('about', ['--only' => 'lifecycle'])->expectsOutputToContain('none')->assertSuccessful();
});

it('refuses a subject context that is not an instantiable RestoresSubjectContext', function (mixed $value): void {
    config()->set('lifecycle.subjects.context', $value);

    expect(fn () => app(RestoresSubjectContext::class))->toThrow(InvalidLifecycleConfigurationException::class, 'lifecycle.subjects.context');
})->with([
    'a foreign class' => [stdClass::class],
    'a missing class' => ['App\\Nope\\Context'],
    'the contract itself' => [RestoresSubjectContext::class],
    'not a string' => [42],
    'a list' => [[SpySubjectContext::class]],
]);

it('treats the context key of lifecycle.subjects as a setting, not a model', function (): void {
    config()->set('lifecycle.subjects', [Listing::class, Document::class, 'context' => SpySubjectContext::class]);

    expect(app(DefinitionsAccessor::class)->subjects())->toBe([Listing::class, Document::class])
        ->and(app(RestoresSubjectContext::class))->toBeInstanceOf(SpySubjectContext::class);
});

it('fires expiry warnings and runs due expiries inside the subject context', function (): void {
    $inside = [];
    Event::listen(LifecycleExpiring::class, function () use (&$inside): void {
        $inside[] = ['expiring', SpySubjectContext::$inside];
    });
    Event::listen(LifecycleExpired::class, function () use (&$inside): void {
        $inside[] = ['expired', SpySubjectContext::$inside];
    });
    $listing = publishedListing();

    contextNow('2026-10-25 10:00:00');
    expect(Lifecycles::sweep()->warned)->toBe(1);

    contextNow('2026-11-04 10:00:00');
    expect(Lifecycles::sweep()->executed)->toBe(1)
        ->and($inside)->toBe([['expiring', true], ['expired', true]])
        ->and(SpySubjectContext::$ran)->toBe([$listing->getMorphClass().'#'.$listing->id, $listing->getMorphClass().'#'.$listing->id]);
});

it('puts off a due schedule whose context is gone, without an error or an attempt', function (): void {
    Exceptions::fake();
    $gone = publishedListing();
    $kept = publishedListing();
    SpySubjectContext::$unavailable = [$gone->id];

    contextNow('2026-11-04 10:00:00');
    $result = Lifecycles::schedules()->runDue();
    $row = contextExpiryRow($gone);

    expect($result->executed)->toBe(1)
        ->and($result->contextMissing)->toBe(1)
        ->and($result->errored)->toBe(0)
        ->and($result->total())->toBe(2)
        ->and($row->status)->toBe(ScheduleStatus::Pending)
        ->and($row->attempts)->toBe(0)
        ->and($row->last_denial)->toBe('context_missing')
        ->and($row->due_at->toDateTimeString())->toBe('2026-11-04 10:05:00')
        ->and(Lifecycles::for($gone->refresh())->state()->value)->toBe('active')
        ->and(Lifecycles::for($kept->refresh())->state()->value)->toBe('expired');

    // Not due again until retry_after: the next sweep does not touch it.
    expect(Lifecycles::schedules()->runDue()->contextMissing)->toBe(0);

    // The tenant is back: it runs.
    SpySubjectContext::$unavailable = [];
    contextNow('2026-11-04 10:05:00');

    expect(Lifecycles::schedules()->runDue()->executed)->toBe(1)
        ->and(Lifecycles::for($gone->refresh())->state()->value)->toBe('expired');

    Exceptions::assertNothingReported();
});

it('shows the schedules it put off in the sweep table', function (): void {
    $gone = publishedListing();
    SpySubjectContext::$unavailable = [$gone->id];
    contextNow('2026-11-04 10:00:00');

    expect(Artisan::call('lifecycle:sweep', ['--no-warnings' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('context missing')
        ->and($output)->toMatch('/\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+1\s+\|/')
        ->and($output)->toContain("1 schedule(s) put off: their subject's tenant context could not be restored");
});

it('puts off an expiry warning whose context is gone, unreported', function (): void {
    Exceptions::fake();
    $gone = publishedListing();
    SpySubjectContext::$unavailable = [$gone->id];

    contextNow('2026-10-25 10:00:00');

    expect(Lifecycles::schedules()->warn())->toBe(0)
        ->and(contextExpiryRow($gone)->next_warn_at?->toDateTimeString())->toBe('2026-10-25 10:05:00')
        ->and(contextExpiryRow($gone)->warnings_sent)->toBe(0);

    Exceptions::assertNothingReported();

    SpySubjectContext::$unavailable = [];
    contextNow('2026-10-25 10:05:00');

    expect(Lifecycles::schedules()->warn())->toBe(1);
});

it('retries a failed schedule inside the subject context, and not at all when it is gone', function (): void {
    $allow = false;
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->allowSystem()
        ->when(function () use (&$allow): bool {
            return $allow;
        }, 'off')));

    $documents = [Document::factory()->create(), Document::factory()->create()];
    $ids = [];

    foreach ($documents as $document) {
        $ids[] = Lifecycles::for($document)->asSystem()->schedule('go', CarbonImmutable::now())->id;
    }

    expect(Lifecycles::sweep()->failed)->toBe(2);

    SpySubjectContext::reset();
    SpySubjectContext::$unavailable = [$documents[1]->id];
    $allow = true;

    expect(Lifecycles::schedules()->retry($ids[0]))->toBeTrue()
        ->and(Lifecycles::schedules()->retry($ids[1]))->toBeFalse()
        ->and(SpySubjectContext::$ran)->toBe([$documents[0]->getMorphClass().'#'.$documents[0]->id])
        ->and(LifecycleSchedule::query()->findOrFail($ids[1])->status)->toBe(ScheduleStatus::Failed);
});

it('adopts every row inside its own context and hands over the ones it skipped', function (): void {
    $rows = [];

    foreach (range(1, 3) as $i) {
        $rows[] = (int) DB::table('listings')->insertGetId(['title' => 'row '.$i, 'status' => null]);
    }

    SpySubjectContext::$unavailable = [$rows[1]];
    $skipped = [];

    $changed = Lifecycles::adoptAll(Listing::class, skipped: function (Model $subject, SubjectContextUnavailableException $exception) use (&$skipped): void {
        $skipped[] = [$subject->getKey(), $exception->getMessage()];
    });

    expect($changed)->toBe(2)
        ->and(SpySubjectContext::$ran)->toHaveCount(2)
        ->and($skipped)->toHaveCount(1)
        ->and($skipped[0][0])->toBe($rows[1])
        ->and($skipped[0][1])->toContain('its tenant context cannot be restored')
        ->and(DB::table('listings')->orderBy('id')->pluck('status')->all())->toBe(['draft', null, 'draft']);
});

it('lists the rows lifecycle:adopt skipped', function (): void {
    $kept = (int) DB::table('listings')->insertGetId(['title' => 'kept', 'status' => null]);
    $gone = (int) DB::table('listings')->insertGetId(['title' => 'gone', 'status' => null]);
    SpySubjectContext::$unavailable = [$gone];

    expect(Artisan::call('lifecycle:adopt', ['model' => Listing::class]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('Adopted 1 subject(s).')
        ->and($output)->toContain('Skipped: The ['.Listing::class.' #'.$gone.'] belongs to team [404]')
        ->and($output)->toContain('Skipped 1 subject(s) whose tenant context could not be restored')
        ->and(DB::table('listings')->where('id', $kept)->value('status'))->toBe('draft')
        ->and(DB::table('listings')->where('id', $gone)->value('status'))->toBeNull();
});
