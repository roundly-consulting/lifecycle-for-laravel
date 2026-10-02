<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

use RoundlyConsulting\Lifecycle\Enums\IssueCode;
use RoundlyConsulting\Lifecycle\Enums\IssueSeverity;

/**
 * One finding of the definition validator.
 */
final readonly class Issue
{
    public IssueSeverity $severity;

    /**
     * @param  array<string, scalar>  $params
     */
    public function __construct(
        public IssueCode $code,
        public ?string $path = null,
        public array $params = [],
    ) {
        $this->severity = $code->severity();
    }

    public function isError(): bool
    {
        return $this->severity === IssueSeverity::Error;
    }

    public function message(): string
    {
        $message = trans('lifecycle::issues.'.$this->code->value, [...$this->params, 'path' => (string) $this->path]);

        return is_string($message) ? $message : $this->code->value;
    }
}
