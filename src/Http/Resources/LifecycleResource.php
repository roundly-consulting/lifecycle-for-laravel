<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Enums\ScheduleKind;
use RoundlyConsulting\Lifecycle\LifecycleHandle;

/**
 * One lifecycle of a subject for an API: state, freeze, expiry and every transition leaving
 * the current state — refused ones too, with their reasons, so a UI can show disabled
 * buttons. Checked for the handle's actor (`Lifecycles::for($listing)->by($user)`).
 *
 * @property LifecycleHandle $resource
 */
final class LifecycleResource extends JsonResource
{
    public function __construct(LifecycleHandle $handle)
    {
        parent::__construct($handle);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $handle = $this->resource;
        $definition = $handle->definition();
        $key = $definition->key($handle->state());
        $expiry = null;

        foreach ($handle->scheduled() as $scheduled) {
            if ($scheduled->kind === ScheduleKind::Expiry) {
                $expiry = $scheduled;
            }
        }

        $last = $handle->lastTransition();

        return [
            'lifecycle' => $handle->lifecycle,
            'state' => $definition->encode($key),
            'state_label' => $definition->stateLabel($key),
            'terminal' => $definition->isTerminal($key),
            'entered_at' => $handle->enteredAt()?->toIso8601String(),
            'version' => $handle->version(),
            'frozen' => $handle->isFrozen()
                ? ['until' => $handle->frozenUntil()?->toIso8601String(), 'reason' => $handle->frozenReason()]
                : null,
            'expiry' => $expiry === null ? null : [
                'expires_at' => $expiry->expiresAt?->toIso8601String(),
                'due_at' => $expiry->dueAt->toIso8601String(),
                'in_grace' => $handle->isInGrace(),
            ],
            'allowed_transitions' => array_map(static fn (AvailableTransition $transition): array => [
                'name' => $transition->name,
                'label' => $transition->label,
                'to' => TransitionRecordResource::raw($transition->to),
                'to_label' => $transition->toLabel,
                'allowed' => $transition->allowed,
                'denials' => array_map(static fn (Denial $denial): array => $denial->toArray(), $transition->denials),
                'requires_reason' => $transition->requiresReason,
                'payload_fields' => $transition->payloadFields,
                'available_at' => $transition->availableAt?->toIso8601String(),
            ], $handle->allowedTransitions(includeDenied: true)),
            'last_transition' => $last === null ? null : (new TransitionRecordResource($last))->toArray($request),
        ];
    }
}
