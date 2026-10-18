<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A JsonResource subclass that cannot be instantiated — refused as an actor resource.
 */
abstract class AbstractActorResource extends JsonResource {}
