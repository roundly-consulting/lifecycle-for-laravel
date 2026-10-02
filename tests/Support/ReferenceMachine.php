<?php

declare(strict_types=1);

namespace RoundlyConsulting\Lifecycle\Tests\Support;

use Carbon\CarbonImmutable;

/**
 * A pure, in-memory model of the reference lifecycle used by the property test — what the
 * engine must agree with after every random step:
 *
 *   draft →publish→ active →close→ closed →reopen (max 2)→ active
 *   active →expire (system, TTL 10 days)→ expired →reactivate→ active
 *   * →archive (irreversible)→ archived (terminal)
 */
final class ReferenceMachine
{
    public const array TRANSITIONS = [
        'publish' => [['draft'], 'active'],
        'close' => [['active'], 'closed'],
        'reopen' => [['closed'], 'active'],
        'expire' => [['active'], 'expired'],
        'reactivate' => [['expired'], 'active'],
        'archive' => [['draft', 'active', 'closed', 'expired'], 'archived'],
    ];

    public string $state = 'draft';

    public CarbonImmutable $enteredAt;

    public bool $frozen = false;

    /** @var array<string, array{count: int, last_at: string}> */
    public array $counters = [];

    /**
     * Every history row: kind, transition, from, to, the entry time and counter entry before it,
     * whether a rollback reverted it.
     *
     * @var list<array{kind: string, transition: ?string, from: ?string, to: string, previous: ?CarbonImmutable, counter: ?array{count: int, last_at: string}, reverted: bool}>
     */
    public array $rows = [];

    /**
     * Expiry schedules of the `active` state.
     *
     * @var list<array{expires: CarbonImmutable, due: CarbonImmutable, created: ?int, status: string, cancelledBy: ?int}>
     */
    public array $expiries = [];

    public function __construct(CarbonImmutable $now)
    {
        $this->enteredAt = $now;
        $this->rows[] = ['kind' => 'initial', 'transition' => null, 'from' => null, 'to' => 'draft', 'previous' => null, 'counter' => null, 'reverted' => false];
    }

    public function apply(string $transition, CarbonImmutable $now, bool $system = false, string $kind = 'transition'): bool
    {
        [$from, $to] = self::TRANSITIONS[$transition];

        if ($this->state === 'archived' || ! in_array($this->state, $from, true) || $this->frozen) {
            return false;
        }

        if (($transition === 'expire') !== $system) {
            return false;
        }

        if ($transition === 'reopen' && ($this->counters['reopen']['count'] ?? 0) >= 2) {
            return false;
        }

        $left = $this->state;
        $this->rows[] = [
            'kind' => $kind,
            'transition' => $transition,
            'from' => $left,
            'to' => $to,
            'previous' => $this->enteredAt,
            'counter' => $this->counters[$transition] ?? null,
            'reverted' => false,
        ];
        $row = count($this->rows) - 1;

        $this->counters[$transition] = ['count' => ($this->counters[$transition]['count'] ?? 0) + 1, 'last_at' => $now->format('Y-m-d H:i:s')];
        $this->state = $to;
        $this->enteredAt = $now;

        $this->leave($left, $row);

        if ($to === 'active') {
            $this->expiries[] = ['expires' => $now->addDays(10), 'due' => $now->addDays(10), 'created' => $row, 'status' => 'pending', 'cancelledBy' => null];
        }

        return true;
    }

