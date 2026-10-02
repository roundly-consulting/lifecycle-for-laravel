<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownLifecycleException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\OrderLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\PaymentLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\NoLifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\NotASubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;

it('compiles a definition once and keeps it until flushed', function (): void {
    $registry = app(DefinitionRegistry::class);

    $first = $registry->get(ListingLifecycle::class);

    expect($registry->get(ListingLifecycle::class))->toBe($first)
        ->and(app(DefinitionRegistry::class))->toBe($registry);

    $registry->flush();

    expect($registry->get(ListingLifecycle::class))->not->toBe($first)
        ->and($registry->get(ListingLifecycle::class))->toEqual($first);
});

it('resolves a model lifecycle by attribute, defaulting to the first', function (): void {
    $registry = app(DefinitionRegistry::class);

    expect($registry->of(new Order)->class)->toBe(OrderLifecycle::class)
        ->and($registry->of(Order::class, 'payment_status')->class)->toBe(PaymentLifecycle::class)
        ->and($registry->lifecycleName(Order::class))->toBe('status')
        ->and($registry->lifecycleName(new Order, 'payment_status'))->toBe('payment_status')
        ->and($registry->of(Listing::class)->class)->toBe(ListingLifecycle::class)
        ->and($registry->definitionsOf(Listing::class))->toBe(['status' => ListingLifecycle::class]);
});

it('refuses models that are not lifecycle subjects', function (Closure $call, string $message): void {
    expect($call)->toThrow(UnknownLifecycleException::class, $message);
})->with([
    'class string' => [fn () => app(DefinitionRegistry::class)->of(NotASubject::class), 'does not implement'],
    'instance' => [fn () => app(DefinitionRegistry::class)->of(new NotASubject), 'does not implement'],
    'not a model' => [fn () => app(DefinitionRegistry::class)->of(stdClass::class), 'does not implement'],
    'undeclared attribute' => [fn () => app(DefinitionRegistry::class)->of(Order::class, 'colour'), 'no lifecycle on the attribute [colour]'],
    'no lifecycles' => [fn () => app(DefinitionRegistry::class)->of(NoLifecycles::class), 'declares no lifecycle at all'],
    'not a definition' => [fn () => app(DefinitionRegistry::class)->get(stdClass::class), 'is not a RoundlyConsulting'],
    'validate a non definition' => [fn () => app(DefinitionRegistry::class)->validate(stdClass::class), 'is not a RoundlyConsulting'],
]);
