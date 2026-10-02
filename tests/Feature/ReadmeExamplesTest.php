<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\Lifecycle\Actions\ApplyTransitionAction;
use RoundlyConsulting\Lifecycle\Contracts\CompensatesTransition;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\Contracts\TransitionHandler;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Http\Resources\LifecycleResource;
use RoundlyConsulting\Lifecycle\Http\Resources\TransitionRecordResource;
use RoundlyConsulting\Lifecycle\Jobs\RunScheduledTransitionJob;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\Rules\ValidState;
use RoundlyConsulting\Lifecycle\Rules\ValidTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Readme\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Readme\ListingLifecycle;

/**
 * The README's snippets, run as written (host-specific collaborators replaced by small
 * stand-ins), so the documentation cannot rot.
 */
beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC'));
    Gate::define('publish', fn (User $user, Listing $listing): bool => $listing->user_id === $user->id);
});

afterEach(fn () => Carbon::setTestNow());

function readmeListing(User $user, ListingStatus $status = ListingStatus::Draft): Listing
{
    $listing = Listing::query()->create(['user_id' => $user->id]);

    if ($status !== ListingStatus::Draft) {
        Lifecycles::for($listing)->by($user)->apply('publish');
    }

    if ($status === ListingStatus::Closed) {
        Lifecycles::for($listing)->apply('close');
    }

    return $listing;
}

it('runs the quick start', function (): void {
    $user = User::factory()->create();

    $listing = Listing::query()->create(['user_id' => $user->id]);  // status = draft

    expect($listing->status)->toBe(ListingStatus::Draft);

    Lifecycles::for($listing)->by($user)->apply('publish');

    expect($listing->status)->toBe(ListingStatus::Active)
        ->and($listing->published_at?->getTimestamp())->toBe(CarbonImmutable::now()->getTimestamp())
        ->and(Lifecycles::for($listing)->expiresAt()?->toIso8601String())->toBe('2026-11-01T08:00:00+00:00');

    $listing->status = ListingStatus::Closed;

    expect(fn () => $listing->save())->toThrow(DirectStateWriteException::class);
});

it('runs the subject and transition snippets', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create();

    Lifecycles::for($order)->apply('pay');                         // the first lifecycle (status)
    Lifecycles::for($order, 'payment_status')->apply('authorize'); // another one

    $listing = readmeListing($user, ListingStatus::Active);
    Lifecycles::for($listing)->transitionTo(ListingStatus::Closed);

    Lifecycles::for($listing)
        ->by($user)
        ->because('Back in stock')
        ->with(['note' => 'Restocked'])
        ->apply('reopen');
    Lifecycles::for($listing)->asSystem()->apply('expire');
    $listing->transition('reactivate');

    $listing->transition('close', ['note' => 'Sold elsewhere']);   // name, payload, lifecycle
    $canReopen = $listing->canTransition('reopen');                // in its cooldown

    Carbon::setTestNow(CarbonImmutable::now()->addHours(2));
    $listing->lifecycle()->by($user)->because('Back in stock')->apply('reopen');
    $listing->transitionTo(ListingStatus::Archived);

    $attempt = Lifecycles::for($listing)->by($user)->attempt('close');

    expect($order->fresh()?->payment_status)->toBe('authorized')
        ->and($canReopen)->toBeFalse()
        ->and($listing->status)->toBe(ListingStatus::Archived)
        ->and($attempt->succeeded)->toBeFalse()
        ->and($attempt->decision->messages())->toBe(['The current state "Archived" is final.']);
});

it('runs the version and idempotency snippets', function (): void {
    $listing = readmeListing(User::factory()->create(), ListingStatus::Active);
    $order = Order::factory()->create();
    $event = (object) ['id' => 'evt_1'];

    $version = Lifecycles::for($listing)->version();
    Lifecycles::for($listing)->expectingVersion($version)->apply('close');

    $first = Lifecycles::for($order)->idempotencyKey("stripe:{$event->id}")->apply('pay');
    $result = Lifecycles::for($order)->idempotencyKey("stripe:{$event->id}")->apply('pay');

    expect($version)->toBe(2)
        ->and(fn () => Lifecycles::for($listing)->expectingVersion($version)->apply('reopen'))
        ->toThrow(TransitionDeniedException::class)
        ->and($first->replayed)->toBeFalse()
        ->and($result->replayed)->toBeTrue();
});

