<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Guards\DenyingGuard;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function documentWith(Closure $go): Document
{
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: $go));

    return Document::factory()->create();
}

it('short-circuits on structural denials', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->requiresActor()->requiresReason());

    $decision = Lifecycles::for($document)->check('finish');

    expect($decision->codes())->toBe(['not_from_current_state'])
        ->and($decision->first()?->source)->toBe('structure')
        ->and(Lifecycles::for($document)->check('nope')->codes())->toBe(['unknown_transition']);
});

it('collects every non-structural denial at once', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go
        ->requiresActor()->requiresReason()->when(fn () => false, 'no_photos', 'Add photos.')
        ->rules(['note' => 'required']));

    try {
        Lifecycles::for($document)->apply('go');
        $this->fail('expected a denial');
    } catch (TransitionDeniedException $exception) {
        expect($exception->decision()->codes())->toBe(['actor_required', 'reason_required', 'invalid_payload', 'no_photos'])
            ->and($exception->getMessage())->toBe('You must be signed in to perform "Go".')
            ->and($exception->retryAfterSeconds())->toBe(0);
    }
});

it('refuses system-only transitions to users and others to the system', function (): void {
    $listing = Listing::factory()->create();
    $listing->transition('publish');

    expect(Lifecycles::for($listing)->check('expire')->codes())->toBe(['system_only'])
        ->and(Lifecycles::for($listing)->asSystem()->check('expire')->allowed)->toBeTrue()
        ->and(Lifecycles::for($listing)->asSystem()->check('close')->codes())->toBe(['system_not_allowed'])
        ->and(Lifecycles::for($listing)->asSystem()->by(User::factory()->create())->check('expire')->codes())->toBe(['system_only']);
});

it('enforces actor types and actor closures', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go
        ->actors(User::class)
        ->actor(fn (?User $actor, Document $subject): bool => $actor?->admin === true));

    $admin = User::factory()->create(['admin' => true]);
    $member = User::factory()->create();

    expect(Lifecycles::for($document)->by($member)->check('go')->codes())->toBe(['actor_not_allowed'])
        ->and(Lifecycles::for($document)->by(Listing::factory()->create())->check('go')->codes())->toBe(['actor_not_allowed'])
        ->and(Lifecycles::for($document)->by($admin)->check('go')->allowed)->toBeTrue();
});

it('asks the Gate with the subject and the context', function (): void {
    $seen = null;
    Gate::define('advance', function (User $user, Document $document, TransitionContext $context) use (&$seen): bool {
        $seen = $context->transition->name;

        return $user->admin === true;
    });

    $document = documentWith(fn (TransitionBuilder $go) => $go->ability('advance'));

    expect(Lifecycles::for($document)->by(User::factory()->create())->check('go')->codes())->toBe(['unauthorized'])
        ->and(Lifecycles::for($document)->by(User::factory()->create(['admin' => true]))->check('go')->allowed)->toBeTrue()
        ->and($seen)->toBe('go');
});

it('skips actor rules, the required reason in system context', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->allowSystem()->ability('advance')->requiresReason());

    expect(Lifecycles::for($document)->asSystem()->apply('go')->to)->toBe('b');
});

it('takes the actor from auth when none is given', function (): void {
    Gate::define('advance', fn (User $user): bool => $user->admin === true);
    $document = documentWith(fn (TransitionBuilder $go) => $go->ability('advance'));

    $this->actingAs(User::factory()->create(['admin' => true]));

    expect(Lifecycles::for($document)->can('go'))->toBeTrue();

    config()->set('lifecycle.actor.from_auth', false);

    expect(Lifecycles::for($document)->check('go')->codes())->toBe(['actor_required']);

    config()->set('lifecycle.actor.from_auth', true);
    config()->set('lifecycle.actor.guard', 'web');

    expect(Lifecycles::for($document)->can('go'))->toBeTrue();
});

