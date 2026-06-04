# Validation plan (maintainers)

The authoritative test matrix. Every scenario here must be covered before a release. Suites: `tests/Unit` (no database) and `tests/Integration` (real PostgreSQL).

## Core (unit)

- SQL generator with snapshots (`CREATE`/`DROP`/`REFRESH`/`CREATE INDEX`/`COMMENT`/`ALTER … RENAME`).
- Identifier validation and quoting (no string interpolation).
- Canonical hash stability (reformatting / comment changes do **not** change the hash).
- `MaterializedViewComparator` registry ↔ live state.
- `drop --if-pending` driven only by pending migrations or `--all-managed`.
- `sync` handles hash drift without deleting orphans.
- `prune` is required to delete managed-but-undeclared views.
- `PrivilegeSnapshotter` / `PrivilegeReplayer`.
- `ReadinessChecker` for populated/unpopulated views, with per-request/process memoisation.
- `AsyncRefreshRequest` carries the target database identity.
- `Async` refuses a per-connection Doctrine transport when no shared transport is configured.
- `ExternalDependencyGuard` blocks drops/rebuilds/prunes that have unmanaged dependents.
- `StableLockKeyGenerator` is deterministic across PHP platforms and never calls PostgreSQL.
- `MigrationImpactResolver` (optional) can target a closure but is not the default.
- `matview:generate` workflow in both `stable` and `versioned` (bump) naming.

## PostgreSQL (integration)

- Create a view `WITH NO DATA`.
- Create and introspect indexes.
- Refuse `REFRESH … CONCURRENTLY` without a unique index.
- Refuse `REFRESH … CONCURRENTLY` on an unpopulated view.
- First non-concurrent refresh, then a concurrent refresh.
- Read and write the hash in the COMMENT.
- Derive matview → matview ordering from the catalog, **including through a plain-view intermediate**.
- Confirm the `pg_depend` query ignores the `_RETURN` internal dependency and produces no self-loop.
- Rebuild `drop_create`.
- Rebuild a dependents closure.
- Refuse `side_by_side` on a non-leaf view.
- Rebuild `side_by_side` on a leaf: temporary index names → drop old view → rename indexes.
- GRANT snapshot/replay across a rebuild.
- Idempotent resume when `CREATE` succeeds but `COMMENT` fails.
- Refuse a `DROP` without `CASCADE` when an unmanaged dependent exists (clear message).
- Validate unmanaged dependents on `validate`, `sync`, `drop --if-pending`, `prune`.

## ORM (integration, requires doctrine/orm)

- Read-only entity readable via a repository.
- Insert/update/delete blocked by `MaterializedViewWriteGuard`.
- `UnitOfWork::markReadOnly()` applied when the post-load listener is enabled.
- Read guard on an unpopulated matview.
- Readiness guard memoised per request.

## Bundle & boot scenarios

Bundle-level boot scenarios that run the same managed views across many databases (lane, advisory lock, rolling deploy, template cloning) live in the bundle's own plan:
[`../../../materialized-view-bundle/docs/internals/validation-plan.md`](../../../materialized-view-bundle/docs/internals/validation-plan.md).
