<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\Compiler;
use RoundlyConsulting\Lifecycle\Definition\DefinitionValidator;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\ValidationReport;
use RoundlyConsulting\Lifecycle\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A minimal valid lifecycle: a → b → c (terminal). Tests add the one thing they examine.
 */
function baseLifecycle(LifecycleBuilder $lifecycle): LifecycleBuilder
{
    $lifecycle->states(['a', 'b', 'c'])->initial('a')->terminal('c');
    $lifecycle->transition('go')->from('a')->to('b');
    $lifecycle->transition('finish')->from('b')->to('c');

    return $lifecycle;
}

function validateLifecycle(Closure $define): ValidationReport
{
    $builder = new LifecycleBuilder;
    $define($builder);

    return (new DefinitionValidator)->validate('Inline', $builder);
}

function compileLifecycle(Closure $define): CompiledDefinition
{
    $builder = new LifecycleBuilder;
    $define($builder);

    return (new Compiler)->compile('Inline', $builder);
}
