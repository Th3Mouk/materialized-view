# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/Th3Mouk/materialized-view/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/Th3Mouk/materialized-view/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Th3Mouk/materialized-view/releases/tag/v1.0.0
