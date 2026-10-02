<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions;

use Closure;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * A definition each test writes inline (see `defineDocumentLifecycle()` in Pest.php).
 */
final class InlineLifecycle extends LifecycleDefinition
{
    public static ?Closure $define = null;

    public function define(LifecycleBuilder $lifecycle): void
    {
        (self::$define ?? static fn (LifecycleBuilder $l) => baseLifecycle($l))($lifecycle);
    }
}
