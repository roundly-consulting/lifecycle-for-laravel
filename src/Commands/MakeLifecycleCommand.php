<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Commands;

use BackedEnum;
use Illuminate\Console\GeneratorCommand;

/**
 * Generates a lifecycle definition class in `App\Lifecycles`: a small valid example with
 * string states, or — with `--enum` — the states of a backed enum, starting in its first case.
 * A published `stubs/lifecycle.stub` / `stubs/lifecycle.enum.stub` in the host wins.
 */
final class MakeLifecycleCommand extends GeneratorCommand
{
    protected $signature = 'make:lifecycle
        {name : The definition class, e.g. ListingLifecycle}
        {--enum= : A backed enum class whose cases are the states}
        {--force : Overwrite the class if it already exists}';

    protected $description = 'Create a lifecycle definition class';

    protected $type = 'Lifecycle';

    /**
     * @var class-string<BackedEnum>|null
     */
    private ?string $enum = null;

    /**
     * An unusable `--enum` fails (exit 1); an existing class without `--force` is refused like
     * every `make:*` command.
     */
    public function handle(): ?bool
    {
        $enum = $this->option('enum');

        if (is_string($enum) && $enum !== '') {
            $enum = ltrim($enum, '\\');

            if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
                $this->fail(sprintf('The enum [%s] does not exist or is not a backed enum.', $enum));
            }

            if ($enum::cases() === []) {
                $this->fail(sprintf('The enum [%s] has no cases.', $enum));
            }

            $this->enum = $enum;
        }

        if (parent::handle() === false) {
            return false;
        }

        $this->line("Add it to your model's lifecycleDefinitions() and to lifecycle.subjects, then run php artisan lifecycle:validate.");

        return null;
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath($this->enum === null ? '/stubs/lifecycle.stub' : '/stubs/lifecycle.enum.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Lifecycles';
    }

    /**
     * @param  string  $name
     */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        if ($this->enum === null) {
            return $stub;
        }

        $first = $this->enum::cases()[0];

        return str_replace(
            ['{{ enum }}', '{{ enumBasename }}', '{{ initialCase }}'],
            [$this->enum, class_basename($this->enum), $first->name],
            $stub,
        );
    }

    /**
     * A host's published stub wins over the package's.
     */
    private function resolveStubPath(string $stub): string
    {
        $published = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($published) ? $published : __DIR__.$stub;
    }
}
