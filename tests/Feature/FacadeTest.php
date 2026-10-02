<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Lifecycle\Accessors\DefinitionsAccessor;
use RoundlyConsulting\Lifecycle\Actions\ApplyTransitionAction;
use RoundlyConsulting\Lifecycle\Actions\InitializeLifecycleAction;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\LifecycleHandle;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Lifecycle\ModelLifecycle;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Testing\RecordedCall;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;

/**
 * The public API contract — Actions → Manager → Facade (+ fake) — and every manager method
 * through the facade, the DI form and the raw action.
 */
it('pins the facade contract', function (): void {
    expect(Lifecycles::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];
    $alias = (string) array_key_first($aliases);
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe(['Lifecycles' => Lifecycles::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\'.$alias))->toBeFalse()
        ->and($coreAliases)->not->toContain(strtolower($alias));
});

it('runs every manager method through the facade', function (): void {
    $listing = Listing::factory()->create();

    $request = new TransitionRequest($listing, 'status', 'publish');

    expect(Lifecycles::for($listing))->toBeInstanceOf(LifecycleHandle::class)
        ->and(Lifecycles::model(Listing::class))->toBeInstanceOf(ModelLifecycle::class)
        ->and(Lifecycles::definitions())->toBeInstanceOf(DefinitionsAccessor::class)
        ->and(Lifecycles::check($request)->allowed)->toBeTrue()
        ->and(Lifecycles::available(new AvailableTransitionsQuery($listing, 'status'))[0]->name)->toBe('publish')
        ->and(Lifecycles::apply($request)->to)->toBe(ListingStatus::Active)
        ->and(Lifecycles::attempt(new TransitionRequest($listing, 'status', 'close'))->succeeded)->toBeTrue()
        ->and(Lifecycles::attempt(new TransitionRequest($listing, 'status', 'close'))->decision->has(DenialCode::NotFromCurrentState))->toBeTrue()
        ->and(Lifecycles::adopt($listing))->toBeFalse()
        ->and(Lifecycles::adoptAll(Listing::class))->toBe(0)
        ->and(Lifecycles::allowDirectWrites(fn (): string => 'ran'))->toBe('ran');
});

it('serves the same API through an injected manager and the raw action', function (): void {
    $listing = Listing::factory()->create();
    $manager = app(LifecycleManager::class);

    expect($manager)->toBe(app(LifecycleManager::class))
        ->and($manager->for($listing)->apply('publish')->to)->toBe(ListingStatus::Active);

    app(ApplyTransitionAction::class)->execute(new TransitionRequest($listing, 'status', 'close'));

    expect($listing->status)->toBe(ListingStatus::Closed);
});

it('resolves actions through the container so a host override applies', function (): void {
    app()->bind(ApplyTransitionAction::class, static fn (): never => throw new RuntimeException('overridden'));

    Lifecycles::for(Listing::factory()->create())->apply('publish');
})->throws(RuntimeException::class, 'overridden');

it('swaps the manager everywhere under the fake', function (): void {
    $fake = Lifecycles::fake();

    expect(app(LifecycleManager::class))->toBe($fake)
        ->and(Lifecycles::getFacadeRoot())->toBe($fake);
});

it('records transitions made through the facade, an injected manager and the model trait', function (): void {
    app()->bind(ApplyTransitionAction::class, static fn (): never => throw new RuntimeException('ran'));
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();

    Lifecycles::for($listing)->apply('publish');
    app(LifecycleManager::class)->for($listing)->apply('close');
    $listing->transition('reopen');

    expect($listing->status)->toBe(ListingStatus::Active)
        ->and($fake->recorded())->toHaveCount(3)
        ->and($fake->recorded()[0])->toBeInstanceOf(RecordedCall::class);

    $fake->assertTransitioned($listing);
    $fake->assertTransitioned($listing, 'reopen');
    $fake->assertTransitioned($listing, 'publish', fn ($result) => $result->to === ListingStatus::Active);
    $fake->assertTransitionedTo($listing, ListingStatus::Closed);
    $fake->assertTransitionedTo($listing, 'closed', 'status');
    $fake->assertNotTransitioned($listing, 'archive');
    Lifecycles::assertTransitioned($listing, 'close');
});

it('fails assertTransitioned when nothing matched', function (): void {
    Lifecycles::fake()->assertTransitioned(Listing::factory()->create(), 'publish');
})->throws(ExpectationFailedException::class, 'to be transitioned via [publish]');

it('fails assertTransitionedTo for another state', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    $fake->assertTransitionedTo($listing, ListingStatus::Archived);
})->throws(ExpectationFailedException::class, 'to be transitioned to the given state');

it('fails assertTransitionedTo for another lifecycle', function (): void {
    $fake = Lifecycles::fake();
    $order = Order::factory()->create();
    $order->transition('pay');

    $fake->assertTransitionedTo($order, 'unpaid', 'payment_status');
})->throws(ExpectationFailedException::class);

it('fails assertNotTransitioned after a transition', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    $fake->assertNotTransitioned($listing);
})->throws(ExpectationFailedException::class, 'not to be transitioned, but it was');

