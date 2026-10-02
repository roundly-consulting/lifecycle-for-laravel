<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
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
