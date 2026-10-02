<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * Without input, `check()`/`allowedTransitions()` report what a transition needs instead of
 * denying it — otherwise every reason-gated transition renders as a disabled button.
 * `apply()` always evaluates the input rows; with the same input both agree.
 */
beforeEach(function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle(
        $l,
        go: fn (TransitionBuilder $go) => $go->requiresReason()->rules(['note' => 'required|string']),
    ));
});

it('reports required input without denying', function (): void {
    $document = Document::factory()->create();
    $available = Lifecycles::for($document)->allowedTransitions()[0];

    expect(Lifecycles::for($document)->can('go'))->toBeTrue()
        ->and($available->allowed)->toBeTrue()
        ->and($available->requiresReason)->toBeTrue()
        ->and($available->payloadFields)->toBe(['note'])
        ->and(Lifecycles::for($document)->asSystem()->allowedTransitions(includeDenied: true)[0]->requiresReason)->toBeFalse();
});

it('denies apply without the input', function (): void {
    Lifecycles::for(Document::factory()->create())->apply('go');
})->throws(TransitionDeniedException::class, 'A reason is required.');

it('agrees between check and apply given the same input', function (array $payload, ?string $reason, array $codes): void {
    $document = Document::factory()->create();
    $handle = Lifecycles::for($document)->with($payload)->because($reason);

    $checked = $handle->check('go');
    $attempt = $handle->attempt('go');

    expect($checked->codes())->toBe($codes)
        ->and($attempt->decision->codes())->toBe($codes)
        ->and($attempt->succeeded)->toBe($codes === []);
})->with([
    'both given' => [['note' => 'ok'], 'because', []],
    'reason missing' => [['note' => 'ok'], '', ['reason_required']],
    'payload invalid' => [['note' => ['x']], 'because', ['invalid_payload']],
]);
