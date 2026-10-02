<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Rules\ValidState;
use RoundlyConsulting\Lifecycle\Rules\ValidTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\PaymentLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

function validate(mixed $value, object $rule): array
{
    return Validator::make(['field' => $value], ['field' => [$rule]])->errors()->get('field');
}

it('passes a transition that can be applied now', function (): void {
    $listing = Listing::factory()->create();

    expect(validate('publish', ValidTransition::for($listing)))->toBe([])
        ->and(validate(ListingStatus::Active, ValidTransition::for($listing)->toState()))->toBe([])
        ->and(validate('active', ValidTransition::for($listing, 'status')->toState()))->toBe([]);
});

it('fails with each denial message', function (): void {
    $listing = Listing::factory()->create();

    expect(validate('close', ValidTransition::for($listing)))->toBe(['The transition "Close" is not available from the current state "Draft".'])
        ->and(validate('closed', ValidTransition::for($listing)->toState()))->toBe(['There is no transition to "Closed" from the current state.']);
});

it('fails non-string input and unknown states with the generic message', function (mixed $value, bool $toState): void {
    $rule = ValidTransition::for(Listing::factory()->create());

    expect(validate($value, $toState ? $rule->toState() : $rule))->toBe(['The field is not a transition that can be applied.']);
})->with([
    'array' => [['publish'], false],
    'unknown state' => ['gone', true],
    'not a state' => [1.5, true],
]);

it('enforces the actor rules', function (): void {
    Gate::define('advance', fn (User $user): bool => $user->admin === true);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->ability('advance')));
    $document = Document::factory()->create();

    expect(validate('go', ValidTransition::for($document)->by(User::factory()->create())))->toBe(['You are not authorized to perform "Go".'])
        ->and(validate('go', ValidTransition::for($document)->by(User::factory()->create(['admin' => true]))))->toBe([])
        ->and(validate('go', ValidTransition::for($document)))->toBe(['You must be signed in to perform "Go".']);
});

it('translates the messages', function (): void {
    app()->setLocale('sk');

    expect(validate(['x'], ValidTransition::for(Listing::factory()->create())))->toBe(['Pole field nie je prechod, ktorý je možné vykonať.'])
        ->and(validate('gone', ValidState::of(Listing::class)))->toBe(['Pole field nie je platný stav.']);
});

it('accepts declared states only', function (): void {
    expect(validate('active', ValidState::of(Listing::class)))->toBe([])
        ->and(validate('captured', ValidState::of(PaymentLifecycle::class)))->toBe([])
        ->and(validate('unpaid', ValidState::of(new Order, 'payment_status')))->toBe([])
        ->and(validate('gone', ValidState::of(Listing::class)))->toBe(['The field is not a valid state.'])
        ->and(validate(['active'], ValidState::of(Listing::class)))->toBe(['The field is not a valid state.']);
});