it('runs the asking-before-acting snippets', function (): void {
    $user = User::factory()->create();
    $listing = readmeListing($user, ListingStatus::Closed);
    Lifecycles::for($listing)->by($user)->because('1')->apply('reopen');
    Lifecycles::for($listing)->apply('close');

    $decision = Lifecycles::for($listing)->by($user)->check('reopen');
    $transitions = Lifecycles::for($listing)->by($user)->allowedTransitions(includeDenied: true);

    expect(Lifecycles::for($listing)->by($user)->can('archive'))->toBeTrue()
        ->and(Lifecycles::for($listing)->by($user)->canTransitionTo(ListingStatus::Active))->toBeFalse()
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->codes())->toBe(['cooldown_active'])
        ->and($decision->retryAfter?->toIso8601String())->toBe('2026-10-02T09:00:00+00:00')
        ->and(array_map(fn ($transition) => [$transition->name, $transition->allowed, $transition->requiresReason], $transitions))
        ->toBe([['reopen', false, true], ['archive', true, false]])
        ->and($transitions[0]->availableAt?->toIso8601String())->toBe('2026-10-02T09:00:00+00:00')
        ->and(Lifecycles::for($listing)->allowedStates())->toBe([ListingStatus::Archived]);
});

it('compiles the restriction, limit, handler and expiry definitions', function (): void {
    $definition = compileLifecycle(function (LifecycleBuilder $lifecycle): void {
        $lifecycle->states(['pending', 'approved'])->initial('pending');

        $lifecycle->transition('approve')
            ->from('pending')->to('approved')
            ->ability('approve')
            ->actors(User::class)
            ->requiresReason(10)
            ->rules(['note' => 'required|string|max:500'], ['note.required' => 'Say why.'])
            ->when(fn (TransitionContext $context): bool => $context->subject->exists, code: 'no_photos')
            ->when(ReadmeEnsureInvoicePaid::class)
            ->notAfter('deadline_at');
    });

    $limits = compileLifecycle(function (LifecycleBuilder $lifecycle): void {
        $lifecycle->states(ListingStatus::class)->initial(ListingStatus::Draft)->terminal(ListingStatus::Archived);
        $lifecycle->transition('publish')->from(ListingStatus::Draft)->to(ListingStatus::Active);

        $lifecycle->state(ListingStatus::Active)
            ->quota(fn (Listing $listing): int => 5, scope: 'user_id')
            ->minDwell('1 hour')
            ->sealedAfter('90 days');

        $lifecycle->transition('close')->from(ListingStatus::Active)->to(ListingStatus::Closed)->ignoresSeal();
        $lifecycle->transition('reopen')
            ->from(ListingStatus::Closed)->to(ListingStatus::Active)
            ->maxOccurrences(3)
            ->cooldown('24 hours')
            ->rateLimit(10, '1 hour', RateLimitScope::Actor);

        $lifecycle->state(ListingStatus::Active)
            ->ttl('30 days')
            ->grace('3 days')
            ->warnBefore('7 days', '1 day')
            ->expiresVia('expire');
        $lifecycle->transition('expire')->from(ListingStatus::Active)->to(ListingStatus::Expired)->systemOnly()->ignoresSeal();
        $lifecycle->transition('archive')->from('*')->to(ListingStatus::Archived)->ignoresSeal();
    });

    $handlers = compileLifecycle(function (LifecycleBuilder $lifecycle): void {
        $lifecycle->states(['paid', 'fulfilled'])->initial('paid');
        $lifecycle->state('paid')->stamps('paid_at')->onEnter(fn () => null);
        $lifecycle->transition('fulfil')
            ->from('paid')->to('fulfilled')
            ->handledBy(ReadmeReserveStock::class)
            ->snapshots('stock_note')
            ->reversible(within: '1 hour');
    });

    expect($definition->transition('approve')?->guards)->toHaveCount(2)
        ->and($limits->state('active')->quotas)->toHaveCount(1)
        ->and($handlers->transition('fulfil')?->reversibility->withoutCompensation)->toBeFalse();
});

