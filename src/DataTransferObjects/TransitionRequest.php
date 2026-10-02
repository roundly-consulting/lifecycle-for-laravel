<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\DataTransferObjects;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;

/**
 * One request to check or apply a transition: by name (`transition`) or by target state
 * (`target`) — exactly one of them. System context comes only from code (`system: true`);
 * there is no array factory, so input can never forge it.
 */
final readonly class TransitionRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Model $subject,
        public string $lifecycle,
        public ?string $transition = null,
        public BackedEnum|string|int|null $target = null,
        public ?Model $actor = null,
        public bool $system = false,
        public ?string $reason = null,
        public array $payload = [],
        public ?int $expectedVersion = null,
        public ?string $idempotencyKey = null,
    ) {
        if (($transition === null) === ($target === null)) {
            throw InvalidLifecycleUsageException::invalidRequest('name exactly one of a transition or a target state');
        }

        if ($idempotencyKey !== null && ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191)) {
            throw InvalidLifecycleUsageException::invalidRequest('an idempotency key has 1 to 191 characters');
        }
    }
}
