# PostgreSQL references (maintainers)

Every PostgreSQL behaviour the library relies on, with the official documentation it is grounded in. **When you upgrade the supported PostgreSQL range, re-read these pages and re-run the integration suite.** Links pin to v17; check the `current` docs for newer majors.

## Materialized views

- **CREATE MATERIALIZED VIEW** — <https://www.postgresql.org/docs/17/sql-creatematerializedview.html>
  - There is **no `CREATE OR REPLACE MATERIALIZED VIEW`**. A definition change is a drop + create → see [Rebuild strategies](../guide/rebuild-strategies.md).
  - `WITH NO DATA` creates the object **unscannable**: querying it before the first `REFRESH` raises `materialized view "x" has not been populated`. This drives the [population policies](../guide/population-and-readiness.md).
- **REFRESH MATERIALIZED VIEW** — <https://www.postgresql.org/docs/17/sql-refreshmaterializedview.html>
  - `CONCURRENTLY` *"is only allowed if there is at least one UNIQUE index on the materialized view which uses only column names and includes all rows; that is, it must not be an expression index or include a WHERE clause."*
  - `CONCURRENTLY` *"can only be used when the materialized view is already populated"*, and *"CONCURRENTLY and WITH NO DATA may not be specified together."*
  - `WITH DATA` is the default; omitted on concurrent refreshes.
- **CREATE INDEX** (incl. `CONCURRENTLY`) — <https://www.postgresql.org/docs/17/sql-createindex.html>
  - `CREATE INDEX CONCURRENTLY` **cannot run inside a transaction block** → migration-owned mode sets `isTransactional(): false`. Non-concurrent index creation *can* be in the same transaction as `CREATE MATERIALIZED VIEW` + `COMMENT`.

## Identifiers

- **Identifier length (NAMEDATALEN)** — <https://www.postgresql.org/docs/17/sql-syntax-lexical.html> — identifiers are limited to **63 bytes** (`NAMEDATALEN - 1`); longer names are silently truncated by the server. The library rejects schema/view identifiers over 63 characters up front (`MaterializedViewName::MAX_IDENTIFIER_LENGTH = 63`) rather than letting PostgreSQL truncate them.

## Catalog introspection

- **`pg_class` / `relkind` / `relispopulated`** — <https://www.postgresql.org/docs/17/catalog-pg-class.html>
  - Materialized views are `relkind = 'm'`. `relispopulated` tells whether a `REFRESH` has populated the view (used by `ReadinessChecker` and the `CONCURRENTLY` precondition).

Matview introspection:

```sql
SELECT
    n.nspname AS schema_name,
    c.relname AS view_name,
    pg_get_viewdef(c.oid, true) AS definition,
    c.relispopulated AS is_populated,
    obj_description(c.oid, 'pg_class') AS comment
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind = 'm'
  AND n.nspname = :schema_name;
```

> We never diff `pg_get_viewdef()` for drift detection — PostgreSQL normalises stored definitions. Drift is decided by our [canonical hash](../guide/drift-and-hashing.md).

Index introspection:

```sql
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = :schema_name
  AND tablename = :view_name;
```

## Dependencies

- **`pg_depend`** — <https://www.postgresql.org/docs/17/catalog-pg-depend.html>
- **`pg_rewrite`** — <https://www.postgresql.org/docs/17/catalog-pg-rewrite.html>

There is no direct dependency of a view on the objects it reads — the dependent object is the view's `_RETURN` **rewrite rule**. The query below is the **correct, filtered** form. The filters are mandatory (see inline note):

```sql
SELECT DISTINCT
    dependent_ns.nspname     AS dependent_schema,
    dependent_class.relname  AS dependent_view,
    dependent_class.relkind  AS dependent_relkind,
    referenced_ns.nspname    AS referenced_schema,
    referenced_class.relname AS referenced_view,
    referenced_class.relkind AS referenced_relkind
FROM pg_depend dep
JOIN pg_rewrite rw ON rw.oid = dep.objid
JOIN pg_class dependent_class ON dependent_class.oid = rw.ev_class
JOIN pg_namespace dependent_ns ON dependent_ns.oid = dependent_class.relnamespace
JOIN pg_class referenced_class ON referenced_class.oid = dep.refobjid
JOIN pg_namespace referenced_ns ON referenced_ns.oid = referenced_class.relnamespace
WHERE dep.classid    = 'pg_rewrite'::regclass
  AND dep.refclassid = 'pg_class'::regclass
  AND dep.deptype    = 'n'                        -- normal deps only
  AND dependent_class.oid <> referenced_class.oid -- exclude the _RETURN self-edge
  AND dependent_class.relkind  IN ('m', 'v')
  AND referenced_class.relkind IN ('m', 'v');
```

Without `deptype = 'n'` and the self-exclusion, the `_RETURN` rule reports the view depending on itself → a false self-edge that breaks topological sorting. Both ends are filtered to `relkind IN ('m', 'v')` and the `relkind` columns are selected so that `matview → plain view → matview` chains can be captured: `CatalogDependencyResolver` keeps the plain-view (`'v'`) intermediates in the result set and collapses the `matview → 'v' → matview` hops in PHP (`collapsedMaterializedViewEdges()`), rather than filtering both ends to `'m'` in a single SQL hop. Reference: CYBERTEC, "Tracking view dependencies in PostgreSQL" — <https://www.cybertec-postgresql.com/en/tracking-view-dependencies-in-postgresql/>.

