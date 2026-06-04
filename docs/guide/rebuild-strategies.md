# Rebuild strategies

PostgreSQL has **no `CREATE OR REPLACE MATERIALIZED VIEW`**. Any change to a view's definition is therefore a *rebuild* (drop + create), which is destructive and must re-apply indexes and GRANTs, and respect dependents. Two strategies are provided.

## `drop_create` (default)

Simple and robust:

```text
DROP MATERIALIZED VIEW IF EXISTS view;
CREATE MATERIALIZED VIEW view AS ...;
CREATE INDEX ...;
COMMENT ON MATERIALIZED VIEW view IS ...;
-- Optional, according to the population policy:
-- REFRESH MATERIALIZED VIEW view;
```

Good for boot, and for environments where a brief projection outage is acceptable.

- **Managed dependents**: if the view has dependent *managed* matviews, it cannot be dropped naively. The library rebuilds the **closure of managed dependents** in the correct order, or uses the drop-all / sync-all boot path. See [Dependencies & refresh order](dependencies-and-ordering.md).
- **Unmanaged dependents**: if a non-managed object depends on the view, `drop_create` **refuses before issuing the `DROP`** by default. `CASCADE` is **never** implicit — it would destroy external objects the library cannot recreate. See [Privileges](privileges.md) and the `ExternalDependencyGuard`. This default can be overridden per project: with the `cascade` external-dependent policy (`drop.on_external_dependent: cascade` in the bundle, or `SyncOptions::withDropDependentPolicy(DropDependentPolicy::Cascade)` / `MaterializedViewManager::drop(..., DropDependentPolicy::Cascade)` in the core), the drop/rebuild proceeds with `DROP MATERIALIZED VIEW ... CASCADE` and the guard no longer blocks. Use it only when external consumers (e.g. Superset) are out of scope and managed views must be freely recreatable by migrations.

## `side_by_side` (low-lock, leaf views only)

Minimises read unavailability by building the new view next to the old one and swapping names. **Order matters** — the renamed old view keeps the *final* index names until it is dropped:

1. Create `view__mv_tmp_<hash>`.
2. Create the temporary indexes with **temporary** names (the final names are still taken).
3. Populate the temporary view.
4. Take a short lock.
5. Rename `view` → `view__mv_old_<hash>`.
6. Rename the temporary view → `view`.
7. **Drop the old view** — this frees the final index names.
8. Rename the temporary indexes to their final names.
9. Re-apply GRANTs and the management COMMENT on the new view.

This is not free — the renames still take locks — but it avoids blocking reads during the build and refresh of the new view.

> **Restriction:** `side_by_side` is **invalid for a view that has dependents**. PostgreSQL binds dependencies by OID; renaming the old view does not re-point dependents at the new OID. For the MVP, `side_by_side` must be refused on non-leaf views, or fall back to a full closure rebuild.

## Index re-application

A rebuild loses the view's indexes. Two sources are possible:

- indexes declared in `MaterializedViewDefinition` (the source of truth at MVP);
- a snapshot of `pg_indexes` for the old view (`IndexSnapshotter`), used as a safety net or to adopt pre-existing views.

## Privilege re-application

A rebuild also loses object GRANTs. This is important enough to have its own page: [Privileges](privileges.md).
