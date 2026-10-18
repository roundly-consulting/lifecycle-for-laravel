<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleConfigurationException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Http\Resources\LifecycleResource;
use RoundlyConsulting\Lifecycle\Http\Resources\TransitionActorResource;
use RoundlyConsulting\Lifecycle\Http\Resources\TransitionRecordResource;
use RoundlyConsulting\Lifecycle\Support\Resources;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Resources\AbstractActorResource;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Resources\HostActorResource;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/**
 * Every string value of an encoded payload that carries a backslash — a PHP class name. The
 * decoded values are checked, so JSON's own escape sequences never count.
 *
 * @return list<string>
 */
function classNamesInPayload(string $json): array
{
    $values = Arr::flatten((array) json_decode($json, true, flags: JSON_THROW_ON_ERROR));

    return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && str_contains($value, '\\')));
}

it('renders the actor as its class basename, never its class name, without a morph map', function (): void {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->by($user)->because('ready')->apply('publish');

    $record = Lifecycles::for($listing)->lastTransition();
    $history = TransitionRecordResource::collection(Lifecycles::for($listing)->history())->toResponse(new Request)->getContent();
    $lifecycle = LifecycleResource::make(Lifecycles::for($listing)->by($user))->toResponse(new Request)->getContent();
    $collection = LifecycleResource::collection(Listing::query()->withLifecycle()->get())->toResponse(new Request)->getContent();
    $encoded = str_replace('\\', '\\\\', User::class);

    expect(Relation::morphMap())->toBe([])
        ->and($record?->actorType)->toBe(User::class)
        ->and((new TransitionRecordResource($record))->toArray(new Request)['actor'])->toBe(['id' => $user->id, 'type' => 'User'])
        ->and(json_decode((string) $lifecycle, true)['data']['last_transition']['actor'])->toBe(['id' => $user->id, 'type' => 'User'])
        ->and(json_decode((string) $history, true)['data'][0]['actor'])->toBe(['id' => $user->id, 'type' => 'User'])
        ->and(classNamesInPayload((string) $history))->toBe([])
        ->and(classNamesInPayload((string) $lifecycle))->toBe([])
        ->and(classNamesInPayload((string) $collection))->toBe([])
        ->and((string) $history)->not->toContain($encoded)
        ->and((string) $lifecycle)->not->toContain($encoded)
        ->and((string) $collection)->not->toContain($encoded);
});

it('renders the basename of the mapped class when the actor is stored under a morph alias', function (): void {
    Relation::morphMap(['member' => User::class]);

    try {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();
        Lifecycles::for($listing)->by($user)->apply('publish');
        $record = Lifecycles::for($listing)->lastTransition();

        $mapped = (new TransitionRecordResource($record))->toArray(new Request)['actor'];
        Relation::morphMap([], false);
        $unmapped = (new TransitionRecordResource($record))->toArray(new Request)['actor'];
    } finally {
        Relation::morphMap([], false);
    }

    expect($record?->actorType)->toBe('member')
        ->and($mapped)->toBe(['id' => $user->id, 'type' => 'User'])
        ->and($unmapped)->toBe(['id' => $user->id, 'type' => 'member']);
});

it('renders the actor through the resource configured as lifecycle.resources.actor', function (): void {
    config()->set('lifecycle.resources.actor', HostActorResource::class);
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->by($user)->apply('publish');

    $history = TransitionRecordResource::collection(Lifecycles::for($listing)->history())->toArray(new Request);
    $lifecycle = LifecycleResource::make(Lifecycles::for($listing)->by($user))->toArray(new Request);
    $expected = ['key' => $user->id, 'label' => 'Member #'.$user->id];

    expect($history[0]['actor'])->toBe($expected)
        ->and($lifecycle['last_transition']['actor'])->toBe($expected)
        ->and($history[1]['kind'])->toBe('initial')
        ->and($history[1]['actor'])->toBeNull();
});

