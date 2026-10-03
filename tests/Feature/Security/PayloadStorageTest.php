<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\LifecycleTransition;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

function storedContext(): ?array
{
    return LifecycleTransition::query()->where('kind', 'transition')->sole()->context;
}

it('stores only validated payload keys', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->rules(['note' => 'string'])));

    Lifecycles::for(Document::factory()->create())->with(['note' => 'kept', 'is_admin' => true])->apply('go');

    expect(storedContext())->toBe(['note' => 'kept']);
});

it('refuses a payload for a transition without rules and stores nothing', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));

    expect(fn () => Lifecycles::for(Document::factory()->create())->with(['anything' => 'goes'])->apply('go'))
        ->toThrow(InvalidLifecycleUsageException::class, 'payload [anything] would be dropped')
        ->and(LifecycleTransition::query()->where('kind', 'transition')->count())->toBe(0);
});

it('stores no payload when history.store_payload is off', function (string $value): void {
    config()->set('lifecycle.history.store_payload', $value);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->rules(['note' => 'string'])));

    Lifecycles::for(Document::factory()->create())->with(['note' => 'kept'])->apply('go');

    expect(storedContext())->toBeNull();
})->with(['off', 'no', '0', 'false']);

it('stores the payload when history.store_payload is blank, as its default does', function (): void {
    config()->set('lifecycle.history.store_payload', '');
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->rules(['note' => 'string'])));

    Lifecycles::for(Document::factory()->create())->with(['note' => 'kept'])->apply('go');

    expect(storedContext())->toBe(['note' => 'kept']);
});
