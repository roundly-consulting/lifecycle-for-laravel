<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\OrderLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\PaymentLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OrderStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OtherStatus;

it('round-trips a string-backed enum', function (): void {
    $codec = app(DefinitionRegistry::class)->get(ListingLifecycle::class)->codec;

    expect($codec->key(ListingStatus::Active))->toBe('active')
        ->and($codec->key('active'))->toBe('active')
        ->and($codec->decode('active'))->toBe(ListingStatus::Active)
        ->and($codec->decode(ListingStatus::Active))->toBe(ListingStatus::Active)
        ->and($codec->encode(ListingStatus::Active))->toBe('active')
        ->and($codec->encode('active'))->toBe('active')
        ->and($codec->tryDecode('nope'))->toBeNull()
        ->and($codec->tryDecode('draft'))->toBe(ListingStatus::Draft)
        ->and($codec->tryKey(OtherStatus::Draft))->toBeNull()
        ->and($codec->tryKey(null))->toBeNull()
        ->and($codec->tryKey(1.5))->toBeNull();
});

it('round-trips an int-backed enum through string keys', function (): void {
    $codec = app(DefinitionRegistry::class)->get(OrderLifecycle::class)->codec;

    expect($codec->key(OrderStatus::Fulfilled))->toBe('3')
        ->and($codec->decode(3))->toBe(OrderStatus::Fulfilled)
        ->and($codec->decode('3'))->toBe(OrderStatus::Fulfilled)
        ->and($codec->encode('3'))->toBe(3)
        ->and($codec->value('3'))->toBe(OrderStatus::Fulfilled);
});

it('round-trips plain strings', function (): void {
    $codec = app(DefinitionRegistry::class)->get(PaymentLifecycle::class)->codec;

    expect($codec->decode('captured'))->toBe('captured')
        ->and($codec->encode('captured'))->toBe('captured')
        ->and($codec->tryKey(ListingStatus::Draft))->toBeNull();
});

it('refuses undeclared values', function (mixed $value, string $message): void {
    $codec = app(DefinitionRegistry::class)->get(ListingLifecycle::class)->codec;

    expect(fn () => $codec->decode($value))->toThrow(UnknownStateException::class, $message);
})->with([
    'string' => ['gone', 'The state [gone] is not declared by the lifecycle definition ['.ListingLifecycle::class.'].'],
    'enum of another class' => [OtherStatus::Draft, OtherStatus::class.'::Draft'],
    'non scalar' => [[1], 'The state [array]'],
]);

it('refuses an undeclared key', function (): void {
    app(DefinitionRegistry::class)->get(ListingLifecycle::class)->codec->value('gone');
})->throws(UnknownStateException::class);
