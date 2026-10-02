<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Graph\DotRenderer;
use RoundlyConsulting\Lifecycle\Graph\GraphExporter;
use RoundlyConsulting\Lifecycle\Graph\MermaidRenderer;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\TicketLifecycle;

it('renders a mermaid state diagram', function (): void {
    $graph = (new GraphExporter)->export(app(DefinitionRegistry::class)->get(ListingLifecycle::class), GraphFormat::Mermaid);

    expect($graph)->toBe(<<<'MERMAID'
        stateDiagram-v2
            state "Draft" as s0
            state "Active" as s1
            state "Closed" as s2
            state "Expired" as s3
            state "Archived" as s4
            [*] --> s0
            s0 --> s1: publish
            s1 --> s2: close
            s2 --> s1: reopen
            s1 --> s3: expire ⚙
            s3 --> s1: reactivate
            s0 --> s4: archive
            s1 --> s4: archive
            s2 --> s4: archive
            s3 --> s4: archive
            s4 --> [*]
            note right of s1: ttl 30 days · grace 3 days

        MERMAID);
});

it('renders a dot graph', function (): void {
    $graph = (new GraphExporter)->export(app(DefinitionRegistry::class)->get(TicketLifecycle::class), GraphFormat::Dot);

    expect($graph)->toBe(<<<'DOT'
        digraph "TicketLifecycle" {
            rankdir=LR;
            node [shape=box, style=rounded];
            __start [shape=point];
            __start -> "new";
            "new" [label="New"];
            "open" [label="Open"];
            "waiting" [label="Waiting on \"customer\""];
            "resolved" [label="Resolved!"];
            "closed" [label="Closed", peripheries=2];
            "new" -> "open" [label="open"];
            "open" -> "waiting" [label="wait"];
            "waiting" -> "waiting" [label="nudge"];
            "open" -> "resolved" [label="resolve"];
            "waiting" -> "resolved" [label="resolve"];
            "resolved" -> "open" [label="reopen"];
            "open" -> "closed" [label="close", style=dashed];
            "waiting" -> "closed" [label="close", style=dashed];
            "resolved" -> "closed" [label="close", style=dashed];
        }

        DOT);
});

it('notes every state constraint', function (): void {
    $definition = compileLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly()->ignoresSeal();
        $l->state('b')->expiresAtAttribute('ends_at')->expiresVia('lapse')->quota(3)->minDwell('1 hour')->sealedAfter('2 days');
        $l->transition('lapse_a')->from('a')->to('c')->systemOnly();
        $l->state('a')->ttl(fn () => null)->expiresVia('lapse_a');
    });

    expect((new MermaidRenderer)->render($definition))
        ->toContain('note right of s1: expires at ends_at · quota · min dwell 1 hour · sealed after 2 days')
        ->toContain('note right of s0: ttl dynamic');
});

it('uses the configured default format', function (): void {
    $definition = app(DefinitionRegistry::class)->get(ListingLifecycle::class);

    expect((new GraphExporter)->export($definition))->toStartWith('stateDiagram-v2');

    config()->set('lifecycle.graph.default_format', 'dot');

    expect((new GraphExporter)->export($definition))->toStartWith('digraph "ListingLifecycle"')
        ->and(GraphExporter::defaultFormat())->toBe(GraphFormat::Dot);
});

it('rejects an unknown default format', function (): void {
    config()->set('lifecycle.graph.default_format', 'svg');

    GraphExporter::defaultFormat();
})->throws(InvalidLifecycleConfigurationException::class);

it('renders a namespace-less definition name', function (): void {
    expect((new DotRenderer)->render(compileLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l))))
        ->toStartWith('digraph "Inline" {');
});
