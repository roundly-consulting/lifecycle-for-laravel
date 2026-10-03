<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use RoundlyConsulting\Lifecycle\Definition\Constraints\DeadlineRule;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionDefinition;
use RoundlyConsulting\Lifecycle\Engine\ClosureGuard;
use RoundlyConsulting\Lifecycle\Enums\RateLimitScope;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownStateException;
use RoundlyConsulting\Lifecycle\Support\Durations;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\ListingLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\OrderLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Definitions\TicketLifecycle;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\ListingStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Enums\OrderStatus;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\CompensatingHandler;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Handlers\PlainHandler;

function names(array $transitions): array
{
    return array_map(fn (TransitionDefinition $t): string => $t->name, $transitions);
}

it('compiles an enum lifecycle into states, transitions and an expiry rule', function (): void {
    $definition = app(DefinitionRegistry::class)->get(ListingLifecycle::class);
    $active = $definition->state('active');

    expect($definition->class)->toBe(ListingLifecycle::class)
        ->and($definition->initial)->toBe('draft')
        ->and($definition->initialState()->value)->toBe(ListingStatus::Draft)
        ->and($definition->terminalKeys())->toBe(['archived'])
        ->and($definition->values())->toBe(ListingStatus::cases())
        ->and($definition->isTerminal('archived'))->toBeTrue()
        ->and($definition->isTerminal('draft'))->toBeFalse()
        ->and($active->ttl?->interval?->d)->toBe(30)
        ->and($active->ttl?->grace?->d)->toBe(3)
        ->and(array_map(fn (CarbonInterval $lead): int => $lead->d, $active->ttl->leads))->toBe([7, 1])
        ->and($active->ttl->expiresVia)->toBe('expire')
        ->and($active->stamps)->toBe(['published_at'])
        ->and($active->expires())->toBeTrue()
        ->and($definition->expiringStates())->toBe([$active])
        ->and($definition->state('draft')->expires())->toBeFalse();
});

it('expands a wildcard to every non-terminal state except the target', function (): void {
    $definition = app(DefinitionRegistry::class)->get(ListingLifecycle::class);

    expect($definition->transition('archive')?->from)->toBe(['draft', 'active', 'closed', 'expired'])
        ->and(names($definition->transitionsFrom('active')))->toBe(['close', 'expire', 'archive'])
        ->and(names($definition->transitionsFrom('archived')))->toBe([])
        ->and(names($definition->transitionsBetween('active', 'expired')))->toBe(['expire'])
        ->and($definition->hasTransition('publish'))->toBeTrue()
        ->and($definition->hasTransition('nope'))->toBeFalse()
        ->and($definition->transition('nope'))->toBeNull();
});

it('keeps a self-transition only when allowed and excludes listed states', function (): void {
    $definition = app(DefinitionRegistry::class)->get(TicketLifecycle::class);

    expect($definition->transition('nudge')?->from)->toBe(['waiting'])
        ->and($definition->transition('nudge')?->isSelfFrom('waiting'))->toBeTrue()
        ->and($definition->transition('close')?->from)->toBe(['open', 'waiting', 'resolved'])
        ->and($definition->transition('resolve')?->leavesFrom('open'))->toBeTrue()
        ->and($definition->transition('resolve')?->leavesFrom('new'))->toBeFalse()
        ->and($definition->label)->toBe('Support ticket')
        ->and($definition->meta)->toBe(['team' => 'support']);
});

it('excludes the target from a wildcard', function (): void {
    $definition = compileLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('reset')->from('*')->to('a');
    });

    expect($definition->transition('reset')?->from)->toBe(['b']);
});

it('includes the target in a wildcard when self-transitions are allowed', function (): void {
    $definition = compileLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l)->transition('touch')->from('*')->to('b')->allowSelf();
    });

    expect($definition->transition('touch')?->from)->toBe(['a', 'b']);
});

it('compiles an int-backed lifecycle with string keys', function (): void {
    $definition = app(DefinitionRegistry::class)->get(OrderLifecycle::class);

    expect($definition->initial)->toBe('1')
        ->and($definition->terminalKeys())->toBe(['3', '4'])
        ->and($definition->transition('cancel')?->from)->toBe(['1'])
        ->and($definition->value('2'))->toBe(OrderStatus::Paid)
        ->and($definition->encode(OrderStatus::Paid))->toBe(2)
        ->and($definition->decode('2'))->toBe(OrderStatus::Paid)
        ->and($definition->decode(2))->toBe(OrderStatus::Paid)
        ->and($definition->key(OrderStatus::Paid))->toBe('2')
        ->and($definition->hasState(9))->toBeFalse()
        ->and($definition->stateLabel('2'))->toBe('Order paid');
});

