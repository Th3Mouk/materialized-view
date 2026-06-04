# Doctrine references (maintainers)

The Doctrine DBAL and ORM contracts the library builds on. Each item notes the official documentation **and** the exact symbol verified in the installed vendor at design time (DBAL 4.4.3, ORM 3.6.6, Migrations 3.9.5, DoctrineBundle 3.x). Re-verify these on every Doctrine major/minor bump.

## DBAL — connection & execution

- **Schema Manager / introspection** — <https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/schema-manager.html>
- `Doctrine\DBAL\Connection`
  - `executeStatement(string): int|string` — DDL / `REFRESH` / `ANALYZE`.
  - `executeStatement(string, array $params, array $types): int|string` — parameterised; advisory-lock calls bind `int4` arguments with `Doctrine\DBAL\ParameterType::INTEGER` (`LaneLock`, `ViewRefreshLock`).
  - `fetchAllAssociative()`, `fetchFirstColumn()`, `fetchOne()` — catalog introspection.
  - `transactional(Closure): mixed` — wraps the side-by-side swap statements in one transaction (`SideBySideRebuilder`).
  - `createSchemaManager()`, `getDatabasePlatform()`.
  - *Verified present in vendor `doctrine/dbal/src/Connection.php`: `executeStatement()`, `transactional()` (l.957); `Doctrine\DBAL\ParameterType::INTEGER` (`ParameterType.php`).*

## DBAL — identifier & literal quoting

The library never string-concatenates user values into SQL; identifiers and literals are quoted through the platform/connection contract (`IdentifierQuoter`, `GrantStatementGenerator`, `RebuildStatementFactory`).

- `Doctrine\DBAL\Platforms\AbstractPlatform`
  - `quoteSingleIdentifier(string): string` — quote one identifier segment (schema, view, index, column). *Verified `AbstractPlatform.php:1337`.*
  - `quoteStringLiteral(string): string` — quote a string literal (the `COMMENT … IS '<json>'` management marker, `SET lock_timeout = '<value>'`). *Verified `AbstractPlatform.php:2355`.*
- `Doctrine\DBAL\Connection`
  - `quoteSingleIdentifier(string): string` — the connection-level shortcut used by `MaterializedViewManager`/synchronizer for `ANALYZE` and `REFRESH` names. *Verified `Connection.php:579`.*
  - **`Connection` has no `quoteStringLiteral()` in DBAL 4** — string literals must go through `getDatabasePlatform()->quoteStringLiteral()` (this is why `IdentifierQuoter::quoteStringLiteral()` delegates to the platform, not the connection). *Verified absent in vendor `Connection.php`.*

## DBAL — materialized views are invisible to the comparator

- `AbstractSchemaManager::introspectViews()` **exists** but reads **plain views** (`pg_views`), not `pg_matviews`.
- `PostgreSQLSchemaManager::selectTableNames()` filters tables to **`relkind IN ('r', 'p')`** — materialized views are `relkind = 'm'`, so they are **never** introspected as tables.
- Consequence: a materialized view is **invisible to `doctrine:migrations:diff`** and the `Comparator`; the library does not need `schema_filter` to hide matviews from the diff. (`schema_filter` remains good hygiene for analytics *tables* and plain views — for example a host application may filter such names with a regex like `~^(?!report_)~`.)
- *Verified in vendor: `PostgreSQLSchemaManager.php` (`relkind IN ('r','p')`), `AbstractSchemaManager.php::introspectViews()`, `createComparator()`.*

## DBAL — primary/replica

- `Doctrine\DBAL\Connections\PrimaryReadReplicaConnection::ensureConnectedToPrimary(): void` — call **before** any DDL/refresh and before taking the lane advisory lock.
- A host application typically configures this via its Doctrine connection `wrapper_class: PrimaryReadReplicaConnection`.
- *Verified present in vendor `doctrine/dbal/src/Connections/PrimaryReadReplicaConnection.php:199`.*

## ORM — read-only entities

- **Attributes reference** — <https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/attributes-reference.html>
- **UnitOfWork** — <https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/unitofwork.html>
- `#[ORM\Entity(readOnly: true)]` — the `readOnly` constructor parameter exists (`Mapping/Entity.php`).
- `UnitOfWork::markReadOnly()` / `isReadOnly()` — used by the optional `MaterializedViewPostLoadListener`.
- These reduce risk but are not sufficient — the `onFlush` write guard provides the explicit, debuggable failure.
- *Verified present in vendor `doctrine/orm/src/Mapping/Entity.php` and `UnitOfWork.php`.*

## Doctrine Migrations — programmatic lane

- **Migration classes** — <https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/migration-classes.html>
- The bundle's `doctrine-lane` runs migrations **in-process** while holding the advisory lock. Two supported paths:
  1. **Preferred:** invoke the existing `doctrine:migrations:migrate` command via the console `Application` (preserves configuration, console output and events).
  2. **Direct:** `DependencyFactory::getMigrator(): Migrator` + a hand-built `MigratorConfiguration` (`allOrNothing`, `dryRun`), with the plan from `getMigrationPlanCalculator()`.
- `DependencyFactory` has **no autowiring alias** — inject it explicitly: `#[Autowire(service: 'doctrine.migrations.dependency_factory')]`.
- Pending-migration detection uses `getMigrationStatusCalculator()->getNewMigrations()` (the same logic as `migrations:up-to-date`).
- *Verified in vendor `doctrine/migrations/src`: `DependencyFactory::getMigrator()` (l.403), `getConsoleInputMigratorConfigurationFactory()` (l.384), `getMigrationPlanCalculator()` (l.362), `Migrator::migrate(MigrationPlanList, MigratorConfiguration)`; bundle service id `doctrine.migrations.dependency_factory` (`config/services.php`).*

> ⚠️ **Doctrine Migrations exposes no reliable API for the tables a pending migration will touch** — migrations are arbitrary `addSql()`. This is why `drop --if-pending` is conservative (drop-all) at MVP and a targeted drop is a future, application-supplied `MigrationImpactResolver` (or a reactive catch-and-retry). See the [bundle boot lane](../../../materialized-view-bundle/docs/guide/boot-lane.md).

## Symfony

Symfony-specific contracts (bundle, autoconfiguration, Messenger, Scheduler) are documented in the bundle: [`../../../materialized-view-bundle/docs/internals/symfony-references.md`](../../../materialized-view-bundle/docs/internals/symfony-references.md).
