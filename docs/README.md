# Documentation — th3mouk/materialized-view

This documentation is organised in **three tiers**, by audience.

## 1. Getting started (users)

Set the library up and ship your first materialized view fast.

- [Getting started](getting-started.md)

## 2. Guide (users — advanced concepts)

One focused page per non-trivial concept. Read what you need.

- [Defining views](guide/defining-views.md) — the definition model, SQL files, indexes, generated SQL, naming/versioning
- [Population & read safety](guide/population-and-readiness.md) — `WITH NO DATA`, the unpopulated-read error, population policies, the boot triangle
- [Rebuild strategies](guide/rebuild-strategies.md) — `drop_create`, `side_by_side`, index re-application, external dependents
- [Privileges (GRANTs)](guide/privileges.md) — why rebuilds drop GRANTs, snapshot/replay, object vs database privileges
- [Dependencies & refresh order](guide/dependencies-and-ordering.md) — catalog-derived ordering, closure rebuild
- [Refresh runtime & locking](guide/refresh-and-locking.md) — `CONCURRENTLY`, primary/replica, timeouts, advisory locks, stable keys
- [Drift detection & hashing](guide/drift-and-hashing.md) — the canonical hash, the management comment
- [Doctrine ORM integration](guide/doctrine-orm-integration.md) — read-only entities, write guard, readiness guard
- [Migration-owned mode](guide/migration-owned-mode.md) — the optional alternative to declarative sync

## 3. Internals (maintainers)

How and why the library is built, and the upstream contracts it depends on. **Keep these pages in sync with upstream releases.**

- [Architecture](internals/architecture.md) — package layout, class map, data flows
- [Design rationale](internals/design-rationale.md) — the decisions, the trade-offs, the risk register, scope of guarantees
- [PostgreSQL references](internals/postgresql-references.md) — every PG behaviour relied upon, with official links
- [Doctrine references](internals/doctrine-references.md) — DBAL/ORM contracts relied upon, with official links
- [Compatibility & evolution](internals/compatibility-and-evolution.md) — version policy and what to watch upstream
- [Validation plan](internals/validation-plan.md) — the full test matrix

## Scope at a glance

**Goals**

- Declare a materialized view in application code with a readable, versioned `.sql` file.
- Synchronise the real PostgreSQL state with the declared definitions.
- Avoid table-DDL ↔ materialized-view conflicts automatically (see the [bundle](../../materialized-view-bundle) for the boot lane that runs the same managed views across many databases/connections).
- Refresh one or many views with strong runtime guarantees.
- Re-apply a view's indexes (and GRANTs) automatically after a rebuild.
- Read materialized views through Doctrine ORM as read-only projections.
- Emit migration SQL when a project prefers the migration-owned mode.

**Non-goals (at MVP)**

- Multi-RDBMS support — PostgreSQL materialized views have specific semantics.
- Incremental refresh — PostgreSQL refreshes the whole view.
- Generating the source SQL automatically from ORM entities.
- Replacing Doctrine Migrations for tables.
- A full job scheduler — the bundle exposes commands and can integrate with Messenger, but does not become an orchestrator.
