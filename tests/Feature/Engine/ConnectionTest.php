<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\SecondaryDocument;

/**
 * Package rows live in the subject's database and are written in the subject's transaction.
 */
beforeEach(function (): void {
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);

    foreach ([__DIR__.'/../../../database/migrations', __DIR__.'/../../Fixtures/migrations'] as $path) {
        Artisan::call('migrate', ['--database' => 'secondary', '--path' => $path, '--realpath' => true]);
    }
});

it('writes package rows on the subject connection only', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));
    $document = SecondaryDocument::query()->create();

    $document->transition('go');

    expect(DB::connection('secondary')->table('lifecycle_transitions')->count())->toBe(2)
        ->and(DB::connection('secondary')->table('lifecycle_states')->value('state'))->toBe('b')
        ->and(LifecycleState::query()->count())->toBe(0);
});

it('rolls back on both connections when the handler throws', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->handledBy(function (TransitionContext $c): never {
        throw new RuntimeException('nope');
    })));
    $document = SecondaryDocument::query()->create();

    expect(fn () => $document->transition('go'))->toThrow(RuntimeException::class);

    expect(DB::connection('secondary')->table('lifecycle_transitions')->count())->toBe(1)
        ->and(DB::connection('secondary')->table('documents')->value('status'))->toBe('a')
        ->and(DB::table('lifecycle_transitions')->count())->toBe(0);
});
