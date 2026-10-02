<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Definition\TransitionDefinition;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownLifecycleException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OrderStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\NotASubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Ticket;

it('returns a new handle for every context change', function (): void {
    $handle = Lifecycles::for(Listing::factory()->create());

    expect($handle->by(null))->not->toBe($handle)
        ->and($handle->asSystem())->not->toBe($handle)
        ->and($handle->because('x'))->not->toBe($handle)
        ->and($handle->with(['a' => 1]))->not->toBe($handle)
        ->and($handle->expectingVersion(1))->not->toBe($handle)
        ->and($handle->idempotencyKey('k'))->not->toBe($handle)
        ->and($handle->lifecycle)->toBe('status');
});

it('reads the state and the record', function (): void {
    $listing = Listing::factory()->create();

    $handle = Lifecycles::for($listing);

    expect($handle->state())->toBe(ListingStatus::Draft)
        ->and($handle->is(ListingStatus::Active, 'draft'))->toBeTrue()
        ->and($handle->is(ListingStatus::Active))->toBeFalse()
        ->and($handle->isTerminal())->toBeFalse()
        ->and($handle->version())->toBe(1)
        ->and($handle->enteredAt())->not->toBeNull()
        ->and($handle->definition()->class)->toBe(ListingLifecycle::class)
        ->and($listing->lifecycle()->state())->toBe(ListingStatus::Draft);
});

it('reads an eager-loaded record without querying', function (): void {
    $listing = Listing::factory()->create();
    $listing->load('lifecycleStates');
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(Lifecycles::for($listing)->version())->toBe(1)
        ->and($queries)->toBe(0);

    $listing->setRelation('lifecycleStates', $listing->newCollection());

    expect(Lifecycles::for($listing)->version())->toBe(0)
        ->and(Lifecycles::for($listing)->enteredAt())->toBeNull();
});

it('forgets loaded records after a mutation', function (): void {
    $listing = Listing::factory()->create();
    $listing->load('lifecycleStates', 'lifecycleHistory', 'lifecycleSchedules');

    $listing->transition('publish');

    expect($listing->relationLoaded('lifecycleStates'))->toBeFalse()
        ->and(Lifecycles::for($listing)->version())->toBe(2)
        ->and($listing->lifecycleHistory()->count())->toBe(2)
        ->and($listing->lifecycleSchedules()->count())->toBe(1); // the active state's expiry
});

it('reports a subject without a state', function (): void {
    $ticket = new Ticket;
    $ticket->exists = true;

    Lifecycles::for($ticket)->state();
})->throws(UnknownStateException::class, 'has no state yet');

it('reports a new subject without a record', function (): void {
    expect(Lifecycles::for(new Listing)->version())->toBe(0);
});

it('resolves the lifecycle name or refuses', function (): void {
    expect(Lifecycles::for(Order::factory()->create(), 'payment_status')->lifecycle)->toBe('payment_status')
        ->and(fn () => Lifecycles::for(new NotASubject))->toThrow(UnknownLifecycleException::class)
        ->and(fn () => Lifecycles::for(new Order, 'colour'))->toThrow(UnknownLifecycleException::class);
});

it('describes a model lifecycle at class level', function (): void {
    $model = Lifecycles::model(Order::class);

    expect($model->class)->toBe(Order::class)
        ->and($model->lifecycle)->toBe('status')
        ->and($model->states())->toBe(OrderStatus::cases())
        ->and($model->initial())->toBe(OrderStatus::Pending)
        ->and($model->terminal())->toBe([OrderStatus::Fulfilled, OrderStatus::Cancelled])
        ->and(array_map(fn (TransitionDefinition $t): string => $t->name, $model->transitions()))->toBe(['pay', 'fulfil', 'cancel'])
        ->and($model->graph(GraphFormat::Dot))->toStartWith('digraph "OrderLifecycle"')
        ->and($model->definition()->initial)->toBe('1');
});
