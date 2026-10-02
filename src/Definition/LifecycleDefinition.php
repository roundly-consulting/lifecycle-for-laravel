<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Definition;

/**
 * Base class of every lifecycle definition. Declare the states and transitions in
 * `define()`; the package compiles the result once per process and validates it.
 *
 * The class is instantiated with `new $class()` — never through the container — because the
 * compiled definition lives in a process-wide memo: anything injected here would outlive the
 * request under Octane. Put dependencies into class-string guards, handlers and hooks, which
 * are resolved from the container on every execution. `define()` must not read request
 * state; dynamic values belong in closures, which are evaluated per call.
 */
abstract class LifecycleDefinition
{
    abstract public function define(LifecycleBuilder $lifecycle): void;
}
