<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Http\Resources;

use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRecord;

/**
 * One history row for an API. Context and snapshot stay out unless `withContext()`.
 *
 * @property TransitionRecord $resource
 */
final class TransitionRecordResource extends JsonResource
{
    private bool $withContext = false;

    public function __construct(TransitionRecord $record)
    {
        parent::__construct($record);
    }

    public function withContext(): self
    {
        $this->withContext = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        return [
            'id' => $record->id,
            'kind' => $record->kind->value,
            'transition' => $record->transition,
            'from' => self::raw($record->from),
            'to' => self::raw($record->to),
            'actor' => $record->actorType === null ? null : ['type' => $record->actorType, 'id' => $record->actorId],
            'system' => $record->system,
            'reason' => $record->reason,
            'occurred_at' => $record->occurredAt->toIso8601String(),
            'reverted' => $record->reverted,
            ...($this->withContext ? ['context' => $record->context, 'snapshot' => $record->snapshot] : []),
        ];
    }

    public static function raw(BackedEnum|string|null $state): string|int|null
    {
        return $state instanceof BackedEnum ? $state->value : $state;
    }
}
