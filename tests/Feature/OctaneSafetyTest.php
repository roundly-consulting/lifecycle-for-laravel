<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\CompensatingHandler;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

/**
 * Nothing request-scoped outlives a request: definitions compile once (built with `new`,
 * no container), while handlers, guards and the Gate are resolved per call.
 */
it('compiles a definition once per process', function (): void {
    $compiles = 0;
    defineDocumentLifecycle(function (LifecycleBuilder $l) use (&$compiles): void {
        $compiles++;
        baseLifecycle($l);
    });

    $document = Document::factory()->create();
    Lifecycles::for($document)->can('go');
    $document->transition('go');

    expect($compiles)->toBe(1)
        ->and(app(DefinitionRegistry::class))->toBe(app(DefinitionRegistry::class));
});

it('honours a container rebind between two calls', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle(
        $l,
        go: fn (TransitionBuilder $go) => $go->handledBy(CompensatingHandler::class),
        finish: fn (TransitionBuilder $finish) => $finish->handledBy(CompensatingHandler::class),
    ));
    $first = new CompensatingHandler;
    $second = new CompensatingHandler;
    $document = Document::factory()->create();

    app()->instance(CompensatingHandler::class, $first);
    $document->transition('go');
    app()->instance(CompensatingHandler::class, $second);
    $document->transition('finish');

    expect($first->calls)->toBe(['handle:go'])
        ->and($second->calls)->toBe(['handle:finish']);
});

it('asks the current Gate on every check', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->ability('go')));
    $document = Document::factory()->create();
    $user = User::factory()->create();

    Gate::define('go', fn (): bool => false);
    $denied = Lifecycles::for($document)->by($user)->can('go');
    Gate::define('go', fn (): bool => true);

    expect($denied)->toBeFalse()
        ->and(Lifecycles::for($document)->by($user)->can('go'))->toBeTrue();
});
