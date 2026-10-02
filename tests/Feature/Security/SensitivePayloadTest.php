<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

it('hands sensitive keys to the handler but never stores them', function (): void {
    $seen = null;
    defineDocumentLifecycle(function (LifecycleBuilder $l) use (&$seen): void {
        baseLifecycle($l, go: function (TransitionBuilder $go) use (&$seen): void {
            $go->rules(['card' => 'required|string', 'note' => 'string'])->sensitive('card')
                ->handledBy(function (TransitionContext $context) use (&$seen): void {
                    $seen = $context->payload['card'];
                });
        });
    });

    Lifecycles::for(Document::factory()->create())->with(['card' => '4242', 'note' => 'n'])->apply('go');

    $row = LifecycleTransition::query()->where('kind', 'transition')->sole();

    expect($seen)->toBe('4242')
        ->and($row->context)->toBe(['note' => 'n'])
        ->and(json_encode($row->getAttributes()))->not->toContain('4242');
});
