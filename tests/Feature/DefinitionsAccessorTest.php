<?php

declare(strict_types=1);

use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Enums\GraphFormat;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid\WarningsOnlyLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\OrderLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\PaymentLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\TicketLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\NotASubject;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Ticket;

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

it('lists the configured subjects and their definitions', function (): void {
    expect(Lifecycles::definitions()->subjects())->toBe([])
        ->and(Lifecycles::definitions()->registered())->toBe([]);

    config()->set('lifecycle.subjects', [Ticket::class, Order::class, Listing::class, Ticket::class]);

    expect(Lifecycles::definitions()->subjects())->toBe([Ticket::class, Order::class, Listing::class, Ticket::class])
        ->and(Lifecycles::definitions()->registered())->toBe([TicketLifecycle::class, OrderLifecycle::class, PaymentLifecycle::class, ListingLifecycle::class]);
});

it('refuses a malformed subjects list', function (mixed $value, string $message): void {
    config()->set('lifecycle.subjects', $value);

    expect(fn () => Lifecycles::definitions()->subjects())->toThrow(InvalidLifecycleConfigurationException::class, $message)
        ->and(fn () => Lifecycles::definitions()->registered())->toThrow(InvalidLifecycleConfigurationException::class, $message);
})->with([
    'not a list' => ['nope', 'The [lifecycle.subjects] setting must be a list.'],
    'not a class' => [['App\\Models\\Missing'], 'must be an Eloquent model class implementing LifecycleSubject, [App\\Models\\Missing] given'],
    'not a model' => [[ListingLifecycle::class], '['.ListingLifecycle::class.'] given'],
    'a model that is not a subject' => [[NotASubject::class], '['.NotASubject::class.'] given'],
    'not a string' => [[42], '[int] given'],
]);
