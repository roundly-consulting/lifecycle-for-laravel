<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransitionsQuery;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('refuses an unsaved subject everywhere', function (Closure $call): void {
    expect(fn () => $call(new Listing(['status' => 'draft'])))->toThrow(SubjectNotPersistedException::class, 'must be saved');
})->with([
    'apply' => [fn (Listing $l) => Lifecycles::apply(new TransitionRequest($l, 'status', 'publish'))],
    'check' => [fn (Listing $l) => Lifecycles::check(new TransitionRequest($l, 'status', 'publish'))],
    'available' => [fn (Listing $l) => Lifecycles::available(new AvailableTransitionsQuery($l, 'status'))],
    'adopt' => [fn (Listing $l) => Lifecycles::adopt($l)],
    'rollback' => [fn (Listing $l) => Lifecycles::rollback(new RollbackRequest($l, 'status'))],
    'fake apply' => [function (Listing $l): void {
        Lifecycles::fake();
        Lifecycles::apply(new TransitionRequest($l, 'status', 'publish'));
    }],
    'fake check' => [function (Listing $l): void {
        Lifecycles::fake();
        Lifecycles::check(new TransitionRequest($l, 'status', 'publish'));
    }],
]);

it('refuses a subject whose row is gone', function (): void {
    $listing = Listing::factory()->create();
    Listing::query()->whereKey($listing->id)->forceDelete();

    $listing->transition('publish');
})->throws(SubjectNotPersistedException::class, 'no longer exists');
