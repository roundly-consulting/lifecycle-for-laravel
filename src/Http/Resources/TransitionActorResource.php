<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Http\Resources;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRecord;

/**
 * Who performed a history row, for an API: `{id, type}`, where `type` is the actor model's class
 * basename (`User`) — never a class name or a morph alias. `TransitionRecordResource` renders its
 * `actor` through the class configured as `lifecycle.resources.actor`, and only for a row with an
 * actor.
 *
 * It wraps the {@see TransitionRecord}, not the actor model: history is rendered without loading
 * any actor (no query per row). A host resource swapped in via the config receives the same
 * record and resolves the actor its own way (`actorType` / `actorId`), e.g. from a map it
 * eager-loaded for the page.
 *
 * @property TransitionRecord $resource
 */
final class TransitionActorResource extends JsonResource
{
    public function __construct(TransitionRecord $record)
    {
        parent::__construct($record);
    }

    /**
     * @return array{id: int|string|null, type: string|null}
     */
    public function toArray(Request $request): array
    {
        $type = $this->resource->actorType;

        return [
            'id' => $this->resource->actorId,
            'type' => $type === null ? null : class_basename(Relation::getMorphedModel($type) ?? $type),
        ];
    }
}
