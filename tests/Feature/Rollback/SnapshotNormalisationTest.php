<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * Snapshots hold raw stored values, compared through the same JSON round trip on both
 * sides — no driver-specific representation (a pgsql numeric '10.00', a MySQL tinyint)
 * becomes a false conflict.
 */
it('restores every column type without a false conflict', function (string $column, mixed $initial, mixed $changed): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($column, $changed): void {
        $l->states(['a', 'b'])->initial('a')->terminal('b');
        $l->transition('change')->from('a')->to('b')->snapshots($column)
            ->handledBy(fn (TransitionContext $c) => $c->subject->setAttribute($column, $changed))
            ->reversible(withoutCompensation: true);
    });
    $document = Document::factory()->create([$column => $initial]);
    $before = $document->fresh()?->getRawOriginal($column);
    // Drivers render a decimal as '10.00', '10.0' or '10': compare its cast value as a float.
    $cast = static fn (mixed $value): mixed => $column === 'price' && $value !== null ? (float) $value : $value;
    $raw = static fn (mixed $value): mixed => json_decode((string) json_encode($value), true);

    $document->transition('change');

    expect($cast($document->fresh()?->getAttribute($column)))->not->toEqual($cast($initial))
        ->and(Lifecycles::for($document)->canRollback()->allowed)->toBeTrue();

    Lifecycles::for($document)->rollback();
    $after = $document->fresh();

    expect($cast($after?->getAttribute($column)))->toEqual($cast($initial))
        ->and($raw($after?->getRawOriginal($column)))->toBe($raw($before));
})->with([
    'int' => ['quantity', 3, 7],
    'decimal' => ['price', '10.00', '12.50'],
    'bool' => ['flag', true, false],
    'null' => ['quantity', null, 4],
    'json' => ['meta', ['a' => 1, 'b' => [1, 2]], ['a' => 2]],
]);