it('runs the guard example', function (): void {
    $guard = new ReadmeEnsureInvoicePaid(new ReadmeBilling(paid: false));
    $context = new TransitionContext(
        subject: new Listing,
        lifecycle: 'status',
        transition: app(LifecycleManager::class)->definitions()->get(ListingLifecycle::class)->transition('close') ?? throw new LogicException,
        from: ListingStatus::Active,
        to: ListingStatus::Closed,
        actor: null,
        system: false,
        reason: null,
        payload: [],
        now: CarbonImmutable::now(),
        version: 1,
    );

    expect($guard->check($context)?->code)->toBe('invoice_unpaid')
        ->and($guard->check($context)?->isRetryable())->toBeTrue()
        ->and((new ReadmeEnsureInvoicePaid(new ReadmeBilling(paid: true)))->check($context))->toBeNull();
});

it('runs the expiry snippets', function (): void {
    $listing = readmeListing(User::factory()->create(), ListingStatus::Active);
    $handle = Lifecycles::for($listing);

    expect($handle->expiresAt()?->toIso8601String())->toBe('2026-11-01T08:00:00+00:00')
        ->and($handle->isExpired())->toBeFalse()
        ->and($handle->isInGrace())->toBeFalse()
        ->and($handle->isExpiringWithin('3 days'))->toBeFalse()
        ->and($handle->effectiveState())->toBe(ListingStatus::Active)
        ->and($handle->state())->toBe(ListingStatus::Active)
        ->and($handle->extend('7 days')->toIso8601String())->toBe('2026-11-08T08:00:00+00:00')
        ->and($handle->renew()->toIso8601String())->toBe('2026-11-01T08:00:00+00:00')
        ->and($handle->expireAt(now()->addWeek())->toIso8601String())->toBe('2026-10-09T08:00:00+00:00')
        ->and($handle->neverExpire())->toBeTrue()
        ->and($handle->expiresAt())->toBeNull();
});

it('runs the schedule and sweep snippets', function (): void {
    $editor = User::factory()->create();
    $listing = Listing::query()->create(['user_id' => $editor->id]);
    $publishAt = CarbonImmutable::now()->addDay();

    $scheduled = Lifecycles::for($listing)->by($editor)->because('Launch')->schedule('publish', $publishAt);

    expect(Lifecycles::for($listing)->scheduled())->toHaveCount(1)
        ->and(Lifecycles::for($listing)->cancelScheduled('publish'))->toBeTrue();

    Lifecycles::for($listing)->by($editor)->schedule('publish', $publishAt);
    Carbon::setTestNow($publishAt);

    expect(Lifecycles::schedules()->due())->toHaveCount(1)
        ->and(Lifecycles::schedules()->warn())->toBe(0)
        ->and(Lifecycles::sweep()->executed)->toBe(1)
        ->and($listing->fresh()?->status)->toBe(ListingStatus::Active)
        ->and(Lifecycles::schedules()->runDue()->total())->toBe(0)
        ->and(Lifecycles::schedules()->retry($scheduled->id))->toBeFalse();

    Bus::fake();
    Lifecycles::for($listing)->by($editor)->schedule('close', CarbonImmutable::now());
})->throws(TransitionDeniedException::class, 'The system cannot perform "Close".');

it('queues the sweep', function (): void {
    Bus::fake();
    $listing = Listing::query()->create(['user_id' => User::factory()->create()->id]);
    Lifecycles::for($listing)->asSystem()->schedule('publish', CarbonImmutable::now());

    expect(Lifecycles::sweep(limit: 100, queue: true)->queued)->toBe(1);

    Bus::assertDispatched(RunScheduledTransitionJob::class);
});

it('runs the freeze snippets', function (): void {
    $moderator = User::factory()->create();
    $listing = readmeListing(User::factory()->create(), ListingStatus::Active);

    Lifecycles::for($listing)->by($moderator)->because('Under review')->freeze(until: now()->addDays(3));

    expect(Lifecycles::for($listing)->isFrozen())->toBeTrue()
        ->and(Lifecycles::for($listing)->frozenReason())->toBe('Under review')
        ->and(Lifecycles::for($listing)->unfreeze())->toBeTrue();
});