it('renders a row without an actor as null, never through the actor resource', function (): void {
    config()->set('lifecycle.resources.actor', 'not a resource');
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->apply('publish');
    Lifecycles::for($listing)->asSystem()->apply('expire');

    expect(TransitionRecordResource::collection(Lifecycles::for($listing)->history())->toArray(new Request))
        ->sequence(
            fn ($row) => $row->actor->toBeNull()->system->toBeTrue()->transition->toBe('expire'),
            fn ($row) => $row->actor->toBeNull()->transition->toBe('publish'),
            fn ($row) => $row->actor->toBeNull()->kind->toBe('initial'),
        );
});

it('refuses an actor resource that is not an instantiable JsonResource subclass, naming the key', function (mixed $value, string $given): void {
    config()->set('lifecycle.resources.actor', $value);

    expect(fn () => Resources::actor())->toThrow(
        InvalidLifecycleConfigurationException::class,
        'The [lifecycle.resources.actor] setting must be an instantiable JsonResource subclass, ['.$given.'] given.',
    );
})->with([
    'a model' => [User::class, User::class],
    'a plain class' => [stdClass::class, 'stdClass'],
    'the bare JsonResource base' => [JsonResource::class, JsonResource::class],
    'an abstract JsonResource subclass' => [AbstractActorResource::class, AbstractActorResource::class],
    'a missing class' => ['App\\Http\\Resources\\GoneResource', 'App\\Http\\Resources\\GoneResource'],
    'an integer' => [42, 'int'],
    'an array' => [[HostActorResource::class], 'array'],
]);

it('refuses a misconfigured actor resource while rendering a row with an actor', function (): void {
    config()->set('lifecycle.resources.actor', User::class);
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->by(User::factory()->create())->apply('publish');
    $record = Lifecycles::for($listing)->lastTransition();

    expect(fn () => (new TransitionRecordResource($record))->toArray(new Request))
        ->toThrow(InvalidLifecycleConfigurationException::class, '[lifecycle.resources.actor]');
});

it('reads a blank or absent actor resource as the packaged one', function (mixed $value): void {
    config()->set('lifecycle.resources.actor', $value);

    expect(Resources::actor())->toBe(TransitionActorResource::class);
})->with(['null' => [null], 'empty' => [''], 'whitespace' => ['  ']]);

it('ships the packaged actor resource as the configured default', function (): void {
    expect(config('lifecycle.resources.actor'))->toBe(TransitionActorResource::class)
        ->and(Resources::actor())->toBe(TransitionActorResource::class)
        ->and(new ReflectionClass(TransitionActorResource::class))->isFinal()->toBeTrue();
});

it('renders a record without an actor as an empty reference when wrapped directly', function (): void {
    $listing = Listing::factory()->create();
    $record = Lifecycles::for($listing)->lastTransition();

    expect($record?->actorType)->toBeNull()
        ->and((new TransitionActorResource($record))->toArray(new Request))->toBe(['id' => null, 'type' => null]);
});

it('renders the actors of a history without a query', function (): void {
    $render = function (int $rows): int {
        $listing = Listing::factory()->create();

        foreach (range(1, $rows) as $row) {
            $actor = User::factory()->create();
            Lifecycles::for($listing)->by($actor)->apply('publish');
            Lifecycles::for($listing)->by($actor)->rollback();
        }

        $history = Lifecycles::for($listing)->history();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $json = TransitionRecordResource::collection($history)->toArray(new Request);

        expect(array_filter(array_column($json, 'actor')))->toHaveCount($rows * 2);

        return $queries;
    };

    expect($render(1))->toBe(0)
        ->and($render(5))->toBe(0);
});

it('renders the last transitions of eager-loaded subjects with actors without a query', function (): void {
    $render = function (int $listings): int {
        Listing::query()->forceDelete();
        Listing::factory()->count($listings)->create()
            ->each(fn (Listing $listing) => Lifecycles::for($listing)->by(User::factory()->create())->apply('publish'));
        $models = Listing::query()->withLifecycle()->get();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $json = LifecycleResource::collection($models)->toArray(new Request);

        expect(array_column(array_column(array_column($json, 'last_transition'), 'actor'), 'type'))
            ->toBe(array_fill(0, $listings, 'User'));

        return $queries;
    };

    expect($render(1))->toBe(0)
        ->and($render(5))->toBe(0);
});
