<?php

declare(strict_types=1);

namespace RoundlyConsulting\PackageTemplate\DataTransferObjects;

use RoundlyConsulting\PackageTemplate\Actions\ExamplePackageTemplateAction;

/**
 * REPLACE ME — the input of {@see ExamplePackageTemplateAction}.
 *
 * Actions take a DTO, never a shape array. Rename this alongside the action it feeds.
 */
final readonly class ExamplePackageTemplateData
{
    public function __construct(
        public string $name,
    ) {}
}
