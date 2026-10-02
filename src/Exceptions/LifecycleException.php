<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Exceptions;

use RuntimeException;

/**
 * Base class of every exception the package throws, so a host can catch them all at once.
 */
abstract class LifecycleException extends RuntimeException {}
