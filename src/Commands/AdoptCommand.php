<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use Throwable;

/**
 * Reconciles every row of a model with its lifecycle: missing records are created, states
 * written outside the engine adopted, NULL states initialised. For tables that existed before
 * the lifecycle, and after bulk writes.
 */
final class AdoptCommand extends Command
{
    protected $signature = 'lifecycle:adopt
        {model : Model class name or morph alias}
        {--lifecycle= : The lifecycle attribute (default: the first)}
        {--chunk=500 : Rows per chunk}
        {--no-expiry : Do not schedule expiries for adopted states}';

    protected $description = 'Adopt the stored state of every row of a model';

    public function handle(LifecycleManager $lifecycle, ResolvesLifecycleArguments $arguments): int
    {
        try {
            $chunk = IntegerOption::parse($this->option('chunk'), 'chunk') ?? 500;
            $option = $this->option('lifecycle');
            $count = $lifecycle->adoptAll(
                $arguments->model(ResolvesLifecycleArguments::string($this->argument('model'))),
                is_string($option) && $option !== '' ? $option : null,
                $chunk,
                $this->option('no-expiry') !== true,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Adopted %d subject(s).', $count));

        return self::SUCCESS;
    }
}
