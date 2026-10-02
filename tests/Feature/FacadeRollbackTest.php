<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Lifecycle\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Lifecycle\DataTransferObjects\RollbackRequest;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('runs rollback, checkRollback and prune through the facade', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(Lifecycles::checkRollback(new RollbackRequest($listing, 'status'))->allowed)->toBeTrue()
        ->and(Lifecycles::rollback(new RollbackRequest($listing, 'status'))->to)->toBe(ListingStatus::Draft)
        ->and(Lifecycles::prune(new PruneOptions(1, 1))->historyDeleted)->toBe(0);
});

it('rolls back the fake own stack and records it', function (): void {
    Lifecycles::fake();
    $listing = Listing::factory()->create();

    expect(fn () => Lifecycles::for($listing)->rollback())->toThrow(RollbackDeniedException::class, 'nothing to roll back');

    $published = $listing->transition('publish');
    $listing->transition('close');
    $listing->transition('reopen');

    expect(Lifecycles::for($listing)->canRollback()->allowed)->toBeTrue()
        ->and(Lifecycles::for($listing)->rollback()->to)->toBe(ListingStatus::Closed)
        ->and($listing->status)->toBe(ListingStatus::Closed);

    $result = Lifecycles::for($listing)->rollbackTo($published->record);

    expect($result->to)->toBe(ListingStatus::Active)
        ->and($result->reverted)->toHaveCount(1)
        ->and($listing->status)->toBe(ListingStatus::Active)
        ->and(Lifecycles::for($listing)->canRollback()->allowed)->toBeTrue()
        ->and(fn () => Lifecycles::for($listing)->rollbackTo($published->record))->toThrow(RollbackDeniedException::class)
        ->and(fn () => Lifecycles::for($listing)->rollbackTo(999))->toThrow(RollbackDeniedException::class, 'cannot be rolled back to');
});

it('asserts rollbacks and prunes', function (): void {
    $fake = Lifecycles::fake();
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    $fake->assertNotPruned();
    Lifecycles::for($listing)->rollback();
    Lifecycles::prune(new PruneOptions);

    $fake->assertRolledBack($listing);
    $fake->assertRolledBack($listing, fn ($result) => $result->from === ListingStatus::Active);
    $fake->assertPruned();

    expect(fn () => $fake->assertNothingRolledBack())->toThrow(ExpectationFailedException::class, '1 rollback(s)')
        ->and(fn () => $fake->assertRolledBack(Listing::factory()->create()))->toThrow(ExpectationFailedException::class, 'to be rolled back')
        ->and(fn () => $fake->assertNotPruned())->toThrow(ExpectationFailedException::class, 'not to be pruned')
        ->and(fn () => Lifecycles::fake()->assertPruned())->toThrow(ExpectationFailedException::class, 'to be pruned');
});
