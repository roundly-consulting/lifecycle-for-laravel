<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use RoundlyConsulting\Lifecycle\Definition\Issue;
use RoundlyConsulting\Lifecycle\Definition\ValidationReport;

/**
 * A lifecycle definition has at least one error; the attached report lists them all.
 */
final class InvalidLifecycleDefinitionException extends LifecycleException
{
    private ValidationReport $report;

    public static function fromReport(ValidationReport $report): self
    {
        $lines = array_map(
            static fn (Issue $issue): string => '- '.$issue->code->value.($issue->path === null ? '' : ' ('.$issue->path.')').': '.$issue->message(),
            $report->errors(),
        );

        $exception = new self(sprintf(
            "The lifecycle definition [%s] is invalid:\n%s",
            $report->definition,
            implode("\n", $lines),
        ));

        $exception->report = $report;

        return $exception;
    }

    public function report(): ValidationReport
    {
        return $this->report;
    }
}
