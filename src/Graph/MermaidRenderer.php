<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Graph;

use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\StateDefinition;
use RoundlyConsulting\Lifecycle\Support\Durations;

/**
 * `stateDiagram-v2` output. State ids are `s{n}` in declaration order; labels are escaped
 * (`"` → `#quot;`, newlines → space); `⚙` marks system-only transitions.
 *
 * @internal
 */
final readonly class MermaidRenderer
{
    public function render(CompiledDefinition $definition): string
    {
        $ids = [];
        $lines = ['stateDiagram-v2'];

        foreach ($definition->states as $index => $state) {
            $ids['s'.$state->key] = 's'.$index;
            $lines[] = sprintf('    state "%s" as s%d', self::escape($state->label()), $index);
        }

        $lines[] = '    [*] --> '.$ids['s'.$definition->initial];

        foreach ($definition->transitions as $transition) {
            foreach ($transition->from as $from) {
                $lines[] = sprintf(
                    '    %s --> %s: %s%s',
                    $ids['s'.$from],
                    $ids['s'.$transition->to],
                    self::escape($transition->name),
                    $transition->systemOnly ? ' ⚙' : '',
                );
            }
        }

        foreach ($definition->states as $state) {
            if ($state->terminal) {
                $lines[] = '    '.$ids['s'.$state->key].' --> [*]';
            }
        }

        foreach ($definition->states as $state) {
            $note = self::note($state);

            if ($note !== '') {
                $lines[] = '    note right of '.$ids['s'.$state->key].': '.$note;
            }
        }

        return implode("\n", $lines)."\n";
    }

    public static function escape(string $text): string
    {
        return str_replace(['"', "\r\n", "\n", "\r"], ['#quot;', ' ', ' ', ' '], $text);
    }

    private static function note(StateDefinition $state): string
    {
        $parts = [];

        if ($state->ttl !== null) {
            $parts[] = match (true) {
                $state->ttl->attribute !== null => 'expires at '.$state->ttl->attribute,
                $state->ttl->interval !== null => 'ttl '.Durations::describe($state->ttl->interval),
                default => 'ttl dynamic',
            };

            if ($state->ttl->grace !== null) {
                $parts[] = 'grace '.Durations::describe($state->ttl->grace);
            }
        }

        if ($state->quotas !== []) {
            $parts[] = 'quota';
        }

        if ($state->minDwell !== null) {
            $parts[] = 'min dwell '.Durations::describe($state->minDwell);
        }

        if ($state->sealedAfter !== null) {
            $parts[] = 'sealed after '.Durations::describe($state->sealedAfter);
        }

        return implode(' · ', $parts);
    }
}
