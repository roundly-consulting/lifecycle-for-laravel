<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Graph;

use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;

/**
 * Graphviz DOT output: rounded boxes, a point for the start, dashed system-only edges and
 * double-bordered terminal states. Ids and labels are quoted with `"` and `\` escaped.
 *
 * @internal
 */
final readonly class DotRenderer
{
    public function render(CompiledDefinition $definition): string
    {
        $name = substr(strrchr('\\'.$definition->class, '\\') ?: $definition->class, 1);

        $lines = [
            sprintf('digraph %s {', self::quote($name)),
            '    rankdir=LR;',
            '    node [shape=box, style=rounded];',
            '    __start [shape=point];',
            sprintf('    __start -> %s;', self::quote($definition->initial)),
        ];

        foreach ($definition->states as $state) {
            $lines[] = sprintf(
                '    %s [label=%s%s];',
                self::quote($state->key),
                self::quote($state->label()),
                $state->terminal ? ', peripheries=2' : '',
            );
        }

        foreach ($definition->transitions as $transition) {
            foreach ($transition->from as $from) {
                $lines[] = sprintf(
                    '    %s -> %s [label=%s%s];',
                    self::quote($from),
                    self::quote($transition->to),
                    self::quote($transition->name),
                    $transition->systemOnly ? ', style=dashed' : '',
                );
            }
        }

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    public static function quote(string $text): string
    {
        return '"'.str_replace(['\\', '"', "\r\n", "\n", "\r"], ['\\\\', '\\"', ' ', ' ', ' '], $text).'"';
    }
}
