# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0]

### Added
- **Doctrine DBAL is now optional — the core runs on any connection backend.**
  The engine talks to PostgreSQL through a new
  `Th3Mouk\MaterializedView\Core\Database\Connection` port
  (`executeStatement` / `fetchOne` / `fetchAllAssociative` / `fetchAssociative` /
  `transactional` / `ensureConnectedToPrimary`), so `Core` no longer depends on
  Doctrine DBAL. Two adapters ship with the package:
  - `Th3Mouk\MaterializedView\Dbal\DbalConnection` — wraps a Doctrine DBAL
    connection and keeps its primary/replica routing, middlewares and profiling.
  - `Th3Mouk\MaterializedView\Pdo\PdoConnection` — wraps a bare `PDO` handle (the
    `pdo_pgsql` driver) for projects that do not run Doctrine.

  New factories on `MaterializedViewManager`: `forDriver(Connection $connection, …)`
  (framework-agnostic) and `forPdo(PDO $pdo, …)` (bare PDO). Backend failures are
  normalised to `Th3Mouk\MaterializedView\Core\Database\DatabaseException`, which
  carries the SQLSTATE so missing-dependency handling keeps working on either
  backend. See [Connection backends](docs/guide/connection-backends.md).

### Changed
- **`doctrine/dbal` moved from `require` to `suggest`.** The core now requires only
  `php` and `psr/log`. `MaterializedViewManager::forConnection(Doctrine\DBAL\Connection)`
  is unchanged and keeps working exactly as before — it now wraps the connection in
  `DbalConnection` internally — so **existing Doctrine code (and the Symfony bundle)
  needs no changes**. Install `doctrine/dbal` for the recommended, natively-supported
  Doctrine backend, or rely on `ext-pdo_pgsql` with `forPdo()` and no extra Composer
  dependency. `IdentifierQuoter` is now PostgreSQL-native (it no longer needs a DBAL
  platform); its internal `forConnection()` / `forPlatform()` factories were removed.
  The optional reactive-conflict helpers (`PostgresDependencyConflict::fromDriverException()`,
  `DependencyConflictSqlState::isDependencyConflict()`) remain Doctrine-oriented; on a
  bare PDO backend, classify with `PostgresDependencyConflict::fromRawError()`.

## [1.2.0] - 2026-06-09

### Added
- **The failing view and an aggregate rollup are now logged when a batch
  `sync` / `refresh-all` aborts under the `fail` policy.** Previously an error
  raised while building or refreshing a view simply propagated to the caller
  with no library record, so the boot output (and Datadog) never named the view
  that broke. The batch operations now emit PSR-3 records on the same logger as
  the rest of the library, then re-throw the original error untouched:
  - `MaterializedViewSynchronizer::synchronize()` logs an `error`
    (`synchronisation aborted while building "{view}"`) carrying the failing
    `view`, its `action` (`create`/`rebuild`), the `sqlstate_reason`, and a
    progress rollup (`created`, `rebuilt`, `skipped`, `remaining`, `managed`).
  - `MaterializedViewManager::refreshAll()` now brackets the batch with `info`
    records (`refresh-all started` / `refresh-all completed`, with `refreshed`
    and `total`) and, on failure, logs an `error` (`refresh-all aborted at
    "{view}"`) naming the failing `view` with a `refreshed` / `remaining` /
    `total` rollup.

  Only observability is added — the exception still propagates unchanged, so the
  caller keeps full control over exit behaviour while operators get the failing
  view and partial progress in the logs.