it('runs the rollback and history snippets', function (): void {
    $user = User::factory()->create();
    $listing = readmeListing($user, ListingStatus::Active);
    $record = Lifecycles::for($listing)->lastTransition();
    Lifecycles::for($listing)->apply('close');

    Lifecycles::for($listing)->by($user)->rollback();
    Lifecycles::for($listing)->apply('close');

    expect(Lifecycles::for($listing)->canRollback()->allowed)->toBeTrue()
        ->and(Lifecycles::for($listing)->rollback(force: true)->to)->toBe(ListingStatus::Active);

    Lifecycles::for($listing)->apply('close');
    Lifecycles::for($listing)->rollbackTo($record ?? throw new LogicException);

    expect($listing->status)->toBe(ListingStatus::Active)
        ->and(Lifecycles::for($listing)->history(20))->toHaveCount(8)
        ->and(Lifecycles::for($listing)->lastTransition()?->kind->value)->toBe('rollback')
        ->and(Lifecycles::prune(new PruneOptions(historyOlderThanDays: 365, schedulesOlderThanDays: 30))->historyDeleted)->toBe(0);
});

it('runs the query scopes', function (): void {
    $user = User::factory()->create();
    readmeListing($user, ListingStatus::Active);
    readmeListing($user, ListingStatus::Closed);
    readmeListing($user);

    expect(Listing::query()->whereState(ListingStatus::Active)->count())->toBe(1)
        ->and(Listing::query()->whereState([ListingStatus::Active, ListingStatus::Closed])->count())->toBe(2)
        ->and(Listing::query()->whereNotState(ListingStatus::Archived)->count())->toBe(3)
        ->and(Listing::query()->whereExpiringWithin('3 days')->count())->toBe(0)
        ->and(Listing::query()->whereExpired()->count())->toBe(0)
        ->and(Listing::query()->whereNotExpired()->count())->toBe(3)
        ->and(Listing::query()->whereInGrace()->count())->toBe(0)
        ->and(Listing::query()->whereFrozen()->count())->toBe(0)
        ->and(Listing::query()->whereInStateFor('14 days')->count())->toBe(0)
        ->and(Listing::query()->withLifecycle()->paginate()->total())->toBe(3)
        ->and(Order::query()->whereState('captured', 'payment_status')->count())->toBe(0);
});

it('runs the strict-write snippets', function (): void {
    $listing = readmeListing(User::factory()->create(), ListingStatus::Active);

    Lifecycles::allowDirectWrites(fn () => $listing->update(['status' => ListingStatus::Closed]));  // adopted at once

    expect(Lifecycles::for($listing)->lastTransition()?->kind->value)->toBe('adopted')
        ->and(Lifecycles::for($listing)->adopt())->toBeFalse()
        ->and(Lifecycles::model(Listing::class)->adopt(chunk: 500))->toBe(0);
});

it('renders the README graph and runs the definition helpers', function (): void {
    $readme = (string) file_get_contents(__DIR__.'/../../README.md');
    preg_match('/```mermaid\n(.*?)```/s', $readme, $graph);

    $model = Lifecycles::model(Listing::class);

    expect($model->graph())->toBe($graph[1] ?? '')
        ->and($model->states())->toHaveCount(5)
        ->and($model->initial())->toBe(ListingStatus::Draft)
        ->and($model->terminal())->toBe([ListingStatus::Archived])
        ->and($model->transitions())->toHaveCount(6)
        ->and(Lifecycles::definitions()->get(ListingLifecycle::class)->class)->toBe(ListingLifecycle::class)
        ->and(Lifecycles::definitions()->of(new Listing)->class)->toBe(ListingLifecycle::class)
        ->and(Lifecycles::definitions()->validate(ListingLifecycle::class)->isValid())->toBeTrue()
        ->and(Lifecycles::definitions()->graph(ListingLifecycle::class, GraphFormat::Dot))->toStartWith('digraph')
        ->and(Lifecycles::definitions()->registered())->toBe([]);
});

