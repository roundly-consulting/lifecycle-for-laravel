<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Closure;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;

/**
 * Adapts a closure guard: `true`/`null` allow, `false` denies with the declared code and
 * message (`guard_failed` by default), a Denial is passed through.
 *
 * @internal
 */
final readonly class ClosureGuard implements Guard
{
    public function __construct(
        public Closure $closure,
        public ?string $code = null,
        public ?string $message = null,
    ) {}

    public function check(TransitionContext $context): ?Denial
    {
        $result = ($this->closure)($context);

        return match (true) {
            $result === null, $result === true => null,
            $result instanceof Denial => $result,
            $result === false => Denial::of($this->code ?? DenialCode::GuardFailed, message: $this->message, source: 'guard'),
            default => throw InvalidLifecycleUsageException::invalidGuardResult($result),
        };
    }
}