it('checks the reason length', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->requiresReason(5));
    config()->set('lifecycle.history.reason_max_length', 10);

    expect(Lifecycles::for($document)->because('abc')->check('go')->codes())->toBe(['reason_required'])
        ->and(Lifecycles::for($document)->because('   abcd  ')->check('go')->codes())->toBe(['reason_required'])
        ->and(Lifecycles::for($document)->because('long enough but way too long')->check('go')->codes())->toBe(['reason_too_long'])
        ->and(Lifecycles::for($document)->because('long enough but way too long')->check('go')->first()?->params['max'])->toBe(10)
        ->and(Lifecycles::for($document)->because('perfect')->check('go')->allowed)->toBeTrue()
        ->and(Lifecycles::for($document)->asSystem()->because(str_repeat('x', 11))->check('finish')->codes())->toBe(['not_from_current_state']);
});

it('reports payload validation errors per field', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->rules(['note' => 'required|string|max:5'], ['note.max' => 'Too long!']));

    $decision = Lifecycles::for($document)->with(['note' => 'far too long'])->check('go');

    expect($decision->codes())->toBe(['invalid_payload'])
        ->and($decision->first()?->errors)->toBe(['note' => ['Too long!']]);

    try {
        Lifecycles::for($document)->with(['note' => 'far too long'])->apply('go');
    } catch (TransitionDeniedException $exception) {
        expect($exception->toValidationException()->errors())->toBe(['note' => ['Too long!']]);
    }
});

it('refuses a payload sent to a transition that declares no rules', function (string $call, Closure $run): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->allowSystem());

    expect(fn () => $run($document))->toThrow(
        InvalidLifecycleUsageException::class,
        'Transition [go] declares no rules(), so its payload [note, priority] would be dropped. Declare rules() for the keys it accepts.',
    )->and($document->fresh()?->status)->toBe('a');
})->with([
    'apply' => ['apply', fn (Document $d) => Lifecycles::for($d)->with(['note' => 'hello', 'priority' => 1])->apply('go')],
    'attempt' => ['attempt', fn (Document $d) => Lifecycles::for($d)->with(['note' => 'hello', 'priority' => 1])->attempt('go')],
    'check' => ['check', fn (Document $d) => Lifecycles::for($d)->with(['note' => 'hello', 'priority' => 1])->check('go')],
    'transitionTo' => ['transitionTo', fn (Document $d) => Lifecycles::for($d)->with(['note' => 'hello', 'priority' => 1])->transitionTo('b')],
    'trait' => ['trait', fn (Document $d) => $d->transition('go', ['note' => 'hello', 'priority' => 1])],
    'schedule' => ['schedule', fn (Document $d) => Lifecycles::for($d)->asSystem()->with(['note' => 'hello', 'priority' => 1])->schedule('go', CarbonImmutable::now()->addDay())],
]);

it('accepts an empty payload on a transition without rules', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go);

    expect(Lifecycles::for($document)->with([])->check('go')->allowed)->toBeTrue()
        ->and($document->transition('go')->to)->toBe('b');
});

it('keeps only the validated keys of a payload with rules', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->rules(['note' => 'string'])->handledBy(function (TransitionContext $context): void {
        expect($context->payload)->toBe(['note' => 'kept']);
    }));

    $result = Lifecycles::for($document)->with(['note' => 'kept', 'extra' => 'dropped'])->apply('go');

    expect($result->record->context)->toBe(['note' => 'kept']);
});

it('caps the size of the stored payload', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->rules(['note' => 'string']));
    config()->set('lifecycle.history.max_context_bytes', 1024);

    expect(Lifecycles::for($document)->with(['note' => str_repeat('x', 2000)])->check('go')->codes())->toBe(['invalid_payload'])
        ->and(Lifecycles::for($document)->with(['note' => 'short'])->check('go')->allowed)->toBeTrue();
});