it('runs the resource, validation and cast snippets', function (): void {
    $user = User::factory()->create();
    $listing = readmeListing($user, ListingStatus::Closed);
    $request = Request::create('/', 'POST', ['transition' => 'reopen', 'status' => 'active']);
    $request->setUserResolver(fn () => $user);

    $resource = LifecycleResource::make(Lifecycles::for($listing)->by($request->user()))->resolve();
    $history = TransitionRecordResource::collection(Lifecycles::for($listing)->history())->resolve();
    $record = Lifecycles::for($listing)->lastTransition() ?? throw new LogicException;

    $errors = Validator::make($request->all(), [
        'transition' => ['required', ValidTransition::for($listing)->by($request->user())],
        'status' => ['nullable', ValidState::of(Listing::class)],
    ])->errors()->all();

    try {
        Lifecycles::for($listing)->by($request->user())->with($request->all())->apply('reopen');
    } catch (TransitionDeniedException $e) {
        $exception = $e->toValidationException();
    }

    expect($resource['state'])->toBe('closed')
        ->and(array_column($resource['allowed_transitions'], 'requires_reason'))->toBe([true, false])
        ->and($history)->toHaveCount(3)
        ->and((new TransitionRecordResource($record))->withContext()->resolve())->toHaveKey('context')
        ->and($errors)->toBe([])
        ->and(Validator::make(['transition' => 'active'], ['transition' => [ValidTransition::for($listing)->toState()]])->passes())->toBeTrue()
        ->and(($exception ?? null)?->errors())->toBe(['transition' => ['A reason is required.']]);
});

it('runs the without-the-facade snippet', function (): void {
    $user = User::factory()->create();
    $listing = readmeListing($user, ListingStatus::Closed);

    $reopen = new class(app(LifecycleManager::class))
    {
        public function __construct(private readonly LifecycleManager $lifecycle) {}

        public function __invoke(Listing $listing, User $user): void
        {
            $this->lifecycle->for($listing)->by($user)->because('Back in stock')->apply('reopen');
        }
    };

    $reopen($listing, $user);
    Lifecycles::for($listing)->apply('close');
    Carbon::setTestNow(CarbonImmutable::now()->addHours(2));

    // The raw use case
    app(ApplyTransitionAction::class)->execute(new TransitionRequest(
        subject: $listing,
        lifecycle: 'status',
        transition: 'reopen',
        actor: $user,
        reason: 'Back in stock',
    ));

    expect($listing->status)->toBe(ListingStatus::Active)
        ->and(Lifecycles::for($listing)->lastTransition()?->reason)->toBe('Back in stock');
});

it('runs the fake snippet', function (): void {
    $listing = Listing::query()->create(['status' => ListingStatus::Closed]);
    Route::post('/listings/{id}/reopen', function (string $id) {
        try {
            Lifecycles::for(Listing::query()->findOrFail($id))->because('Back')->apply('reopen');
        } catch (TransitionDeniedException $e) {
            throw $e->toValidationException();
        }
    });

    Lifecycles::fake()->denyNext('reopen', DenialCode::QuotaExceeded);

    $this->postJson("/listings/{$listing->id}/reopen")->assertStatus(422);

    Lifecycles::assertTransitionDenied($listing, 'reopen', DenialCode::QuotaExceeded);
    Lifecycles::assertNotTransitioned($listing, 'reopen');
});

/**
 * Stand-ins for the README's host collaborators.
 */
final readonly class ReadmeBilling
{
    public function __construct(public bool $paid) {}

    public function isPaid(object $subject): bool
    {
        return $this->paid;
    }
}

final readonly class ReadmeEnsureInvoicePaid implements Guard
{
    public function __construct(private ReadmeBilling $billing) {}

    public function check(TransitionContext $context): ?Denial
    {
        return $this->billing->isPaid($context->subject)
            ? null
            : Denial::of('invoice_unpaid', message: 'Pay the invoice first.', retryAfter: now()->addHour()->toImmutable());
    }
}

final readonly class ReadmeReserveStock implements CompensatesTransition, TransitionHandler
{
    public function handle(TransitionContext $context): void {}

    public function compensate(RollbackContext $context): void {}
}