it('passes and fails assertNothingTransitioned', function (): void {
    $fake = Lifecycles::fake();
    $fake->assertNothingTransitioned();

    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(fn () => $fake->assertNothingTransitioned())
        ->toThrow(ExpectationFailedException::class, '1 transition(s) were applied');
});

it('denies the next application once, or every application', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();

    $fake->denyNext('publish', DenialCode::QuotaExceeded);

    expect(Lifecycles::for($listing)->can('publish'))->toBeFalse()
        ->and(fn () => $listing->transition('publish'))->toThrow(TransitionDeniedException::class);

    $fake->assertTransitionDenied($listing, 'publish', DenialCode::QuotaExceeded);
    $fake->assertTransitionDenied($listing);

    expect($listing->transition('publish')->to)->toBe(ListingStatus::Active);

    $fake->deny('close', 'blocked', 'Closing is blocked.');

    expect(Lifecycles::for($listing)->attempt('close')->decision->first()?->message)->toBe('Closing is blocked.')
        ->and(Lifecycles::for($listing)->attempt('close')->succeeded)->toBeFalse();
});

it('fails assertTransitionDenied when nothing was denied', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();
    $fake->denyNext('publish');
    rescue(fn () => $listing->transition('publish'), report: false);

    $fake->assertTransitionDenied($listing, 'publish', DenialCode::Frozen);
})->throws(ExpectationFailedException::class, 'to be denied, but none was');

it('records adoptions and fails assertAdopted without one', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();

    expect(fn () => $fake->assertAdopted())->toThrow(ExpectationFailedException::class, 'Expected a lifecycle adoption');

    expect(Lifecycles::for($listing)->adopt())->toBeFalse()
        ->and(Lifecycles::model(Listing::class)->adopt())->toBe(0);

    $fake->assertAdopted();
    $fake->assertAdopted($listing);
    expect(fn () => $fake->assertAdopted(Listing::factory()->create()))->toThrow(ExpectationFailedException::class);
});

it('keeps the pure model hooks real under the fake and skips the database ones', function (): void {
    app()->bind(InitializeLifecycleAction::class, static fn (): never => throw new RuntimeException('initialised'));
    Lifecycles::fake();

    $listing = Listing::factory()->create();

    expect($listing->status)->toBe(ListingStatus::Draft)
        ->and(LifecycleState::query()->count())->toBe(0);

    $listing->status = ListingStatus::Active;

    expect(fn () => $listing->save())->toThrow(DirectStateWriteException::class);

    $listing->refresh();
    $listing->title = 'renamed';
    $listing->save();
    $listing->delete();
    $listing->forceDelete();

    expect(Listing::withTrashed()->count())->toBe(0);
});

it('lists available transitions under the fake', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();
    $fake->deny('publish', DenialCode::Unauthorized);

    expect(array_map(fn ($t) => $t->name, Lifecycles::for($listing)->allowedTransitions()))->toBe(['archive'])
        ->and(array_map(fn ($t) => [$t->name, $t->allowed], Lifecycles::for($listing)->allowedTransitions(includeDenied: true)))
        ->toBe([['publish', false], ['archive', true]]);
});
