<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use RoundlyConsulting\Lifecycle\Enums\IssueCode;

/**
 * Every error and warning found in one definition. Never thrown by itself — compiling a
 * definition with errors throws InvalidLifecycleDefinitionException carrying the report.
 */
final readonly class ValidationReport
{
    /**
     * @param  list<Issue>  $issues
     */
    public function __construct(
        public string $definition,
        public array $issues,
    ) {}

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return list<Issue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $issue): bool => $issue->isError()));
    }

    /**
     * @return list<Issue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $issue): bool => ! $issue->isError()));
    }

    public function has(IssueCode $code): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }
}
