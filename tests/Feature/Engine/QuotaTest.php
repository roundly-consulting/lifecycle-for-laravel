<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\QuotaScopeException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Models\QuotaLock;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\TenantDocument;

function quotaLifecycle(int|Closure $max, string|array $scope = 'user_id'): void
{
    defineDocumentLifecycle(function (LifecycleBuilder $l) use ($max, $scope): void {
        baseLifecycle($l)->state('b')->quota($max, $scope);
    });
}

it('refuses entering a state that is at its quota for the partition', function (): void {
    quotaLifecycle(2);
    [$first, $second, $third] = Document::factory()->count(3)->create(['user_id' => 7])->all();
    $other = Document::factory()->create(['user_id' => 8]);

    $first->transition('go');
    $second->transition('go');
    $decision = Lifecycles::for($third)->check('go');

    expect($decision->codes())->toBe(['quota_exceeded'])
        ->and($decision->first()?->params)->toMatchArray(['max' => 2, 'current' => 2])
        ->and($decision->first()?->message)->toBe('The limit of 2 for "B" has been reached.')
        ->and($decision->isRetryable())->toBeTrue()
        ->and($other->transition('go')->to)->toBe('b')
        ->and(QuotaLock::query()->count())->toBe(2); // one mutex row per partition
});

it('counts NULL scope values as one partition', function (): void {
    quotaLifecycle(1);
    [$first, $second] = Document::factory()->count(2)->create(['user_id' => null])->all();

    $first->transition('go');

    expect(Lifecycles::for($second)->can('go'))->toBeFalse();
});

it('ignores soft-deleted rows and global scopes when counting', function (): void {
    quotaLifecycle(1);
    $trashed = Document::factory()->create(['user_id' => 1]);
    $trashed->transition('go');
    $trashed->delete();

    $tenant = TenantDocument::query()->create(['user_id' => 2]);
    $tenant->transition('go');
    $scoped = TenantDocument::query()->create(['user_id' => 2]);

    expect(Document::factory()->create(['user_id' => 1])->transition('go')->to)->toBe('b')
        ->and(Lifecycles::for($scoped)->can('go'))->toBeFalse();
});

it('evaluates a closure maximum per check', function (): void {
    quotaLifecycle(fn (Document $document): int => $document->quantity ?? 0);
    $document = Document::factory()->create(['user_id' => 1, 'quantity' => 1]);
    Document::factory()->create(['user_id' => 1, 'quantity' => 5])->transition('go');

    expect(Lifecycles::for($document)->can('go'))->toBeFalse();

    $document->update(['quantity' => 2]);

    expect(Lifecycles::for($document)->can('go'))->toBeTrue();
});

it('denies everyone at a zero maximum without counting', function (): void {
    quotaLifecycle(0);

    expect(Lifecycles::for(Document::factory()->create())->check('go')->first()?->params)->toMatchArray(['max' => 0, 'current' => 0]);
});

it('refuses a negative closure maximum and an oversized scope', function (): void {
    quotaLifecycle(fn (): int => -1);

    expect(fn () => Lifecycles::for(Document::factory()->create())->check('go'))
        ->toThrow(InvalidLifecycleUsageException::class, 'negative maximum (-1)');

    quotaLifecycle(1, 'title');

    expect(fn () => Lifecycles::for(Document::factory()->create(['title' => str_repeat('x', 200)]))->check('go'))
        ->toThrow(QuotaScopeException::class, 'more than 191 characters');
});

it('checks every quota of the target state', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->state('b')->quota(5, 'user_id')->quota(1, [], 'global');
    });
    Document::factory()->create(['user_id' => 1])->transition('go');

    expect(Lifecycles::for(Document::factory()->create(['user_id' => 2]))->check('go')->codes())->toBe(['quota_exceeded']);
});

it('refuses a transition into a quota\'d state that also moves the subject to another partition', function (): void {
    quotaLifecycle(1);
    Document::factory()->create(['user_id' => 2])->transition('go');
    $mover = Document::factory()->create(['user_id' => 1]);
    $mover->user_id = 2;

    expect(fn () => $mover->transition('go'))->toThrow(InvalidLifecycleUsageException::class, 'user_id')
        ->and($mover->fresh()?->status)->toBe('a')
        ->and($mover->fresh()?->user_id)->toBe(1)
        ->and($mover->user_id)->toBe(2)
        ->and(Document::query()->where('status', 'b')->where('user_id', 2)->count())->toBe(1);
});

it('refuses a handler that moves the subject to another quota partition', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->handledBy(function (TransitionContext $c): void {
            $c->subject->setAttribute('user_id', 2);
        }))->state('b')->quota(1, 'user_id'));
    $occupant = Document::factory()->create(['user_id' => 2]);
    Lifecycles::allowDirectWrites(fn () => $occupant->update(['status' => 'b']));
    $mover = Document::factory()->create(['user_id' => 1]);

    expect(fn () => $mover->transition('go'))->toThrow(InvalidLifecycleUsageException::class, 'user_id')
        ->and($mover->fresh()?->status)->toBe('a')
        ->and(Document::query()->where('status', 'b')->where('user_id', 2)->count())->toBe(1);
});

it('lets a self-transition or a state without quotas change a scope column', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->state('b')->quota(5, 'user_id');
        $l->transition('stay')->from('b')->to('b')->allowSelf();
    });
    $document = Document::factory()->create(['user_id' => 1]);
    $document->transition('go');
    $document->user_id = 3;
    $document->transition('stay');
    $document->user_id = 4;
    $document->transition('finish');

    expect($document->fresh()?->user_id)->toBe(4);
});

it('does not count a restore of a soft-deleted subject, as documented', function (): void {
    quotaLifecycle(1);
    $first = Document::factory()->create(['user_id' => 1]);
    $first->transition('go');
    $first->delete();
    Document::factory()->create(['user_id' => 1])->transition('go');

    $first->restore();

    expect(Document::query()->where('status', 'b')->where('user_id', 1)->count())->toBe(2);
});