it('blocks before notBefore and after notAfter', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->notBefore('publish_at')->notAfter('editable_until', '1 day'));

    // Host datetimes are local (Europe/Bratislava, UTC+2 in October); the engine compares in UTC.
    $document->forceFill(['publish_at' => '2026-10-02 12:30:00', 'editable_until' => null])->saveQuietly();
    $decision = Lifecycles::for($document)->check('go');

    expect($decision->codes())->toBe(['not_yet_available'])
        ->and($decision->retryAfter?->toDateTimeString())->toBe('2026-10-02 10:30:00')
        ->and($decision->retryAfter?->tzName)->toBe('UTC')
        ->and($decision->first()?->params['at'])->toBe('2026-10-02 12:30');

    $document->forceFill(['publish_at' => null, 'editable_until' => '2026-10-01 11:59:59'])->saveQuietly();
    $document->refresh();

    expect(Lifecycles::for($document)->check('go')->codes())->toBe(['deadline_passed']);

    // The deadline itself (10:00 UTC + 1 day offset = now) is still allowed.
    $document->forceFill(['editable_until' => '2026-10-01 12:00:00'])->saveQuietly();

    expect(Lifecycles::for($document)->check('go')->allowed)->toBeTrue();
});

it('reads deadlines from closures with signed offsets', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go
        ->notBefore(fn (Document $d) => CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC'), '-1 day')
        ->notAfter(fn () => null));

    expect(Lifecycles::for($document)->check('go')->allowed)->toBeTrue();
});

it('refuses a deadline attribute that is not a date', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->notBefore('quantity'));
    $document->forceFill(['quantity' => 5])->saveQuietly();

    Lifecycles::for($document)->check('go');
})->throws(InvalidLifecycleUsageException::class, 'The attribute [quantity] must hold a date');

it('runs lifecycle guards before transition guards, resolving classes per call', function (): void {
    $order = [];
    $guard = new DenyingGuard;
    app()->instance(DenyingGuard::class, $guard);

    defineDocumentLifecycle(function (LifecycleBuilder $l) use (&$order): void {
        baseLifecycle($l, go: function (TransitionBuilder $go) use (&$order): void {
            $go->when(function () use (&$order): bool {
                $order[] = 'transition';

                return true;
            })->when(DenyingGuard::class);
        })->guard(function () use (&$order): ?Denial {
            $order[] = 'lifecycle';

            return null;
        });
    });

    $document = Document::factory()->create(['title' => 'blocked']);
    $decision = Lifecycles::for($document)->check('go');

    expect($order)->toBe(['lifecycle', 'transition'])
        ->and($decision->codes())->toBe(['blocked_title'])
        ->and($decision->first()?->message)->toBe('The title is blocked.')
        ->and($decision->retryAfter)->toBeNull()
        ->and($guard->calls)->toBe(1);
});

it('refuses guards that return anything else or are not guards', function (): void {
    $document = documentWith(fn (TransitionBuilder $go) => $go->when(fn () => 'yes'));

    expect(fn () => Lifecycles::for($document)->check('go'))
        ->toThrow(InvalidLifecycleUsageException::class, 'must return bool, null or a Denial');

    $document = documentWith(fn (TransitionBuilder $go) => $go->when(stdClass::class));

    expect(fn () => Lifecycles::for($document)->check('go'))
        ->toThrow(InvalidLifecycleUsageException::class, 'does not implement the expected contract');
});

it('carries a custom guard retry instant to the decision and the exception', function (): void {
    $at = CarbonImmutable::parse('2026-10-02 10:05:00', 'UTC');
    $document = documentWith(fn (TransitionBuilder $go) => $go->when(fn () => Denial::of('busy', retryAfter: $at)));

    try {
        Lifecycles::for($document)->apply('go');
    } catch (TransitionDeniedException $exception) {
        expect($exception->decision()->retryAfter)->toEqual($at)
            ->and($exception->retryAfterSeconds())->toBe(300)
            ->and($exception->denials()[0]->code)->toBe('busy');
    }
});
