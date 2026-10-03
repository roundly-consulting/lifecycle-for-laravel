<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\RollbackDeniedException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

it('caps the reason length in every context', function (): void {
    config()->set('lifecycle.history.reason_max_length', 5);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly());
    $document = Document::factory()->create();

    expect(fn () => Lifecycles::for($document)->because('too long')->apply('go'))
        ->toThrow(TransitionDeniedException::class, 'The reason may not be longer than 5 characters.');

    $document->transition('go');

    expect(Lifecycles::for($document)->asSystem()->because('too long')->check('lapse')->codes())->toBe(['reason_too_long']);
});

it('caps the reason of a rollback and of a freeze', function (): void {
    config()->set('lifecycle.history.reason_max_length', 5);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));
    $document = Document::factory()->create();
    $document->transition('go');

    expect(Lifecycles::for($document)->because('too long')->canRollback()->codes())->toBe(['reason_too_long'])
        ->and(fn () => Lifecycles::for($document)->because('too long')->rollback())
        ->toThrow(RollbackDeniedException::class, 'The reason may not be longer than 5 characters.')
        ->and(Lifecycles::for($document)->because('short')->canRollback()->allowed)->toBeTrue()
        ->and(fn () => Lifecycles::for($document)->because('too long')->freeze())
        ->toThrow(InvalidLifecycleUsageException::class)
        ->and(Lifecycles::for($document)->isFrozen())->toBeFalse()
        ->and(Lifecycles::for($document)->because('short')->freeze())->toBeTrue();
});
