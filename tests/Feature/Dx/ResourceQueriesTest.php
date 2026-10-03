<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Engine\StateRecords;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Http\Resources\LifecycleResource;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/**
 * How many queries rendering a resource collection of eager-loaded listings takes.
 */
function resourceQueries(int $listings): int
{
    Listing::query()->forceDelete();
    Listing::factory()->count($listings)->create()->each(fn (Listing $listing) => $listing->transition('publish'));

    $models = Listing::query()->withLifecycle()->get();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    LifecycleResource::collection($models)->toArray(new Request);

    return $queries;
}

it('renders a collection of eager-loaded subjects in a constant number of queries', function (): void {
    $one = resourceQueries(1);
    $five = resourceQueries(5);

    expect($five)->toBe($one)
        ->and($one)->toBe(0);
});

it('reads the last transition from the eager-loaded relation', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $listing->transition('close');
    Lifecycles::for($listing)->rollback();
    $expected = Lifecycles::for($listing)->lastTransition();

    $loaded = Listing::query()->withLifecycle()->findOrFail($listing->id);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $read = Lifecycles::for($loaded)->lastTransition();

    expect($queries)->toBe(0)
        ->and($read)->toEqual($expected)
        ->and($read?->kind->value)->toBe('rollback');
});

it('reports a reverted last transition from the eager-loaded relation', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');
    $first = Lifecycles::for($listing)->lastTransition();
    DB::table('lifecycle_transitions')->insert([
        'subject_type' => 'unrelated', 'subject_id' => 1, 'lifecycle' => 'status', 'kind' => 'rollback',
        'from_state' => 'active', 'to_state' => 'draft', 'is_system' => true, 'version' => 1,
        'reverts_id' => $first?->id, 'occurred_at' => '2026-10-02 08:00:00',
    ]);

    $loaded = Listing::query()->withLifecycle()->findOrFail($listing->id);

    expect(Lifecycles::for($loaded)->lastTransition()?->reverted)->toBeTrue()
        ->and(Lifecycles::for($listing)->lastTransition()?->reverted)->toBeTrue();
});

it('picks the latest row per lifecycle of a subject with two lifecycles', function (): void {
    $order = Order::factory()->create();
    $order->transition('pay');
    Lifecycles::for($order, 'payment_status')->apply('authorize');
    $order->transition('fulfil');

    $loaded = Order::query()->withLifecycle()->findOrFail($order->id);

    expect(Lifecycles::for($loaded)->lastTransition())->toEqual(Lifecycles::for($order)->lastTransition())
        ->and(Lifecycles::for($loaded, 'payment_status')->lastTransition())->toEqual(Lifecycles::for($order, 'payment_status')->lastTransition())
        ->and(Lifecycles::for($loaded)->lastTransition()?->transition)->toBe('fulfil')
        ->and(Lifecycles::for($loaded, 'payment_status')->lastTransition()?->transition)->toBe('authorize');
});

it('makes a resource from a model like from its handle', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(LifecycleResource::make($listing)->toArray(new Request))
        ->toEqual(LifecycleResource::make(Lifecycles::for($listing))->toArray(new Request));
});

it('finds no record in an eager-loaded set without the lifecycle', function (): void {
    $order = Order::factory()->create();
    $loaded = Order::query()->with(['lifecycleStates' => fn ($states) => $states->where('lifecycle', 'status')])->findOrFail($order->id);

    expect(app(StateRecords::class)->find($loaded, 'payment_status'))->toBeNull()
        ->and(app(StateRecords::class)->find($loaded, 'status')?->lifecycle)->toBe('status')
        ->and(app(StateRecords::class)->find($order, 'payment_status')?->lifecycle)->toBe('payment_status');
});

it('renders another lifecycle of a collection, in a constant number of queries', function (): void {
    $render = function (int $count): array {
        Order::query()->delete();
        Order::factory()->count($count)->create()->each(fn (Order $order) => Lifecycles::for($order, 'payment_status')->apply('authorize'));
        $models = Order::query()->withLifecycle()->get();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $rows = LifecycleResource::collectionFor($models, 'payment_status')->toArray(new Request);

        return [$queries, array_unique(array_column($rows, 'lifecycle')), array_unique(array_column($rows, 'state'))];
    };

    $one = $render(1);
    $three = $render(3);

    expect($three)->toBe($one)
        ->and($one)->toBe([0, ['payment_status'], ['authorized']]);
});
