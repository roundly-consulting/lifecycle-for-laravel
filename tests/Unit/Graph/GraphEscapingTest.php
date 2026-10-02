<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Graph\DotRenderer;
use RoundlyConsulting\Lifecycle\Graph\MermaidRenderer;

/**
 * Labels are author-supplied (translations included): a quote, backslash or line break in
 * one must stay inside its string literal and never start a statement of its own.
 */
it('escapes labels so they cannot break out of the syntax', function (): void {
    expect(MermaidRenderer::escape("a \"b\"\nc\r\nd\re"))->toBe('a #quot;b#quot; c d e')
        ->and(DotRenderer::quote("a \"b\" \\ c\nd\r\ne"))->toBe('"a \\"b\\" \\\\ c d e"')
        ->and(DotRenderer::quote('\\"'))->toBe('"\\\\\\""');
});

it('keeps hostile state labels inside their literals in both formats', function (): void {
    $hostile = "Evil\" as s9\n    s9 --> [*]\n\"; \"x\" [label=\"pwn\"]; //";
    $clean = compileLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));
    $definition = compileLifecycle(function (LifecycleBuilder $l) use ($hostile): void {
        baseLifecycle($l)->state('b')->label($hostile);
    });

    $mermaid = (new MermaidRenderer)->render($definition);
    $dot = (new DotRenderer)->render($definition);

    expect(substr_count($mermaid, "\n"))->toBe(substr_count((new MermaidRenderer)->render($clean), "\n"))
        ->and(substr_count($dot, "\n"))->toBe(substr_count((new DotRenderer)->render($clean), "\n"))
        ->and($mermaid)->toContain('    state "Evil#quot; as s9     s9 --> [*] #quot;; #quot;x#quot; [label=#quot;pwn#quot;]; //" as s1')
        ->and($dot)->toContain('    "b" [label="Evil\" as s9     s9 --> [*] \"; \"x\" [label=\"pwn\"]; //"];')
        ->and($dot)->not->toContain('"x" [label="pwn"]');
});

it('quotes state keys and the definition name in DOT', function (): void {
    $definition = compileLifecycle(function (LifecycleBuilder $l): void {
        $l->states(['a b', 'c"d'])->initial('a b');
        $l->transition('go')->from('a b')->to('c"d');
    });

    expect((new DotRenderer)->render($definition))
        ->toContain('__start -> "a b";')
        ->toContain('"a b" -> "c\"d" [label="go"];');
});
