<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Rules;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionRequest;
use RoundlyConsulting\Lifecycle\Exceptions\LifecycleException;
use RoundlyConsulting\Lifecycle\LifecycleManager;

/**
 * The input names a transition (or, with `toState()`, a target state) the subject may take
 * now — the full guard pipeline, actor rules included. Each refusal becomes its translated
 * message.
 *
 * ```php
 * 'transition' => ['required', ValidTransition::for($listing)->by($request->user())],
 * ```
 */
final class ValidTransition implements ValidationRule
{
    private ?Model $actor = null;

    private bool $toState = false;

    private function __construct(
        private readonly Model $subject,
        private readonly ?string $lifecycle,
    ) {}

    public static function for(Model $subject, ?string $lifecycle = null): self
    {
        return new self($subject, $lifecycle);
    }

    public function by(?Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * The input is the state to move to, not a transition name.
     */
    public function toState(): self
    {
        $this->toState = true;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = $this->toState
            ? is_string($value) || is_int($value) || $value instanceof BackedEnum
            : is_string($value) && $value !== '';

        if (! $valid) {
            $fail('lifecycle::validation.valid_transition')->translate();

            return;
        }

        $manager = App::make(LifecycleManager::class);
        $lifecycle = $manager->for($this->subject, $this->lifecycle)->lifecycle;

        try {
            $decision = $manager->check(new TransitionRequest(
                subject: $this->subject,
                lifecycle: $lifecycle,
                transition: $this->toState ? null : (string) $value,
                target: $this->toState ? $value : null,
                actor: $this->actor,
            ));
        } catch (LifecycleException) {
            $fail('lifecycle::validation.valid_transition')->translate();

            return;
        }

        foreach ($decision->denials as $denial) {
            $fail($denial->message);
        }
    }
}
