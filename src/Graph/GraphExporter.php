<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Graph;

use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Renders a compiled definition as a Mermaid state diagram or a DOT graph. Output is
 * deterministic: declaration order, wildcards expanded.
 *
 * @internal
 */
final readonly class GraphExporter
{
    public function export(CompiledDefinition $definition, ?GraphFormat $format = null): string
    {
        $format ??= self::defaultFormat();

        return match ($format) {
            GraphFormat::Mermaid => (new MermaidRenderer)->render($definition),
            GraphFormat::Dot => (new DotRenderer)->render($definition),
        };
    }

    public static function defaultFormat(): GraphFormat
    {
        return Config::using(InvalidLifecycleConfigurationException::class)
            ->enum('lifecycle.graph.default_format', GraphFormat::class, GraphFormat::Mermaid);
    }
}
