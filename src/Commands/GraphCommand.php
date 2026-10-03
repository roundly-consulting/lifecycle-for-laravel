<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\LifecycleException;
use RoundlyConsulting\Lifecycle\LifecycleManager;

/**
 * Prints (or writes) a lifecycle as a Mermaid state diagram or a DOT graph.
 */
final class GraphCommand extends Command
{
    protected $signature = 'lifecycle:graph
        {definition : A definition class, or Model:attribute (class name or morph alias)}
        {--format= : mermaid or dot (default: graph.default_format)}
        {--output= : Write the graph to this file instead of printing it}';

    protected $description = 'Export a lifecycle definition as a Mermaid or DOT graph';

    public function handle(LifecycleManager $lifecycle, ResolvesLifecycleArguments $arguments): int
    {
        $format = $this->option('format');
        $graphFormat = is_string($format) && $format !== '' ? GraphFormat::tryFrom($format) : null;

        if (is_string($format) && $format !== '' && $graphFormat === null) {
            $this->error(sprintf('Unknown format [%s]; use mermaid or dot.', $format));

            return self::FAILURE;
        }

        try {
            $graph = $lifecycle->definitions()->render($arguments->definition(ResolvesLifecycleArguments::string($this->argument('definition'))), $graphFormat);
        } catch (LifecycleException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            $directory = dirname($output);
            $writable = is_dir($directory) && (file_exists($output) ? is_writable($output) : is_writable($directory));

            if (! $writable || file_put_contents($output, $graph) === false) {
                $this->error(sprintf('Cannot write the graph to %s.', $output));

                return self::FAILURE;
            }

            $this->info(sprintf('Written to %s.', $output));

            return self::SUCCESS;
        }

        $this->output->write($graph);

        return self::SUCCESS;
    }
}
