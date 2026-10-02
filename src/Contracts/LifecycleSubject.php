<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * An Eloquent model with one or more lifecycles. Use the HasLifecycle trait to implement
 * the behaviour; the model only maps its state attributes to definitions.
 *
 * @phpstan-require-extends Model
 */
interface LifecycleSubject
{
    /**
     * Attribute => definition class. The first entry is the primary lifecycle, used when a
     * call names none.
     *
     * @return array<string, class-string<LifecycleDefinition>>
     */
    public function lifecycleDefinitions(): array;
}
