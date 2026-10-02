<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Lifecycle\DataTransferObjects\StateHookContext;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Enums\TransitionKind;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\CompensatingHandler;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\RecordingHook;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('writes the state, history, record and stamps in one go', function (): void {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 11:30:00', 'UTC'));

    $result = Lifecycles::for($listing)->by($user)->because('Ready')->apply('publish');

    $row = LifecycleTransition::query()->where('kind', 'transition')->sole();
    $record = LifecycleState::query()->sole();

    expect($result->transition)->toBe('publish')
        ->and($result->from)->toBe(ListingStatus::Draft)
        ->and($result->to)->toBe(ListingStatus::Active)
        ->and($result->replayed)->toBeFalse()
        ->and($result->record->id)->toBe($row->id)
        ->and($result->record->kind)->toBe(TransitionKind::Transition)
        ->and($result->record->reverted)->toBeFalse()
        ->and($row->from_state)->toBe('draft')
        ->and($row->to_state)->toBe('active')
        ->and($row->actor_type)->toBe($user->getMorphClass())
        ->and($row->actor_id)->toEqual($user->id)
        ->and($row->is_system)->toBeFalse()
        ->and($row->reason)->toBe('Ready')
        ->and($row->version)->toBe(2)
        ->and($row->previous_entered_at?->toDateTimeString())->toBe('2026-10-02 10:00:00')
        ->and($row->counter_before)->toBeNull()
        ->and($row->occurred_at->toDateTimeString())->toBe('2026-10-02 11:30:00')
        ->and($row->snapshot['before'])->toBe(['published_at' => null])
        ->and($row->snapshot['after']['published_at'])->not->toBeNull()
        ->and($record->state)->toBe('active')
        ->and($record->previous_state)->toBe('draft')
        ->and($record->version)->toBe(2)
        ->and($record->entered_at->toDateTimeString())->toBe('2026-10-02 11:30:00')
        ->and($record->counters)->toBe(['publish' => ['count' => 1, 'last_at' => '2026-10-02 11:30:00']])
        ->and($listing->fresh()?->published_at)->not->toBeNull()
        ->and(Lifecycles::for($listing)->version())->toBe(2)
        ->and(Lifecycles::for($listing)->enteredAt()?->toDateTimeString())->toBe('2026-10-02 11:30:00');
});

it('dispatches Transitioning inside the transaction and Transitioned after it', function (): void {
    $listing = Listing::factory()->create();
    $levels = [];

    Event::listen(LifecycleTransitioning::class, function (LifecycleTransitioning $event) use (&$levels): void {
        $levels['transitioning'] = $event->subject->getConnection()->transactionLevel();
        expect($event->transition)->toBe('publish')
            ->and($event->from)->toBe(ListingStatus::Draft)
            ->and($event->to)->toBe(ListingStatus::Active)
            ->and($event->system)->toBeFalse();
    });
    Event::listen(LifecycleTransitioned::class, function (LifecycleTransitioned $event) use (&$levels): void {
        $levels['transitioned'] = $event->subject->getConnection()->transactionLevel();
        expect($event->kind)->toBe(TransitionKind::Transition)
            ->and($event->version)->toBe(2)
            ->and($event->subjectId)->toBe($event->subject->getKey());
    });

    $listing->transition('publish');

    expect($levels)->toBe(['transitioning' => 1, 'transitioned' => 0]);
});

it('runs exit hooks, the handler and enter hooks in that order, with the handler save inside', function (): void {
    $order = [];
    $exitHook = new RecordingHook;
    app()->instance(RecordingHook::class, $exitHook);

    defineDocumentLifecycle(function (LifecycleBuilder $l) use (&$order): void {
        baseLifecycle($l, go: function (TransitionBuilder $go) use (&$order): void {
            $go->handledBy(function (TransitionContext $c) use (&$order): void {
                $order[] = 'handle';
                $c->subject->setAttribute('title', 'handled');
            });
        });
        $l->state('a')->onExit(function (StateHookContext $c) use (&$order): void {
            $order[] = 'exit:'.$c->state;
        })->onExit(RecordingHook::class);
        $l->state('b')->onEnter(function (StateHookContext $c) use (&$order): void {
            $order[] = 'enter:'.$c->state.':'.$c->transition->transition->name;
        });
    });

    $document = Document::factory()->create();
    $document->transition('go');

    expect($order)->toBe(['exit:a', 'handle', 'enter:b:go'])
        ->and($exitHook->seen)->toBe(['status:a'])
        ->and($document->isDirty())->toBeFalse()
        ->and($document->fresh()?->title)->toBe('handled');
});

it('resolves class-string handlers from the container on every run', function (): void {
    $handler = new CompensatingHandler;
    app()->instance(CompensatingHandler::class, $handler);

    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle(
            $l,
            go: fn (TransitionBuilder $go) => $go->handledBy(CompensatingHandler::class),
            finish: fn (TransitionBuilder $finish) => $finish->handledBy(new CompensatingHandler),
        );
    });

    $document = Document::factory()->create();
    $document->transition('go');
    $document->transition('finish');

    expect($handler->calls)->toBe(['handle:go']);
});

it('refuses handlers and hooks that do not implement their contract', function (string $kind): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($kind): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $kind === 'handler' ? $go->handledBy(stdClass::class) : $go);

        if ($kind === 'hook') {
            $l->state('b')->onEnter(stdClass::class);
        }
    });

    Document::factory()->create()->transition('go');
})->with(['handler', 'hook'])->throws(InvalidLifecycleUsageException::class, 'does not implement the expected contract');

it('captures declared snapshot attributes before and after', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->snapshots('price', 'quantity')
            ->handledBy(fn (TransitionContext $c) => $c->subject->setAttribute('price', '12.50')));
    });

    $document = Document::factory()->create(['price' => '10.00', 'quantity' => 3]);
    $document->transition('go');

    $snapshot = LifecycleTransition::query()->where('kind', 'transition')->sole()->snapshot;

    expect($snapshot['before']['quantity'])->toEqual(3)
        ->and((float) $snapshot['before']['price'])->toBe(10.0)
        ->and((float) $snapshot['after']['price'])->toBe(12.5);
});

it('keeps entered_at on a self-transition and still counts it', function (): void {
    $listing = Listing::factory()->create();
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('touch')->from('b')->to('b')->allowSelf();
    });
    $document = Document::factory()->create();
    $document->transition('go');
    $entered = Lifecycles::for($document)->enteredAt();

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC'));
    $result = $document->transition('touch');

    $record = LifecycleState::query()->where('subject_type', $document->getMorphClass())->sole();

    expect($result->from)->toBe('b')
        ->and($result->to)->toBe('b')
        ->and($record->entered_at)->toEqual($entered)
        ->and($record->version)->toBe(3)
        ->and($record->counters['touch']['count'])->toBe(1)
        ->and($listing->status)->toBe(ListingStatus::Draft);
});
