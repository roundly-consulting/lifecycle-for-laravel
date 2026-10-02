<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('cannot be switched to system context through input', function (): void {
    expect(method_exists(TransitionRequest::class, 'fromArray'))->toBeFalse()
        ->and(method_exists(TransitionRequest::class, 'from'))->toBeFalse();

    $listing = Listing::factory()->create();
    $listing->transition('publish');

    $decision = Lifecycles::for($listing)->with(['system' => true, 'actor' => 'admin', 'reason' => 'x'])->check('expire');

    expect($decision->codes())->toBe(['system_only']);
});

it('treats payload keys named like context fields as plain payload', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->rules(['system' => 'boolean', 'reason' => 'string'])));
    $document = Document::factory()->create();

    Lifecycles::for($document)->with(['system' => true, 'reason' => 'from payload'])->apply('go');

    $row = LifecycleTransition::query()->where('kind', 'transition')->sole();

    expect($row->is_system)->toBeFalse()
        ->and($row->reason)->toBeNull()
        ->and($row->context)->toEqual(['system' => true, 'reason' => 'from payload']); // jsonb does not keep key order
});
