<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\Issue;
use RoundlyConsulting\Lifecycle\Exceptions\LifecycleException;
use RoundlyConsulting\Lifecycle\Exceptions\QuotaScopeException;
use RoundlyConsulting\Lifecycle\LifecycleManager;

/**
 * Validates definitions: every error and warning, with its message. For `Model:attribute`
 * the columns the definition names (stamps, expiry attributes, quota scopes) must exist on
 * the model's table. Without arguments it validates every lifecycle of every model in
 * `lifecycle.subjects`. Exits 1 on errors — or warnings with `--strict`, where nothing to
 * validate counts as a failure too.
 */
final class ValidateCommand extends Command
{
    protected $signature = 'lifecycle:validate
        {definition?* : Definition classes or Model:attribute (default: every lifecycle of lifecycle.subjects)}
        {--strict : Fail on warnings too}';

    protected $description = 'Validate lifecycle definitions';

    public function handle(LifecycleManager $lifecycle, ResolvesLifecycleArguments $arguments, DefinitionRegistry $registry): int
    {
        $given = (array) $this->argument('definition');

        try {
            $targets = $given !== [] ? array_map(strval(...), $given) : $this->subjectTargets($lifecycle, $registry);
        } catch (LifecycleException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($targets === []) {
            $message = 'No lifecycle subjects to validate — list your models in lifecycle.subjects.';

            if ($this->option('strict') === true) {
                $this->error($message);

                return self::FAILURE;
            }

            $this->info($message);

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($targets as $target) {
            try {
                $report = $registry->validate($this->definitionClass($arguments, $registry, $target));
                $columns = str_contains($target, ':') && $report->isValid() ? $this->missingColumns($arguments, $target) : [];
            } catch (LifecycleException $exception) {
                $this->error(sprintf('%s: %s', $target, $exception->getMessage()));
                $failed = true;

                continue;
            }

            $errors = [...array_map(static fn (Issue $issue): string => $issue->code->value.': '.$issue->message(), $report->errors()), ...$columns];
            $warnings = array_map(static fn (Issue $issue): string => $issue->code->value.': '.$issue->message(), $report->warnings());

            $this->line(sprintf('<info>%s</info> — %d error(s), %d warning(s)', $target, count($errors), count($warnings)));

            foreach ($errors as $error) {
                $this->line('  <error>error</error>   '.$error);
            }

            foreach ($warnings as $warning) {
                $this->line('  <comment>warning</comment> '.$warning);
            }

            $failed = $failed || $errors !== [] || ($this->option('strict') === true && $warnings !== []);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `Model:attribute` for every lifecycle of every configured subject.
     *
     * @return list<string>
     */
    private function subjectTargets(LifecycleManager $lifecycle, DefinitionRegistry $registry): array
    {
        $targets = [];

        foreach ($lifecycle->definitions()->subjects() as $subject) {
            foreach (array_keys($registry->definitionsOf($subject)) as $attribute) {
                $targets[] = $subject.':'.$attribute;
            }
        }

        return $targets;
    }

    /**
     * The definition class behind a target, without compiling it (an invalid one must still
     * be reported, not thrown).
     */
    private function definitionClass(ResolvesLifecycleArguments $arguments, DefinitionRegistry $registry, string $target): string
    {
        if (! str_contains($target, ':')) {
            return $target;
        }

        [$model, $attribute] = explode(':', $target, 2);
        $class = $arguments->model($model);

        return $registry->definitionsOf($class)[$registry->lifecycleName($class, $attribute === '' ? null : $attribute)];
    }

    /**
     * @return list<string>
     */
    private function missingColumns(ResolvesLifecycleArguments $arguments, string $target): array
    {
        [$model] = explode(':', $target, 2);
        $class = $arguments->model($model);
        $instance = new $class;
        $definition = $arguments->definition($target);
        $schema = Schema::connection($instance->getConnectionName());
        $table = $instance->getTable();
        $missing = [];

        foreach ($definition->states as $state) {
            $columns = [...$state->stamps, ...($state->ttl?->attribute === null ? [] : [$state->ttl->attribute])];

            foreach ($state->quotas as $quota) {
                foreach ($quota->scope as $column) {
                    if (! $schema->hasColumn($table, $column)) {
                        $missing[] = 'invalid_identifier: '.QuotaScopeException::unknownColumn($quota->name, $table, $column)->getMessage();
                    }
                }
            }

            foreach ($columns as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    $missing[] = sprintf('invalid_identifier: The state [%s] names the column [%s], which the table [%s] does not have.', $state->key, $column, $table);
                }
            }
        }

        return $missing;
    }
}
