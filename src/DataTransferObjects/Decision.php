<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;

/**
 * The answer of a check: allowed, or every denial that applies.
 */
final readonly class Decision
{
    /**
     * @param  list<Denial>  $denials
     */
    public function __construct(
        public bool $allowed,
        public array $denials = [],
        public ?CarbonImmutable $retryAfter = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    /**
     * `retryAfter` is the latest instant among the denials when every one of them is
     * retryable — otherwise retrying cannot help and it stays null.
     */
    public static function deny(Denial ...$denials): self
    {
        $denials = array_values($denials);
        $retryAfter = null;

        foreach ($denials as $denial) {
            if (! $denial->isRetryable()) {
                return new self(false, $denials);
            }

            if ($denial->retryAfter !== null && ($retryAfter === null || $denial->retryAfter->greaterThan($retryAfter))) {
                $retryAfter = $denial->retryAfter;
            }
        }

        return new self(false, $denials, $retryAfter);
    }

    /**
     * @param  list<Denial>  $denials
     */
    public static function from(array $denials): self
    {
        return $denials === [] ? self::allow() : self::deny(...$denials);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    public function has(DenialCode|string $code): bool
    {
        return $this->find($code) !== null;
    }

    public function find(DenialCode|string $code): ?Denial
    {
        foreach ($this->denials as $denial) {
            if ($denial->is($code)) {
                return $denial;
            }
        }

        return null;
    }

    public function first(): ?Denial
    {
        return $this->denials[0] ?? null;
    }

    /**
     * Whether retrying later can succeed: denied, and every denial is retryable.
     */
    public function isRetryable(): bool
    {
        if ($this->allowed) {
            return false;
        }

        foreach ($this->denials as $denial) {
            if (! $denial->isRetryable()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (Denial $denial): string => $denial->code, $this->denials);
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (Denial $denial): string => $denial->message, $this->denials);
    }

    /**
     * @return array{allowed: bool, retry_after: string|null, denials: list<array{code: string, message: string, params: array<string, scalar>, retry_after: string|null, errors: array<string, list<string>>}>}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'retry_after' => $this->retryAfter?->toIso8601String(),
            'denials' => array_map(static fn (Denial $denial): array => $denial->toArray(), $this->denials),
        ];
    }
}
