# Refresh runtime & locking

`MaterializedViewManager::refresh()` is the runtime path that keeps a view's data current. It is deliberately defensive.

## What `refresh()` does, in order

1. Resolve the definition.
2. **Force the primary connection** (`ensureConnectedToPrimary()`). On the Doctrine DBAL backend this switches a `PrimaryReadReplicaConnection` to the primary so DDL/refresh never hits a replica; on a bare PDO connection there is no read/write split and this is a no-op. See [Connection backends](connection-backends.md).
3. Apply `lock_timeout` and `statement_timeout`.
4. Take a **per-view advisory lock**: `pg_advisory_lock(lock_namespace, view_key)`.
5. Validate `CONCURRENTLY` preconditions: view populated, a complete UNIQUE index (no predicate, no expression).
6. Execute the `REFRESH` via `Connection::executeStatement()`.
7. `ANALYZE` if configured.
8. Record duration / status / error if the observability table is enabled.

PostgreSQL already permits only one `REFRESH` per view at a time; the advisory lock adds *application-visible* behaviour: fail-fast, configurable timeout, clean logs and metrics.

## `REFRESH … CONCURRENTLY` preconditions

`CONCURRENTLY` is only valid when **all** of these hold (enforced before issuing the statement):

- the view is already **populated** (`relispopulated = true`);
- there is **at least one UNIQUE index** that uses only column names and covers **all rows** — i.e. **not** an expression index and **not** partial (`WHERE`).

If the preconditions are not met, the command must explicitly refuse or downgrade (per the chosen option). `WITH DATA` is the default of `REFRESH` and is omitted on concurrent refreshes.

## Advisory lock keys — stable, computed in PHP

The second lock key must be a **stable `int4`** derived from the qualified view name. **Do not** use `hashtext()` / `hashtextextended()` — they are internal, non-contractual PostgreSQL functions whose output is not guaranteed stable across major versions (using them would change a view's lock key on a major upgrade, silently weakening mutual exclusion during the upgrade window).

`StableLockKeyGenerator` freezes the algorithm in PHP, e.g. `crc32b` over the canonical `schema.name`, converted to a signed `int4`:

```php
$hex      = hash('crc32b', $qualifiedViewName);
$unsigned = (int) hexdec($hex);
$viewKey  = $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;
```

A collision is possible; its only effect is the **occasional over-serialization** of two distinct views — never a loss of safety. A single key per namespace would be too coarse: it would serialize *every* refresh in a database.

## Scope & namespaces

- PostgreSQL advisory locks are **local to a database**: two connections to two different databases can use the same keys without blocking each other. (Confirmed: the `database` column is meaningful for advisory locks in `pg_locks`.)
- The numeric lock namespaces (`lane.lock_namespace`, `refresh.lock_namespace`) must be **documented and reserved** by the application to avoid clashes with other application-level advisory locks.
- The lane lock is session-level and single-key (`pg_advisory_lock(lane.lock_namespace)`); the refresh lock is two-key (namespace + view key). Always release session-level locks in a `finally`.

See the exact upstream guarantees in [PostgreSQL references](../internals/postgresql-references.md).
