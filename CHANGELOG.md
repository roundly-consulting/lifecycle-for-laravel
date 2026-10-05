# Changelog

All notable changes to `lifecycle-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

- The sweep no longer defers a due schedule or expiry of a frozen subject when its transition
  `ignoresFreeze()`: it runs while frozen, as a direct `apply()` already did. Other transitions
  still wait for the freeze to end.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Status lifecycles for any Eloquent model through the `LifecycleSubject` contract and the
  `HasLifecycle` trait, with several lifecycles per model (`status`, `payment_status`).
- Definition classes with a fluent builder: backed-enum or string states, an initial state,
  terminal states, named transitions with several sources, a `*` wildcard and `fromAnyExcept()`,
  self-transitions, labels and metadata. Definitions are validated when compiled and by
  `lifecycle:validate` (errors, warnings and the columns a definition names), which checks every
  model listed in `lifecycle.subjects`.
- One guard pipeline for `can()`, `check()`, `allowedTransitions()` and `apply()`: Gate abilities,
  actor rules, system-only transitions, required reasons, payload validation with sensitive keys
  (a payload sent to a transition without `rules()` is refused, never silently dropped), custom
  guards, `notBefore` / `notAfter` deadlines, freezes and seals. Every refusal is a structured,
  translated `Denial` — including `subject_trashed` for a soft-deleted model.
- Limits: maximum occurrences, cooldowns, minimum time in a state, per-actor or per-subject rate
  limits and race-free quotas per scope column, with a fixed or per-subject maximum.
- Transactions with a subject row lock and a compare-and-swap state write, optimistic versions
  (`expectingVersion()`), idempotency keys, deadlock retries and after-commit events. On
  MySQL/MariaDB only a transaction that counts a quota runs at READ COMMITTED
  (`transactions.mysql_read_committed` opts out to locking reads); a `binlog_format=STATEMENT`
  server gets a configuration error that names the fix.
- Transition handlers, `onEnter` / `onExit` hooks, timestamp stamps and attribute snapshots.
- Expiry per state from an interval, a closure or a datetime column, with grace periods, warnings
  that fire once per lead, `extend()`, `renew()`, `expireAt()` and `neverExpire()`, plus
  `effectiveState()`.
- Scheduled transitions and a `lifecycle:sweep` command that runs warnings, expiries and schedules
  in batches, inline or as unique queued jobs, with retries, deferral while frozen and isolated
  errors — per database connection (`--database`, `sweep(connection: …)`) for models that live on
  another connection, which `lifecycle:validate` checks are swept. `php artisan about` and `lifecycle:validate` say when the sweep is not scheduled.
  `Lifecycles::schedules()->due()` / `failed()` list schedules with their subject and outcome;
  `schedules()->retry($id)` and `for($model)->retryScheduled($transition)` put a failed one back.
- Rollbacks: undo the last transition or roll back to a history point (`canRollback()` /
  `canRollbackTo()` ask first), all or nothing, with windows, irreversible transitions,
  compensating handlers, snapshot conflict detection and restored stamps, counters, entry times
  and schedules.
- An append-only history (actor, reason, context, snapshot) and `lifecycle:prune`.
- Strict state writes, drift adoption and `lifecycle:adopt` for existing tables.
- Query scopes: `whereState`, `whereNotState`, `whereExpired`, `whereNotExpired`,
  `whereExpiringWithin`, `whereInGrace`, `whereFrozen`, `whereInStateFor` and `withLifecycle`.
- `LifecycleResource` (also straight from a model, so
  `LifecycleResource::collection(Listing::query()->withLifecycle()->get())` reads state, freeze,
  expiry and the last transition without a query per model; `collectionFor($models, 'payment_status')`
  renders another lifecycle the same way) and `TransitionRecordResource`, the
  `ValidTransition` and `ValidState` rules and the `AsLifecycleState` cast.
- A `make:lifecycle` generator (string states or `--enum`, publishable stubs), Mermaid and DOT
  graphs (`lifecycle:graph`) and `lifecycle:show`.
- The `Lifecycles` facade over an injectable `LifecycleManager` and public action classes, with
  `Lifecycles::fake()` — it mirrors the real structural checks, freezes, schedules and expiry
  instants in memory — and assertions for transitions, denials, rollbacks, freezes and unfreezes,
  schedules and cancellations, expiry changes, sweeps, warnings, retries, adoption and pruning.
  Every assertion about a subject can name the lifecycle (`lifecycle: 'payment_status'`), for
  models whose lifecycles share transition names.
- Events: `LifecycleTransitioning`, `LifecycleTransitioned`, `LifecycleTransitionDenied`,
  `LifecycleExpiring`, `LifecycleExpired`, `LifecycleRolledBack`, `LifecycleFrozen`,
  `LifecycleUnfrozen`, `LifecycleAdopted` and `ScheduledTransitionFailed`.
- English and Slovak translations; bigint, UUID and ULID keys; swappable models; a `php artisan
  about` section. Octane-safe: the manager keeps no state and resolves everything from the
  current container.
