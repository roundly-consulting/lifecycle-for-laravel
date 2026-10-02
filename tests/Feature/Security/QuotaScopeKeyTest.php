<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\QuotaScopeException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;

/**
 * The quota mutex key is the JSON list of the scope values: no delimiter a value could
 * forge, unicode kept, NULL distinct from any string, at most 191 bytes.
 */
beforeEach(function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->state('b')->quota(1, ['title', 'user_id']);
    });
});

it('stores the scope values as a JSON list', function (): void {
    Document::factory()->create(['title' => 'Žltý|kôň', 'user_id' => null])->transition('go');
    Document::factory()->create(['title' => 'a', 'user_id' => 7])->transition('go');

    expect(QuotaLock::query()->orderBy('id')->pluck('scope_key')->all())->toBe(['["Žltý|kôň",null]', '["a",7]']);
});

it('keeps values that a delimiter join would merge in separate partitions', function (): void {
    Document::factory()->create(['title' => 'a|7', 'user_id' => null])->transition('go');

    $collides = Document::factory()->create(['title' => 'a', 'user_id' => 7]);
    $same = Document::factory()->create(['title' => 'a|7', 'user_id' => null]);

    expect(Lifecycles::for($collides)->can('go'))->toBeTrue()
        ->and(Lifecycles::for($same)->check('go')->codes())->toBe(['quota_exceeded']);
});

it('treats NULL and the string "null" as different partitions', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(1, 'title'));
    Document::factory()->create(['title' => 'null'])->transition('go');

    expect(Lifecycles::for(Document::factory()->create(['title' => 'null']))->can('go'))->toBeFalse()
        ->and(QuotaLock::query()->value('scope_key'))->toBe('["null"]');
});

it('caps the key at 191 bytes, counting multibyte characters by byte', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(1, 'title'));

    Document::factory()->create(['title' => str_repeat('x', 187)])->transition('go');

    expect(QuotaLock::query()->value('scope_key'))->toHaveLength(191)
        ->and(fn () => Document::factory()->create(['title' => str_repeat('x', 188)])->transition('go'))
        ->toThrow(QuotaScopeException::class, 'more than 191 characters')
        ->and(fn () => Document::factory()->create(['title' => str_repeat('ž', 94)])->transition('go'))
        ->toThrow(QuotaScopeException::class);
});
