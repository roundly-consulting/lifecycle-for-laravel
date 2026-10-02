<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

it('refuses column names that could carry SQL', function (Closure $define): void {
    expect(validateLifecycle($define)->has(IssueCode::InvalidIdentifier))->toBeTrue();
})->with([
    'stamp' => [fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->stamps('paid_at = now()--')],
    'quota scope' => [fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(1, 'user_id) or (1=1')],
    'snapshot' => [fn (LifecycleBuilder $l) => baseLifecycle($l)->transition('x')->from('a')->to('c')->snapshots('price; drop table x')],
]);

it('binds state values, never interpolates them', function (): void {
    $odd = "o'brien\"; drop table lifecycle_states; --";
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($odd): void {
        $l->states(['a', $odd])->initial('a')->terminal($odd);
        $l->transition('go')->from('a')->to($odd);
    });
    $document = Document::factory()->create();

    $document->transition('go');

    expect($document->fresh()?->status)->toBe($odd)
        ->and(LifecycleState::query()->sole()->state)->toBe($odd);
});
