<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRecord;

/**
 * A host's own actor resource, swapped in via `lifecycle.resources.actor`.
 *
 * @property TransitionRecord $resource
 */
final class HostActorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource->actorId,
            'label' => 'Member #'.$this->resource->actorId,
            'secret' => $this->when(false, 'never rendered'),
        ];
    }
}