- **DROP MATERIALIZED VIEW** — <https://www.postgresql.org/docs/17/sql-dropmaterializedview.html>
  - Without `CASCADE`, the drop **fails** if any object depends on the view. The library never adds `CASCADE` implicitly; `ExternalDependencyGuard` refuses first.

## Privileges & comments

- **GRANT** — <https://www.postgresql.org/docs/17/sql-grant.html> — dropping an object removes its privileges; a rebuild must re-apply them. See [Privileges](../guide/privileges.md).
  - Replay reads the existing grants from **`information_schema.role_table_grants`** — <https://www.postgresql.org/docs/17/infoschema-role-table-grants.html>. That view "identifies all privileges granted on tables or views" and exposes the `grantee`, `privilege_type` and `is_grantable` columns the library selects (filtered by `table_schema`/`table_name` in `PrivilegeSnapshotter`). The snapshot is captured **before** the closure is dropped, then replayed after each member is rebuilt.
  - Replay emits `GRANT <privileges> ON TABLE <matview> TO <grantee>` (`GrantStatementGenerator`). There is no `GRANT … ON MATERIALIZED VIEW` form — a materialized view is reached through the **`ON TABLE`** grant syntax (verified against the integration suite). The pseudo-role **`PUBLIC`** is rendered **unquoted**: the GRANT page calls it a key word ("The key word `PUBLIC` indicates that the privileges are to be granted to all roles"), so it is emitted verbatim rather than quoted as an identifier; every other grantee is quoted.
- **ALTER DEFAULT PRIVILEGES** — <https://www.postgresql.org/docs/17/sql-alterdefaultprivileges.html> — an alternative the platform may rely on.
- **COMMENT** — <https://www.postgresql.org/docs/17/sql-comment.html> — stores the management hash; travels with database clones.

## Maintenance & runtime tuning

- **ANALYZE** — <https://www.postgresql.org/docs/17/sql-analyze.html> — refreshes planner statistics after a (re)build or refresh. **Only run on a populated view**: a view created/refreshed `WITH NO DATA` is unscannable, so `ANALYZE` is skipped until the view has data (`applyPopulationAndAnalyze` in sync, the `withData` gate in `MaterializedViewManager::refresh()`).
- **`SET lock_timeout` / `SET statement_timeout`** — <https://www.postgresql.org/docs/17/runtime-config-client.html> — bounded waiting around `REFRESH`; applied before the refresh and reset to `DEFAULT` afterwards (`MaterializedViewManager::applyTimeouts()` / `resetTimeouts()`).
- **ALTER MATERIALIZED VIEW … RENAME** — <https://www.postgresql.org/docs/17/sql-altermaterializedview.html> — used by the side-by-side rebuild swap (`ALTER MATERIALIZED VIEW … RENAME TO …`, `ALTER INDEX … RENAME TO …`).

## Advisory locks

- **Advisory lock functions** — <https://www.postgresql.org/docs/17/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS>
  - Two forms: `pg_advisory_lock(bigint)` and `pg_advisory_lock(int4, int4)` (the two key spaces do not overlap). The lane uses the single-argument `bigint` form (`LaneLock`); the per-view refresh lock uses the two-argument `int4, int4` form (`ViewRefreshLock`).
  - Acquisition is `pg_advisory_lock` (blocking) or `pg_try_advisory_lock` (non-blocking, returns `boolean`); release is `pg_advisory_unlock`. All three are issued in both the one- and two-argument forms.
  - Session-level locks are released automatically at session end, even on ungraceful disconnect.
- **`pg_locks`** — <https://www.postgresql.org/docs/17/view-pg-locks.html> — advisory locks are **local to a database** (the `database` column is meaningful), so two connections to two different databases may reuse the same keys without conflicting. This is why the lane lock can be a single constant key per database.
- **Advisory-lock keys are `int4`.** Both arguments of the two-argument form are signed 32-bit integers (`AdvisoryLockKey` asserts the range `[-2147483648, 2147483647]`). The key is derived from the qualified view name by a **frozen library algorithm**: `crc32b('schema.name')` interpreted as an unsigned 32-bit integer, then folded into signed int4 (subtract `2^32` when `≥ 2^31`) — `StableLockKeyGenerator`. This algorithm is a stability contract: it must not change across versions, or running sessions would compute different keys for the same view.
- **`hashtext()` is forbidden** as a lock-key source: it is an internal, non-contractual function whose output has changed across major versions. Compute keys in PHP (`StableLockKeyGenerator`). See [Refresh runtime & locking](../guide/refresh-and-locking.md).

## Database cloning (templates)

- **CREATE DATABASE** — <https://www.postgresql.org/docs/17/sql-createdatabase.html>
  - A `TEMPLATE` clone is a physical copy: tables, **materialized views and their data**, indexes, comments and **object-level** privileges are copied. **Database-level** privileges are **not** copied (apply them via provisioning).
  - *"no other sessions can be connected to the template database while it is being copied"* — constrains a `maintained_template` policy. See the [bundle templates page](../../../materialized-view-bundle/docs/guide/templates-and-cloning.md).

## Transactions & DDL

- **Transactions** — <https://www.postgresql.org/docs/17/tutorial-transactions.html> — most DDL is transactional in PostgreSQL (so `CREATE MATERIALIZED VIEW` + non-concurrent `CREATE INDEX` + `COMMENT` can be atomic). The exceptions are the `CONCURRENTLY` operations (index creation; `REFRESH … CONCURRENTLY` has its own rules) — handle those outside an explicit transaction.
