<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectContextUnavailableException;
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
            $skipped = 0;
            $count = $lifecycle->adoptAll(
                $arguments->model(ResolvesLifecycleArguments::string($this->argument('model'))),
                is_string($option) && $option !== '' ? $option : null,
                $chunk,
                $this->option('no-expiry') !== true,
                function (Model $subject, SubjectContextUnavailableException $exception) use (&$skipped): void {
                    $skipped++;
                    $this->warn('Skipped: '.$exception->getMessage());
                },
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Adopted %d subject(s).', $count));

        if ($skipped > 0) {
            $this->warn(sprintf('Skipped %d subject(s) whose tenant context could not be restored (their team was deleted or is missing).', $skipped));
        }

        return self::SUCCESS;
    }
}
