<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\Compiler;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\DefinitionValidator;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\ValidationReport;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\InlineLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Support\SwappedModelsTestCase;
use RoundlyConsulting\Lifecycle\Tests\Support\UuidKeysTestCase;
use RoundlyConsulting\Lifecycle\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'RealEngine', 'ArchTest.php', 'ConfigContractTest.php', 'MigrationsTest.php');

// Model-swap and uuid-key proofs need their config applied BEFORE boot: own base cases.
uses(SwappedModelsTestCase::class)->in('ModelSwap');
uses(UuidKeysTestCase::class)->in('UuidKeys');

/**
 * A minimal valid lifecycle: a → b → c (terminal). Tests add the one thing they examine.
 */
function baseLifecycle(LifecycleBuilder $lifecycle, ?Closure $go = null, ?Closure $finish = null): LifecycleBuilder
{
    $lifecycle->states(['a', 'b', 'c'])->initial('a')->terminal('c');

    $goBuilder = $lifecycle->transition('go')->from('a')->to('b');
    $finishBuilder = $lifecycle->transition('finish')->from('b')->to('c');

    if ($go !== null) {
        $go($goBuilder);
    }

    if ($finish !== null) {
        $finish($finishBuilder);
    }

    return $lifecycle;
}

/**
 * Give the Document fixture an inline lifecycle for this test.
 */
function defineDocumentLifecycle(Closure $define): void
{
    InlineLifecycle::$define = $define;
    app(DefinitionRegistry::class)->flush();
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
