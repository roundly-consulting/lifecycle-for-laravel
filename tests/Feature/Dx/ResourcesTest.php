<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\TransitionBuilder;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Lifecycle\Http\Resources\LifecycleResource;
use RoundlyConsulting\Lifecycle\Http\Resources\TransitionRecordResource;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Document;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\Listing;
use RoundlyConsulting\Lifecycle\Tests\Fixtures\Models\User;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('renders the lifecycle with allowed and refused transitions', function (): void {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Lifecycles::for($listing)->by($user)->because('ready')->apply('publish');
    Lifecycles::for($listing)->because('maintenance')->freeze(CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'));

    $json = (new LifecycleResource(Lifecycles::for($listing)->by($user)))->toArray(new Request);

    expect($json)->toEqual([
        'lifecycle' => 'status',
        'state' => 'active',
        'state_label' => 'Active',
        'terminal' => false,
        'entered_at' => '2026-10-02T08:00:00+00:00',
        'version' => 2,
        'frozen' => ['until' => '2026-10-05T08:00:00+00:00', 'reason' => 'maintenance'],
        'expiry' => ['expires_at' => '2026-11-01T08:00:00+00:00', 'due_at' => '2026-11-04T08:00:00+00:00', 'in_grace' => false],
        'allowed_transitions' => [
            [
                'name' => 'close', 'label' => 'Close', 'to' => 'closed', 'to_label' => 'Closed', 'allowed' => false,
                'denials' => [['code' => 'frozen', 'message' => 'This record is frozen.', 'params' => ['transition' => 'Close', 'state' => 'Active'], 'retry_after' => null, 'errors' => []]],
                'requires_reason' => false, 'payload_fields' => [], 'available_at' => null,
            ],
            [
                'name' => 'expire', 'label' => 'Expire', 'to' => 'expired', 'to_label' => 'Expired', 'allowed' => false,
                'denials' => [
                    ['code' => 'frozen', 'message' => 'This record is frozen.', 'params' => ['transition' => 'Expire', 'state' => 'Active'], 'retry_after' => null, 'errors' => []],
                    ['code' => 'system_only', 'message' => 'Only the system can perform "Expire".', 'params' => ['transition' => 'Expire', 'state' => 'Active'], 'retry_after' => null, 'errors' => []],
                ],
                'requires_reason' => false, 'payload_fields' => [], 'available_at' => null,
            ],
            [
                'name' => 'archive', 'label' => 'Archive', 'to' => 'archived', 'to_label' => 'Archived', 'allowed' => false,
                'denials' => [['code' => 'frozen', 'message' => 'This record is frozen.', 'params' => ['transition' => 'Archive', 'state' => 'Active'], 'retry_after' => null, 'errors' => []]],
                'requires_reason' => false, 'payload_fields' => [], 'available_at' => null,
            ],
        ],
        'last_transition' => [
            'id' => 2, 'kind' => 'transition', 'transition' => 'publish', 'from' => 'draft', 'to' => 'active',
            'actor' => ['type' => $user->getMorphClass(), 'id' => $user->id], 'system' => false, 'reason' => 'ready',
            'occurred_at' => '2026-10-02T08:00:00+00:00', 'reverted' => false,
        ],
    ]);
});

it('renders a fresh subject without freeze, expiry or reasons', function (): void {
    $listing = Listing::factory()->create();

    $json = LifecycleResource::make(Lifecycles::for($listing))->toArray(new Request);

    expect($json['frozen'])->toBeNull()
        ->and($json['expiry'])->toBeNull()
        ->and($json['last_transition']['kind'])->toBe('initial')
        ->and($json['last_transition']['from'])->toBeNull()
        ->and($json['last_transition']['actor'])->toBeNull()
        ->and(array_column($json['allowed_transitions'], 'allowed'))->toBe([true, true]);
});

it('shows context and snapshot only on request', function (): void {
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->rules(['note' => 'string'])->snapshots('title')));
    $document = Document::factory()->create(['title' => 'T']);
    Lifecycles::for($document)->with(['note' => 'n'])->apply('go');
    $record = Lifecycles::for($document)->lastTransition();

    expect((new TransitionRecordResource($record))->toArray(new Request))->not->toHaveKey('context')
        ->and((new TransitionRecordResource($record))->withContext()->toArray(new Request))->toMatchArray([
            'context' => ['note' => 'n'],
            'snapshot' => ['before' => ['title' => 'T'], 'after' => ['title' => 'T']],
        ]);
});

it('checks the resource transitions for the handle actor', function (): void {
    Gate::define('advance', fn (User $user): bool => $user->admin === true);
    defineDocumentLifecycle(fn (LifecycleBuilder $l) => baseLifecycle($l, go: fn (TransitionBuilder $go) => $go->ability('advance')));
    $document = Document::factory()->create();

    $member = LifecycleResource::make(Lifecycles::for($document)->by(User::factory()->create()))->toArray(new Request);
    $admin = LifecycleResource::make(Lifecycles::for($document)->by(User::factory()->create(['admin' => true])))->toArray(new Request);

    expect($member['allowed_transitions'][0]['denials'][0]['code'])->toBe('unauthorized')
        ->and($admin['allowed_transitions'][0]['allowed'])->toBeTrue();
});