- **Reactive dependency-conflict primitives**, enabling a consumer (such as the
  Symfony bundle's deploy lane) to clear a migration blocked by a managed
  materialized view by dropping *only* the conflicting closure instead of every
  managed view:
  - `Core\Sql\DependencyConflictSqlState` recognises the two SQLSTATEs a blocking
    view raises — `2BP01` (`dependent_objects_still_exist`, a blocked
    `DROP TABLE` / `DROP COLUMN`) and `0A000` (`feature_not_supported`, a blocked
    `ALTER COLUMN ... TYPE`) — by walking the DBAL driver-exception chain, never
    the locale-dependent message text.
  - `Core\Dependency\PostgresDependencyConflict` parses such an error
    (best-effort, locale-aware) into the blocked relation and the dependent
    objects it names; `Core\Sql\QualifiedName` adds the quote-aware identifier
    scanner this requires.
  - `Core\Dependency\CatalogDependencyResolver::resolveConflictClosure()` resolves,
    from the system catalog, the transitive **managed** matview dependents of an
    arbitrary relation (typically a plain table, which is not a node in the
    managed graph) in safe drop order, plus any unmanaged dependents found.
  - `Core\MaterializedViewManager::dropConflictClosure()` drops that closure in
    order, refusing (`UnmanagedDependentFound`) when it contains an unmanaged
    dependent unless `DropDependentPolicy::Cascade` is given. The drop set is
    always confirmed against the catalog, never from the error text alone.
  - `Core\Sql\ManagementMarker` gains read-side helpers (`isManagedComment()`,
    `readHash()`) so a catalog comment can be classified as library-managed.

  These are framework-agnostic primitives only; no existing behaviour changes.

## [1.1.0] - 2026-06-05

### Added
- **Structured PSR-3 logging across the library.** The operational services now
  accept an optional `Psr\Log\LoggerInterface` as their last constructor argument
  (defaulting to `NullLogger`, so behaviour is unchanged when no logger is
  injected) and emit records at levels chosen per RFC 5424 semantics:
  - `debug` — DDL about to run, advisory-lock acquire/release, catalog and
    introspection probes, readiness cache hits/misses, per-view create/refresh start.
  - `info` — synchronisation started and completed (with created/updated/skipped/
    pruned counts and `duration_ms`), a view (re)created, refresh completed
    (`duration_ms`), GRANTs snapshotted/replayed (with counts).
  - `notice` — data-affecting actions: `DROP … CASCADE` of unmanaged dependents
    (the dependents are named in the context), dropping all managed views because
    migrations are pending, recreating an existing view.
  - `warning` — recoverable anomalies: a view skipped because its referenced
    schema/table is absent (`SQLSTATE 42P01` / `3F000`), advisory-lock contention,
    a view read while not yet populated, and the ORM write-guard blocking a write
    to a read-only materialized-view entity.

  Messages use `{placeholder}` tokens with a small, business-relevant context array
  (`view`, `schema`, `strategy`, `duration_ms`, `count`, `sqlstate`, `dependents`, …).
  Errors that simply propagate to the caller are not logged here — the caller (or
  the Symfony bundle) decides.

### Changed
- `psr/log` is now a hard runtime dependency (`require: ^3.0`) instead of a
  `suggest`. PSR-3 is the framework-agnostic logging abstraction this library
  targets: inject any PSR-3 logger (for Symfony/Monolog use
  `th3mouk/materialized-view-bundle`), or omit it for silent (`NullLogger`) operation.

## [1.0.0] - 2026-06-05

### Added
- Initial release. Declarative, PostgreSQL-native management of **materialized
  views** on top of Doctrine DBAL: versioned `.sql` definitions with a small PHP
  definition, drift detection (content-hash in the view `COMMENT`), safe rebuilds
  (`drop_create` and `side_by_side`), `REFRESH [CONCURRENTLY]` with advisory-lock
  coordination, GRANT snapshot/replay across rebuilds, an optional Doctrine ORM
  read layer with readiness guards, and the `on_missing_dependency` (`fail`/`skip`)
  and `on_external_dependent` (`refuse`/`cascade`) policies.

### Changed
- Development dependency `phpunit/phpunit` upgraded to `^13.0`. Connection test
  doubles configured without expectations use `createStub()` / `getStubBuilder()`
  as required by PHPUnit 13.

[1.3.0]: https://github.com/Th3Mouk/materialized-view/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/Th3Mouk/materialized-view/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/Th3Mouk/materialized-view/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Th3Mouk/materialized-view/releases/tag/v1.0.0
