<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;

/**
 * Why one check refused a transition: a stable code, a translated message, the parameters
 * the message was built from, and — for time-bound denials — when retrying can succeed.
 */
final readonly class Denial
{
    /**
     * @param  array<string, scalar>  $params
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $params = [],
        public ?CarbonImmutable $retryAfter = null,
        public array $errors = [],
        public ?string $source = null,
    ) {}

    /**
     * The message is the explicit one when given, else `lifecycle::denials.{code}` when that
     * key exists (hosts translate custom codes in `lang/vendor/lifecycle`), else the generic
     * `guard_failed` text — a custom code never renders as a raw translation key.
     *
     * @param  array<string, scalar>  $params
     * @param  array<string, list<string>>  $errors
     */
    public static function of(
        DenialCode|string $code,
        array $params = [],
        ?string $message = null,
        ?CarbonImmutable $retryAfter = null,
        array $errors = [],
        ?string $source = null,
    ): self {
        $code = $code instanceof DenialCode ? $code->value : $code;

        return new self(
            code: $code,
            message: $message ?? self::translate($code, $params),
            params: $params,
            retryAfter: $retryAfter,
            errors: $errors,
            source: $source,
        );
    }

    public function is(DenialCode|string $code): bool
    {
        return $this->code === ($code instanceof DenialCode ? $code->value : $code);
    }

    public function denialCode(): ?DenialCode
    {
        return DenialCode::tryFrom($this->code);
    }

    /**
     * Package codes are retryable per {@see DenialCode::isRetryable()}; a custom guard code is
     * retryable only when it says when (a non-null `retryAfter`).
     */
    public function isRetryable(): bool
    {
        $known = $this->denialCode();

        return $known === null ? $this->retryAfter !== null : $known->isRetryable();
    }

    /**
     * @return array{code: string, message: string, params: array<string, scalar>, retry_after: string|null, errors: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'params' => $this->params,
            'retry_after' => $this->retryAfter?->toIso8601String(),
            'errors' => $this->errors,
        ];
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private static function translate(string $code, array $params): string
    {
        $translator = App::make(Translator::class);
        $replace = array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $params);

        $key = 'lifecycle::denials.'.$code;

        if (! $translator->has($key)) {
            $key = 'lifecycle::denials.'.DenialCode::GuardFailed->value;
        }

        $message = $translator->get($key, $replace);

        return is_string($message) ? $message : $code;
    }
}