    /**
     * Roll back every effective-path row after position `$point` of the path (the top row when
     * null), all or nothing.
     */
    public function rollback(?int $point, CarbonImmutable $now): bool
    {
        $path = $this->path();

        if ($path === []) {
            return false;
        }

        $targets = $point === null ? [end($path)] : array_slice($path, $point + 1);
        $targets = array_reverse($targets);

        if ($targets === [] || $this->frozen) {
            return false;
        }

        foreach ($targets as $index) {
            $row = $this->rows[$index];

            if ($row['kind'] !== 'transition' || $row['transition'] === 'archive') {
                return false;
            }
        }

        foreach ($targets as $index) {
            $row = $this->rows[$index];
            $this->rows[$index]['reverted'] = true;
            $this->rows[] = ['kind' => 'rollback', 'transition' => $row['transition'], 'from' => $row['to'], 'to' => (string) $row['from'], 'previous' => null, 'counter' => null, 'reverted' => false];
            $rollback = count($this->rows) - 1;

            $this->state = (string) $row['from'];
            $this->enteredAt = $row['previous'] ?? $now;

            if ($row['counter'] === null) {
                unset($this->counters[(string) $row['transition']]);
            } else {
                $this->counters[(string) $row['transition']] = $row['counter'];
            }

            foreach ($this->expiries as $position => $expiry) {
                if ($expiry['status'] === 'pending' && $expiry['created'] === $index) {
                    $this->expiries[$position]['status'] = 'reverted';
                }
            }

            $this->leave($row['to'], $rollback);

            foreach ($this->expiries as $position => $expiry) {
                if ($expiry['status'] === 'state_left' && $expiry['cancelledBy'] === $index && $this->pending() === null) {
                    $this->expiries[$position]['status'] = 'pending';
                    $this->expiries[$position]['cancelledBy'] = null;
                }
            }
        }

        return true;
    }

    public function expireAt(CarbonImmutable $at): bool
    {
        if ($this->state !== 'active') {
            return false;
        }

        $pending = $this->pending();
        $created = $pending === null ? null : $this->expiries[$pending]['created'];

        if ($pending !== null) {
            $this->expiries[$pending]['status'] = 'replaced';
        }

        $this->expiries[] = ['expires' => $at, 'due' => $at, 'created' => $created, 'status' => 'pending', 'cancelledBy' => null];

        return true;
    }

    public function extend(int $days): bool
    {
        $pending = $this->pending();

        if ($this->state !== 'active' || $pending === null) {
            return false;
        }

        $this->expiries[$pending]['status'] = 'replaced';
        $expires = $this->expiries[$pending]['expires']->addDays($days);
        $this->expiries[] = ['expires' => $expires, 'due' => $expires, 'created' => $this->expiries[$pending]['created'], 'status' => 'pending', 'cancelledBy' => null];

        return true;
    }

    public function sweep(CarbonImmutable $now): void
    {
        $pending = $this->pending();

        if ($pending === null || $this->expiries[$pending]['due']->greaterThan($now)) {
            return;
        }

        if ($this->frozen) {
            // Deferred by `schedules.retry_after` (5 minutes): the instant stays, the due time moves.
            $this->expiries[$pending]['due'] = $now->addMinutes(5);

            return;
        }

        $this->expiries[$pending]['status'] = 'executed';
        $this->apply('expire', $now, system: true, kind: 'expiry');
    }

    /**
     * Positions of the effective-path rows, oldest first.
     *
     * @return list<int>
     */
    public function path(): array
    {
        $path = [];

        foreach ($this->rows as $index => $row) {
            if ($row['kind'] !== 'rollback' && ! $row['reverted']) {
                $path[] = $index;
            }
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    public function describePath(): array
    {
        return array_map(fn (int $index): string => self::describe($this->rows[$index]), $this->path());
    }

    public function pendingExpiry(): ?CarbonImmutable
    {
        $pending = $this->pending();

        return $pending === null ? null : $this->expiries[$pending]['expires'];
    }

    /**
     * @param  array{kind: string, transition: ?string, from: ?string, to: string}  $row
     */
    public static function describe(array $row): string
    {
        return $row['kind'].':'.($row['transition'] ?? '-').':'.($row['from'] ?? '-').'>'.$row['to'];
    }

    private function pending(): ?int
    {
        foreach ($this->expiries as $position => $expiry) {
            if ($expiry['status'] === 'pending') {
                return $position;
            }
        }

        return null;
    }

    private function leave(string $state, int $row): void
    {
        if ($state !== 'active') {
            return;
        }

        foreach ($this->expiries as $position => $expiry) {
            if ($expiry['status'] === 'pending') {
                $this->expiries[$position]['status'] = 'state_left';
                $this->expiries[$position]['cancelledBy'] = $row;
            }
        }
    }
}
