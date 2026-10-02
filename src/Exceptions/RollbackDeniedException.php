<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Decision;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Support\Clock;
use RoundlyConsulting\PackageToolkit\Concerns\ProvidesRetryAfter;
use RoundlyConsulting\PackageToolkit\Contracts\HasRetryAfter;

/**
 * A rollback was refused; nothing changed. Same API as TransitionDeniedException.
 */
final class RollbackDeniedException extends LifecycleException implements HasRetryAfter
{
    use ProvidesRetryAfter;

    private Decision $decision;

    public static function because(Decision $decision): self
    {
        $exception = new self($decision->first()->message ?? 'The rollback is not allowed.');
        $exception->decision = $decision;

        if ($decision->retryAfter !== null) {
            $exception->withRetryAfter(max(0, $decision->retryAfter->getTimestamp() - Clock::now()->getTimestamp()));
        }

        return $exception;
    }

    public function decision(): Decision
    {
        return $this->decision;
    }

    /**
     * @return list<Denial>
     */
    public function denials(): array
    {
        return $this->decision->denials;
    }

    public function toValidationException(string $field = 'rollback'): ValidationException
    {
        return ValidationException::withMessages([$field => $this->decision->messages()]);
    }
}