it('labels states and transitions', function (): void {
    $definition = app(DefinitionRegistry::class)->get(TicketLifecycle::class);

    expect($definition->stateLabel('waiting'))->toBe('Waiting on "customer"')
        ->and($definition->stateLabel('resolved'))->toBe('Resolved!')
        ->and($definition->stateLabel('new'))->toBe('New')
        ->and($definition->transition('open')?->label())->toBe('Open ticket')
        ->and($definition->transition('wait')?->label())->toBe('Wait');
});

it('translates headline labels through the JSON translations', function (): void {
    app('translator')->addLines(['*.New' => 'Nový'], 'sk');
    app()->setLocale('sk');

    expect(app(DefinitionRegistry::class)->get(TicketLifecycle::class)->stateLabel('new'))->toBe('Nový');
});

it('throws for an undeclared state key', function (): void {
    app(DefinitionRegistry::class)->get(TicketLifecycle::class)->state('gone');
})->throws(UnknownStateException::class, 'The state [gone] is not declared');

it('compiles every transition setting', function (): void {
    $guard = fn () => true;
    $actorRule = fn () => true;

    $definition = compileLifecycle(function (LifecycleBuilder $l) use ($guard, $actorRule): void {
        baseLifecycle($l)->guard($guard);
        $l->transition('x')
            ->from('a')->to('c')
            ->label('X')->meta(['k' => 'v'])
            ->allowSystem()->requiresActor()->actors(stdClass::class)->actor($actorRule)->ability('do-x')
            ->requiresReason(5)->rules(['note' => 'required|string', 'items.*' => 'int'], ['note.required' => 'Note!'])
            ->sensitive('secret')->when($guard, 'custom', 'Custom message')
            ->notBefore('starts_at', '-1 day')->notAfter(fn () => null)
            ->maxOccurrences(2)->cooldown('1 hour')
            ->rateLimit(5, '1 minute', RateLimitScope::Subject)
            ->ignoresFreeze()->ignoresSeal()->ignoresMinDwell()
            ->handledBy(CompensatingHandler::class)
            ->reversible('2 hours')->rollbackRequires('undo-x')->rollbackGuard($guard)
            ->snapshots('price', 'price');
    });

    $x = $definition->transition('x');

    expect($x)->not->toBeNull()
        ->and($x->label)->toBe('X')
        ->and($x->meta)->toBe(['k' => 'v'])
        ->and($x->allowsSystem())->toBeTrue()
        ->and($x->hasActorRules())->toBeTrue()
        ->and($x->actorTypes)->toBe([stdClass::class])
        ->and($x->actorRule)->toBe($actorRule)
        ->and($x->requiresReason)->toBeTrue()
        ->and($x->reasonMinLength)->toBe(5)
        ->and($x->payloadFields())->toBe(['note', 'items'])
        ->and($x->messages)->toBe(['note.required' => 'Note!'])
        ->and($x->sensitive)->toBe(['secret'])
        ->and($x->guards[0])->toBeInstanceOf(ClosureGuard::class)
        ->and($x->guards[0]->code)->toBe('custom')
        ->and($x->notBefore)->toBeInstanceOf(DeadlineRule::class)
        ->and($x->notBefore?->source)->toBe('starts_at')
        ->and(Durations::describe($x->notBefore->offset))->toBe('-1 day')
        ->and($x->notAfter?->offset)->toBeNull()
        ->and($x->maxOccurrences)->toBe(2)
        ->and($x->cooldown?->h)->toBe(1)
        ->and($x->rateLimits)->toHaveCount(1)
        ->and($x->rateLimits[0]->maxAttempts)->toBe(5)
        ->and(Durations::describe($x->rateLimits[0]->decay))->toBe('1 minute')
        ->and($x->rateLimits[0]->per)->toBe(RateLimitScope::Subject)
        ->and([$x->ignoresFreeze, $x->ignoresSeal, $x->ignoresMinDwell])->toBe([true, true, true])
        ->and($x->handler)->toBe(CompensatingHandler::class)
        ->and($x->reversibility->isReversible())->toBeTrue()
        ->and($x->reversibility->window?->h)->toBe(2)
        ->and($x->reversibility->ability)->toBe('undo-x')
        ->and($x->reversibility->hasActorRules())->toBeTrue()
        ->and($x->snapshots)->toBe(['price'])
        ->and($definition->guards[0])->toBeInstanceOf(ClosureGuard::class);
});

