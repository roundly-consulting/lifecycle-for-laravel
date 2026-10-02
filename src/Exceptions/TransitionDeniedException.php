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
 * A transition was refused. The message is the first denial's translated message; the
 * decision carries every denial. `retryAfterSeconds()` feeds a `Retry-After` header when
 * every denial is retryable (0 otherwise).
 */
final class TransitionDeniedException extends LifecycleException implements HasRetryAfter
{
    use ProvidesRetryAfter;

    private Decision $decision;

    public static function because(Decision $decision): self
    {
        $exception = new self($decision->first()->message ?? 'The transition is not allowed.');
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

    /**
     * A 422 for the host's controller: every denial message under `$field`, and payload
     * validation errors under their own keys.
     */
    public function toValidationException(string $field = 'transition'): ValidationException
    {
        $messages = [];

        foreach ($this->decision->denials as $denial) {
            if ($denial->errors !== []) {
                foreach ($denial->errors as $key => $errors) {
                    $messages[$key] = [...($messages[$key] ?? []), ...$errors];
                }

                continue;
            }

            $messages[$field][] = $denial->message;
        }

        return ValidationException::withMessages($messages);
    }
}
