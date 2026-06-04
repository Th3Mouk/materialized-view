# Population & read safety

A view created `WITH NO DATA` is not merely empty: **PostgreSQL raises a hard error if you read it before its first refresh** (`materialized view "x" has not been populated`). So the library separates three things: *creating* the object, *first population*, and *exposing* it to readers.

## Population policies

Declared per view, via `->withPopulationPolicy(...)`:

| Policy | What `sync` does | Use for |
|---|---|---|
| `PopulationPolicy::Manual` | Creates the view and indexes, **no refresh** | Views populated by an external job |
| `PopulationPolicy::Async` | Creates the view, then **dispatches/schedules** a refresh | Default to avoid a blocking boot |
| `PopulationPolicy::Synchronous` | Performs the first (non-concurrent) refresh **before returning** | Small or critical views read immediately |
| `PopulationPolicy::RequiredBeforeRead` | Strict variant: the library exposes a *not-ready* state until the first refresh | ORM/API projections with no fallback |

**Default for a reusable library:** `Manual` in the core; `Async` as the bundle preset *when Messenger/Scheduler is configured*; **never** `Synchronous` globally by default.

## Consequences you must design for

- `sync` must not block an entire fleet of databases with full initial refreshes unless explicitly asked (`--refresh-initial` or `Synchronous`).
- When the same managed views run across multiple databases, `Async` must carry the **target database identity** in the refresh job; it must not depend on the current HTTP request or hostname. `Async` also presupposes a **shared message transport** — see the [bundle async page](../../../materialized-view-bundle/docs/guide/async-refresh.md).
- An ORM entity mapped onto a not-yet-populated view must be protected by a read strategy: guaranteed synchronous refresh, a `ReadinessChecker` guard, an explicit application exception, or a business fallback.
- `ReadinessChecker` reads `relispopulated` and **memoises it per HTTP request / per console run** — never one catalog round-trip per read.
- `matview:validate` reports managed views that are not populated, and the ORM entities mapped onto them.

## Missing referenced schema or table

A view whose SQL references a schema/table that is absent in the target database (e.g. an FDW schema not yet set up on local/UAT) cannot be created: PostgreSQL raises `SQLSTATE 42P01` (undefined_table) / `3F000` (invalid_schema_name). By default `sync` lets that error abort the whole run. The **missing-dependency policy** makes this opt-in tolerant: with `skip` (`SyncOptions::withMissingDependencyPolicy(MissingDependencyPolicy::Skip)`, or `sync.on_missing_dependency: skip` in the bundle), `sync` logs a warning and continues with the other views, reporting the skipped ones in its outcome. The decision is taken on the driver SQLSTATE read from the DBAL exception chain, never on message text; unrelated database errors still abort.

## The trade-off triangle (decide per view)

| Priority | Typical policy | Consequence |
|---|---|---|
| Fast boot | `Async` or `Manual` | Cold/not-ready projection after deploy until the refresh |
| Immediate read availability | `Synchronous` | Slower boot; non-concurrent initial refresh |
| Simplicity of drop-all | `Async` + readiness guard | An explicit analytics-unavailability window |

There is no universally correct choice — it depends on whether a given view backs a user-facing read. User-facing critical views should use `Synchronous` (or an explicit fallback); non-critical analytics views can use `Async` with a readiness guard.

> Related: during the boot lane, managed views are dropped before migrations and recreated after. Combined with `Async`, that means a deliberate "cold projection" window after every migrating deploy. The boot story is documented in the [bundle](../../../materialized-view-bundle/docs/guide/boot-lane.md).
