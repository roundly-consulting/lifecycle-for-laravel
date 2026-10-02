<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Engine;

use Illuminate\Database\Eloquent\Model;

/**
 * The in-memory subject as the caller handed it over. A failed mutation — and every retried
 * transaction attempt — puts both attribute arrays back and forgets the package relations,
 * so the caller never holds a half-applied model.
 *
 * @internal
 */
final readonly class RestorePoint
{
    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $attributes
     */
    private function __construct(
        private Model $subject,
        private array $original,
        private array $attributes,
    ) {}

    public static function capture(Model $subject): self
    {
        return new self($subject, $subject->getRawOriginal(), $subject->getAttributes());
    }

    public function restore(): void
    {
        $this->subject->setRawAttributes($this->original, true);
        $this->subject->setRawAttributes($this->attributes);
        self::forgetRelations($this->subject);
    }

    public static function forgetRelations(Model $subject): void
    {
        $subject->unsetRelation('lifecycleStates');
        $subject->unsetRelation('lifecycleSchedules');
        $subject->unsetRelation('lifecycleHistory');
    }
}
