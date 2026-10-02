<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid\WarningsOnlyLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\TicketLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;

it('serves compiled definitions, reports and graphs', function (): void {
    $definitions = Lifecycles::definitions();
    $compiled = $definitions->get(ListingLifecycle::class);

    expect($definitions->of(Listing::class))->toBe($compiled)
        ->and($definitions->of(new Listing, 'status'))->toBe($compiled)
        ->and($definitions->validate(WarningsOnlyLifecycle::class)->warnings())->toHaveCount(2)
        ->and($definitions->graph(TicketLifecycle::class, GraphFormat::Mermaid))->toStartWith('stateDiagram-v2')
        ->and($definitions->graph(TicketLifecycle::class))->toStartWith('stateDiagram-v2');

    $definitions->flush();

    expect(app(DefinitionRegistry::class)->get(ListingLifecycle::class))->not->toBe($compiled);
});

it('lists the registered definitions', function (): void {
    expect(Lifecycles::definitions()->registered())->toBe([]);

    config()->set('lifecycle.definitions', [ListingLifecycle::class, TicketLifecycle::class]);

    expect(Lifecycles::definitions()->registered())->toBe([ListingLifecycle::class, TicketLifecycle::class]);
});

it('refuses a malformed definitions list', function (mixed $value, string $message): void {
    config()->set('lifecycle.definitions', $value);

    expect(fn () => Lifecycles::definitions()->registered())->toThrow(InvalidLifecycleConfigurationException::class, $message);
})->with([
    'not a list' => ['nope', 'must be a list'],
    'not a definition' => [[stdClass::class], 'must be a LifecycleDefinition class, [stdClass]'],
    'not a string' => [[42], '[int] given'],
]);
