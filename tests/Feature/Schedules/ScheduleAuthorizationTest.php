<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

/**
 * Who may schedule is decided when scheduling: a user cannot schedule a system-only edge, the
 * scheduling actor's rules apply, and a required sensitive value cannot be scheduled (it is
 * never stored).
 */
function scheduleDenial(Closure $call): array
{
    try {
        $call();
    } catch (TransitionDeniedException $exception) {
        return $exception->decision()->codes();
    }

    return [];
}

it('lets only system code schedule a system-only transition', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->systemOnly()));
    $document = Document::factory()->create();
    $at = CarbonImmutable::parse('2026-12-01', 'UTC');

    expect(scheduleDenial(fn () => Lifecycles::for($document)->schedule('go', $at)))->toBe(['system_only'])
        ->and(Lifecycles::for($document)->asSystem()->schedule('go', $at)->transition)->toBe('go');
});

it('refuses to schedule a transition the system may not run', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));

    expect(scheduleDenial(fn () => Lifecycles::for(Document::factory()->create())->schedule('go', CarbonImmutable::parse('2026-12-01', 'UTC'))))
        ->toBe(['system_not_allowed']);
});

it('checks the scheduling actor and the input now', function (): void {
    Gate::define('plan', fn (User $user): bool => $user->admin === true);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go
        ->allowSystem()->ability('plan')->requiresReason()->rules(['card' => 'required'])->sensitive('card')));
    $document = Document::factory()->create();
    $at = CarbonImmutable::parse('2026-12-01', 'UTC');

    expect(scheduleDenial(fn () => Lifecycles::for($document)->by(User::factory()->create())->with(['card' => '1'])->schedule('go', $at)))
        ->toBe(['unauthorized', 'reason_required', 'invalid_payload']);
});

it('refuses to schedule out of a frozen subject or an unknown transition', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->allowSystem()));
    $document = Document::factory()->create();
    Lifecycles::for($document)->freeze();

    expect(scheduleDenial(fn () => Lifecycles::for($document)->schedule('go', CarbonImmutable::parse('2026-12-01', 'UTC'))))->toBe(['frozen'])
        ->and(scheduleDenial(fn () => Lifecycles::for($document)->schedule('nope', CarbonImmutable::parse('2026-12-01', 'UTC'))))->toBe(['unknown_transition']);
});
