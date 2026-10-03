<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Models\LifecycleState;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid\BrokenLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\Invalid\WarningsOnlyLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\TicketLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Order;

function artisan(string $command, array $parameters = []): array
{
    $code = Artisan::call($command, $parameters);

    return [$code, Artisan::output()];
}

it('prints a graph for a definition class or Model:attribute', function (): void {
    [$code, $output] = artisan('lifecycle:graph', ['definition' => ListingLifecycle::class]);

    expect($code)->toBe(0)->and($output)->toStartWith('stateDiagram-v2');

    Relation::morphMap(['listing' => Listing::class]);
    [$code, $output] = artisan('lifecycle:graph', ['definition' => 'listing:status', '--format' => 'dot']);

    expect($code)->toBe(0)->and($output)->toStartWith('digraph "ListingLifecycle"');

    [$code, $output] = artisan('lifecycle:graph', ['definition' => Listing::class.':']);

    expect($code)->toBe(0)->and($output)->toContain('[*] --> s0');
    Relation::morphMap([], false);
});

it('writes a graph to a file', function (): void {
    $file = sys_get_temp_dir().'/lifecycle-graph-'.uniqid().'.dot';

    [$code, $output] = artisan('lifecycle:graph', ['definition' => TicketLifecycle::class, '--format' => 'dot', '--output' => $file]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Written to')
        ->and((string) file_get_contents($file))->toStartWith('digraph "TicketLifecycle"');

    unlink($file);
});

it('refuses an unknown format or target', function (array $parameters, string $message): void {
    [$code, $output] = artisan('lifecycle:graph', $parameters);

    expect($code)->toBe(1)->and($output)->toContain($message);
})->with([
    'format' => [['definition' => ListingLifecycle::class, '--format' => 'svg'], 'Unknown format [svg]'],
    'model' => [['definition' => 'nope:status'], 'does not implement'],
    'definition' => [['definition' => stdClass::class], 'is not a RoundlyConsulting'],
]);

it('asks for subjects when there is nothing to validate', function (): void {
    expect(artisan('lifecycle:validate'))->toBe([0, "No lifecycle subjects to validate — list your models in lifecycle.subjects.\n"]);

    [$code, $output] = artisan('lifecycle:validate', ['--strict' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('No lifecycle subjects to validate — list your models in lifecycle.subjects.');
});

it('validates every lifecycle of the configured subjects, reporting every issue', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->states(['a', 'b', 'c', 'orphan']);
    });
    config()->set('lifecycle.subjects', [Listing::class, Order::class, Document::class]);
    [$code, $output] = artisan('lifecycle:validate');

    expect($code)->toBe(0)
        ->and($output)->toContain(Listing::class.':status — 0 error(s), 0 warning(s)')
        ->and($output)->toContain(Order::class.':status — 0 error(s)')
        ->and($output)->toContain(Order::class.':payment_status — 0 error(s)')
        ->and($output)->toContain(Document::class.':status — 0 error(s), 2 warning(s)')
        ->and($output)->toContain('unreachable_state: The state "orphan" cannot be reached from the initial state.');

    expect(artisan('lifecycle:validate', ['--strict' => true])[0])->toBe(1);
});

it('checks the columns of the configured subjects by default', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->stamps('signed_at'));
    config()->set('lifecycle.subjects', [Document::class]);

    [$code, $output] = artisan('lifecycle:validate');

    expect($code)->toBe(1)
        ->and($output)->toContain(Document::class.':status — 1 error(s), 0 warning(s)')
        ->and($output)->toContain('names the column [signed_at]');
});

it('reports a malformed subjects list', function (): void {
    config()->set('lifecycle.subjects', [ListingLifecycle::class]);

    [$code, $output] = artisan('lifecycle:validate');

    expect($code)->toBe(1)->and($output)->toContain('must be an Eloquent model class implementing LifecycleSubject');
});

it('validates named definitions, reporting every issue', function (): void {
    [$code, $output] = artisan('lifecycle:validate', ['definition' => [ListingLifecycle::class, WarningsOnlyLifecycle::class]]);

    expect($code)->toBe(0)
        ->and($output)->toContain(ListingLifecycle::class.' — 0 error(s), 0 warning(s)')
        ->and($output)->toContain('unreachable_state: The state "orphan" cannot be reached from the initial state.');

    expect(artisan('lifecycle:validate', ['definition' => [WarningsOnlyLifecycle::class], '--strict' => true])[0])->toBe(1);
});

it('fails on definition errors and unknown targets', function (): void {
    [$code, $output] = artisan('lifecycle:validate', ['definition' => [BrokenLifecycle::class, 'nope:status']]);

    expect($code)->toBe(1)
        ->and($output)->toContain('missing_initial: No initial state is declared.')
        ->and($output)->toContain('nope:status: The model [nope]');
});

it('checks the columns a definition names on the model table', function (): void {
    defineDocumentLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
        $l->state('b')->stamps('signed_at')->quota(1, 'tenant_id')->expiresAtAttribute('ends_at')->expiresVia('lapse');
    });

    [$code, $output] = artisan('lifecycle:validate', ['definition' => [Document::class.':status']]);

    expect($code)->toBe(1)
        ->and($output)->toContain('scoped by [tenant_id], which the table [documents] does not have')
        ->and($output)->toContain('names the column [signed_at]')
        ->and($output)->toContain('names the column [ends_at]');
});

it('shows one subject lifecycle', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    [$code, $output] = artisan('lifecycle:show', ['subject' => Listing::class, 'id' => $listing->id, '--history' => 5]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Active (active)')
        ->and($output)->toMatch('/system can\s+\|\s+expire\s/')
        ->and($output)->toContain('publish');

    [$code, $output] = artisan('lifecycle:show', ['subject' => Listing::class, 'id' => $listing->id, '--history' => 0, '--lifecycle' => 'status']);

    expect($code)->toBe(0)->and($output)->not->toContain('reverted');
});

it('refuses an unknown subject or row', function (array $parameters, string $message): void {
    [$code, $output] = artisan('lifecycle:show', $parameters);

    expect($code)->toBe(1)->and($output)->toContain($message);
})->with([
    'model' => [['subject' => 'nope', 'id' => 1], 'does not implement'],
    'row' => [['subject' => Listing::class, 'id' => 999], 'No ['.Listing::class.'] with key [999]'],
    'history' => [['subject' => Listing::class, 'id' => 1, '--history' => 'all'], '--history must be an integer'],
]);

it('adopts every row of a model', function (): void {
    Listing::factory()->count(2)->create();
    LifecycleState::query()->delete();

    expect(artisan('lifecycle:adopt', ['model' => Listing::class, '--no-expiry' => true]))->toBe([0, "Adopted 2 subject(s).\n"])
        ->and(artisan('lifecycle:adopt', ['model' => Listing::class, '--lifecycle' => 'status'])[1])->toContain('Adopted 0')
        ->and(artisan('lifecycle:adopt', ['model' => 'nope'])[0])->toBe(1);
});
