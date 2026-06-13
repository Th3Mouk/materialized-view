# Design rationale (maintainers)

Why the library is shaped the way it is, the trade-offs taken, and the guarantees it does — and does not — make.

## The twelve decisions

1. **Connection port; Doctrine optional.** The core runs on a tiny `Core\Database\Connection` port, not on Doctrine directly. A Doctrine DBAL adapter (`Dbal\DbalConnection`) is the recommended, natively-supported backend; a `Pdo\PdoConnection` adapter runs the engine without Doctrine. Never Symfony, never the ORM, in `Core`.
2. **PostgreSQL only at MVP.** Materialized views have PostgreSQL-specific semantics.
3. **SQL source of truth = versioned `.sql` files.** No large PHP heredoc as the recommended mode.
4. **Declarative synchronisation by default.** `matview:sync`, plus an optional Doctrine Migrations lane for Symfony apps.
5. **Explicit migrations as an option.** Useful for isolated cases; not the default for an application running the same managed views across many databases.
6. **Explicit initial population.** Boot must not refresh every view synchronously by default; each view declares its policy.
7. **ORM for reading only.** Entities mapped onto views are read-only and protected by a write guard.
8. **Hash in the COMMENT.** The canonical definition hash lives in `COMMENT ON MATERIALIZED VIEW`, with an optional metadata table for observability.
9. **Catalog-derived refresh dependencies.** Manual `dependsOn()` stays possible for external deps, but matview→matview ordering comes from `pg_depend`/`pg_rewrite`.
10. **Symfony by attributes.** The bundle discovers definitions via `#[AsMaterializedViewProvider]`; no interface required in application code.
11. **Explicit destructive prune.** A managed-but-undeclared view is never dropped at boot without `--prune` or a dedicated command.
12. **Robust refresh.** Primary DBAL connection, timeouts, advisory lock, `CONCURRENTLY` validation, `ANALYZE`, metrics.

## Why these choices

- **Why a connection port, DBAL optional** — the engine only needs to run statements, fetch rows and open a transaction; that is a tiny surface. Expressing it as a port keeps `Core` free of any database library, so a project without Doctrine can drive materialized views over a bare PDO connection. DBAL stays the recommended backend because it knows the real connection, the primary/replica wrappers, middlewares, profiling, transactions and the PostgreSQL driver — its adapter reuses all of that; the PDO adapter trades those operational features for a zero-dependency footprint. Quoting is PostgreSQL-native PHP (identical output to DBAL's platform), so it needs no driver at all.
- **Why a `.sql` file over a PHP heredoc** — syntax highlighting, direct `psql` execution, readable Git diffs, simpler review, easy scaffolding, better team adoption. The PHP class stays for the name, indexes, options and strategy.
- **Why declarative by default** — it is what makes the boot lane safe when the same managed views run across many databases/connections: drop managed projections before table DDL, recreate them after migrations, so a developer never has to guess which migration must drop which view.
- **Why not extend DBAL `Schema`** — a materialized view change is not a plain schema diff: no `CREATE OR REPLACE`, potentially destructive rebuild, runtime refresh, `CONCURRENTLY` constraints, indexes and dependencies. A dedicated system keeps those decisions explicit.
- **Why a limited ORM role** — a matview is a physical projection. The ORM may read it as a read-only entity but must neither persist it nor own its DDL.

## Competitive landscape (why build this at all)

At design time, no maintained, framework-agnostic PHP library managed PostgreSQL materialized views:

- `staudenmeir/laravel-migration-views` — the only PHP package with genuine native matview support (`createMaterializedView`/`refreshMaterializedView`), but **Laravel-only**.
- `calebdw/laravel-sql-entities` — the cleanest "entities as code" design, but it implements **only plain views, functions, triggers — no materialized views** — and is Laravel-only.
- `kenny1911/doctrine-views-sync` — Symfony/Doctrine, but **plain views only**, **0 stars**, and pinned to `doctrine/dbal <4.3` (a version lock already biting).
- `wladislavk/ViewMaterializerBundle`, `mte/doctrineviewsbundle`, `albertsola/DoctrineViews` — abandoned (2017–2018), MySQL-centric, no native matviews.

Common failure modes we explicitly design against: bus-factor-1 abandonment, tight framework/DBAL version locks, no migration integration, MySQL-emulation niche, no real refresh strategy, packaging self-sabotage, high adoption friction. See [Compatibility & evolution](compatibility-and-evolution.md).

## Scope of the conflict-avoidance guarantee

The "automatic, no-guess" conflict avoidance holds **only for views whose dependent closure is entirely managed**. If a managed view has an **unmanaged** dependent (a BI view, another team's object), `ExternalDependencyGuard` **refuses** the drop (correct — never an implicit `CASCADE`); a migration that then alters the view's source table will fail loudly with a clear message, and manual intervention is required. This is the intended trade-off: fail loud rather than destroy silently.

## Risk register

| Risk | Mitigation |
|---|---|
| Costly rebuild at boot | No global synchronous initial refresh; population async or synchronous per view only |
| Useless rebuild on every migration | Conservative drop-all at MVP with explicit logs; future `MigrationImpactResolver` or measured reactive mode |
| Reading an unpopulated view | `ReadinessChecker`, `Synchronous` for critical views, explicit business fallback |
| Async refresh on the wrong database | `AsyncRefreshRequest` carries the target DB; `RefreshTargetResolver` selects the connection |
| Async refresh never consumed | Shared transport required for `Async`; a per-connection Doctrine transport refused unless orchestrated |
| Temporary unavailability | `side_by_side` for leaves only; closure rebuild for views with dependents |
| Unmanaged dependent blocks a rebuild | `ExternalDependencyGuard`, explicit refusal, never implicit `CASCADE` |
| Long refresh | `CONCURRENTLY`, timeouts, advisory lock, metrics |
| Write on a replica | `ensureConnectedToPrimary()` before DDL/refresh |
| Undetected SQL drift | Canonical hash in the COMMENT |
| Reformatting triggers a rebuild | Stable SQL canonicalisation before hashing |
| Forgotten refresh dependencies | matview → plain view → matview deps derived from the catalog |
| False dependency cycle | `pg_depend` query filtered on `pg_rewrite`/`pg_class`/`deptype='n'` and self-loop exclusion |
| Lost GRANTs | Snapshot/replay of privileges or declared GRANTs |
| Concurrent rolling deploy | Single lane command with a per-database advisory lock; readiness/fallback during the old-version window |
| Migration fails after drop | Do not run `sync`; log the degraded state; re-run the lane after fixing |
| Ineffective lane lock | Lock on the primary and on the same connection as Doctrine Migrations |
| Over-serialized refreshes | Two-key advisory lock: namespace + stable view key |
| Lane memory spike | Run the lane with a `memory_limit` at least equal to `migrate` alone |
| View created without management comment | Recognise by registry + idempotent COMMENT repair |
| Accidental orphan deletion | Explicit `prune`, never by default at boot |
| Stale matviews in a PostgreSQL template | Explicit `template.policy`; refresh/validate on first boot of a cloned database |
| Refresh-key collision | Safety preserved, only over-serialization; stable PHP key + reserved lock namespaces |
| Unread old SQL versions | `stable` naming by default, or an explicit versioned bump workflow |
| Lost Doctrine Migrations console behaviour | Prefer the in-process `MigrateCommand`; explicit `MigratorConfiguration` if using the migrator directly |
| Bad SQL file | `matview:validate` + PostgreSQL tests |
| Entity modified by mistake | `#[ORM\Entity(readOnly: true)]` + `MaterializedViewWriteGuard` |