it('decides reversibility from the handler', function (): void {
    $definition = compileLifecycle(function (LifecycleBuilder $l): void {
        baseLifecycle($l);
        $l->transition('plain')->from('a')->to('c')->handledBy(PlainHandler::class);
        $l->transition('opted')->from('a')->to('c')->handledBy(new PlainHandler)->reversible(withoutCompensation: true);
        $l->transition('closure')->from('a')->to('c')->handledBy(fn () => null);
        $l->transition('locked')->from('a')->to('c')->irreversible();
    });

    expect($definition->transition('go')?->reversibility->isReversible())->toBeTrue()
        ->and($definition->transition('go')?->reversibility->hasActorRules())->toBeFalse()
        ->and($definition->transition('plain')?->reversibility->isReversible())->toBeFalse()
        ->and($definition->transition('opted')?->reversibility->isReversible())->toBeTrue()
        ->and($definition->transition('closure')?->reversibility->isReversible())->toBeFalse()
        ->and($definition->transition('locked')?->reversibility->isReversible())->toBeFalse();
});

it('compiles quotas with sorted scopes and default names', function (): void {
    $max = fn () => 3;
    $definition = compileLifecycle(function (LifecycleBuilder $l) use ($max): void {
        baseLifecycle($l)->state('b')->quota(5, ['user_id', 'account_id'])->quota($max, name: 'global')
            ->minDwell('1 hour')->sealedAfter('2 days')->meta(['x' => 1])
            ->onEnter(fn () => null)->onExit(fn () => null)->stamps('a_at', 'a_at');
    });

    $state = $definition->state('b');

    expect($state->quotas[0]->scope)->toBe(['account_id', 'user_id'])
        ->and($state->quotas[0]->name)->toBe('b|account_id,user_id')
        ->and($state->quotas[0]->max)->toBe(5)
        ->and($state->quotas[1]->name)->toBe('global')
        ->and($state->quotas[1]->max)->toBe($max)
        ->and($state->minDwell?->h)->toBe(1)
        ->and($state->sealedAfter?->d)->toBe(2)
        ->and($state->meta)->toBe(['x' => 1])
        ->and($state->onEnter)->toHaveCount(1)
        ->and($state->onExit)->toHaveCount(1)
        ->and($state->stamps)->toBe(['a_at']);
});

it('compiles a ttl closure and an expiry attribute', function (): void {
    $resolver = fn () => null;
    $definition = compileLifecycle(function (LifecycleBuilder $l) use ($resolver): void {
        baseLifecycle($l)->transition('lapse')->from('b')->to('c')->systemOnly();
        $l->state('b')->ttl('1 day')->ttl($resolver)->expiresVia('lapse');
        $l->state('a')->expiresAtAttribute('ends_at')->expiresVia('lapse');
        $l->transition('lapse_a')->from('a')->to('c')->allowSystem();
        $l->state('a')->expiresVia('lapse_a');
    });

    expect($definition->state('b')->ttl?->resolver)->toBe($resolver)
        ->and($definition->state('b')->ttl?->interval)->toBeNull()
        ->and($definition->state('a')->ttl?->attribute)->toBe('ends_at');
});

it('returns the same builder for the same state', function (): void {
    $builder = new LifecycleBuilder;

    expect($builder->state('a'))->toBe($builder->state('a'))
        ->and($builder->state(ListingStatus::Draft))->toBe($builder->state('draft'));
});

it('tells whether a definition or a call target counts quotas', function (): void {
    $definition = compileLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l)->state('b')->quota(2, 'user_id'));
    $plain = compileLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l));

    expect($definition->hasQuotas())->toBeTrue()
        ->and($plain->hasQuotas())->toBeFalse()
        ->and($definition->quotasOnTarget('go', null))->toBeTrue()
        ->and($definition->quotasOnTarget('finish', null))->toBeFalse()
        ->and($definition->quotasOnTarget(null, 'b'))->toBeTrue()
        ->and($definition->quotasOnTarget(null, 'c'))->toBeFalse()
        ->and($definition->quotasOnTarget('nope', null))->toBeFalse()
        ->and($definition->quotasOnTarget(null, 'nope'))->toBeFalse()
        ->and($plain->quotasOnTarget('go', null))->toBeFalse();
});
