# Architecture (maintainers)

This page maps the design to the source tree and shows the main data flows. It is the entry point for anyone implementing or modifying the library.

## Layering

```text
th3mouk/materialized-view
├── src/Core         # framework-free; talks to PostgreSQL only through Core\Database\Connection (no Doctrine)
├── src/Dbal         # optional adapter; wraps a Doctrine DBAL connection
├── src/Pdo          # optional adapter; wraps a bare PDO handle (pdo_pgsql)
└── src/DoctrineOrm  # optional; depends on doctrine/orm (read-only mapping)
```

Hard rule: **the `src/Core` engine talks to PostgreSQL only through the `Core\Database\Connection` port and never imports Symfony or Doctrine ORM.** The DBAL and PDO adapters live in `src/Dbal` / `src/Pdo`; the ORM read layer is isolated in `src/DoctrineOrm` (loaded only when `doctrine/orm` is present); Symfony integration is a separate package (`th3mouk/materialized-view-bundle`). The only DBAL names remaining in `Core` are the `MaterializedViewManager::forConnection()` BC convenience and the `RefreshTargetResolver` async-refresh contract — neither is loaded on the PDO path.

## Class map (folder → planned classes)

> The folders exist with `.gitkeep`; the classes below are the planned implementation surface (from the specification). Keep this table authoritative as code lands.

| Folder | Classes | Responsibility |
|---|---|---|
| `Core/Definition` | `MaterializedViewDefinition`, `MaterializedViewName`, `SqlSource` (iface), `SqlFileSource`, `InlineSqlSource`, `MaterializedViewIndex`, `PopulationPolicy` (enum), `RebuildStrategy` (enum), `RefreshOptions` | Immutable declaration of a view |
| `Core/Registry` | `MaterializedViewRegistry` | Typed collection of declared definitions |
| `Core/Database` | `Connection` (iface), `ParameterType` (enum), `DatabaseException` | The execution port the engine runs on; backend-agnostic binding types and failures (carries SQLSTATE) |
| `Core/Sql` | `PostgreSqlMaterializedViewSqlGenerator`, `IdentifierQuoter` | Generates `CREATE`/`DROP`/`REFRESH`/`CREATE INDEX`/`COMMENT`/`ALTER … RENAME`; PostgreSQL-native quoting (no DBAL platform) |
| `Core/Introspection` | `PostgreSqlMaterializedViewIntrospector`, `ReadinessChecker` | Reads `pg_matviews`/`pg_class`/`pg_namespace`/`pg_indexes`, comments, `relispopulated` |
| `Core/Dependency` | `CatalogDependencyResolver`, `ExternalDependencyGuard` | Derives the matview graph from `pg_depend`/`pg_rewrite`; blocks unsafe drops |
| `Core/Rebuild` | `SideBySideRebuilder`, `IndexSnapshotter` | Rebuild strategies; index capture/replay |
| `Core/Privilege` | `PrivilegeSnapshotter`, `PrivilegeReplayer` | GRANT capture/replay across rebuilds |
| `Core/Refresh` | `RefreshOptions`, `AsyncRefreshRequest`, `RefreshTargetResolver` (iface) | Refresh request model; refresh target resolution contract (enumerates the databases/connections to refresh) |
| `Core/Lock` | `LaneLock`, `ViewRefreshLock`, `StableLockKeyGenerator`, `PrimaryConnectionGuard` | Advisory locks; PHP-side stable keys; primary/replica routing |
| `Core/Hashing` | `DefinitionHasher` | Canonical hash stored in the COMMENT |
| `Core/Sync` | `MaterializedViewSynchronizer`, `MaterializedViewComparator` | The declarative reconcile engine |
| `Core/Migration` | `MaterializedViewMigrationSql` | Static helper for migration-owned mode |
| `Core/Exception` | domain exceptions | Named, business-meaningful failures |
| `Core` (root) | `MaterializedViewManager` | Runtime façade: `create()/drop()/refresh()/sync()/refreshAll()`; factories `forDriver()` (port), `forConnection()` (DBAL), `forPdo()` (PDO) |
| `Dbal` | `DbalConnection` | `Connection` adapter over Doctrine DBAL — primary/replica routing, middlewares, profiling |
| `Pdo` | `PdoConnection` | `Connection` adapter over a bare `PDO` handle (`pdo_pgsql`) |
| `DoctrineOrm/Mapping` | `#[MaterializedViewEntity]`, `MaterializedViewMetadataReader` | Link entity ↔ definition |
| `DoctrineOrm/Listener` | `MaterializedViewWriteGuard`, `MaterializedViewPostLoadListener` | Enforce read-only |
| `DoctrineOrm/Repository` | `MaterializedViewRepository` | Read-only base repository |
| `DoctrineOrm/Readiness` | ORM-facing readiness guard | Protect reads on unpopulated views |

## Construction (framework-agnostic)

The manager runs on any `Core\Database\Connection`. Two adapters ship with the package; pick a backend:

```php
use Doctrine\DBAL\DriverManager;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;

// Doctrine DBAL — keeps primary/replica routing, middlewares, profiling:
$connection = DriverManager::getConnection(['url' => $databaseUrl]);
$manager    = MaterializedViewManager::forConnection($connection /*, LoggerInterface $logger = null */);

// …or a bare PDO handle (pdo_pgsql), no Doctrine:
$manager = MaterializedViewManager::forPdo(new PDO($dsn, $user, $password));

// …or any custom Connection adapter:
$manager = MaterializedViewManager::forDriver($connection);
```

`forConnection()` and `forPdo()` are thin wrappers over `forDriver()` that build a `Dbal\DbalConnection` / `Pdo\PdoConnection`. See [Connection backends](../guide/connection-backends.md). The bundle wires this as a service per connection.

## Data flow — `sync`

```text
registry ─▶ MaterializedViewComparator ─▶ plan
                                           │
   introspect (pg_catalog) ───────────────┘
                                           ▼
   for each create/rebuild (dependency-ordered, ExternalDependencyGuard checked):
     PrivilegeSnapshotter → SqlGenerator(DROP/CREATE/INDEX) → PrivilegeReplayer
                                           ▼
     DefinitionHasher → COMMENT          ▼
                                  PopulationPolicy → (Manual | Async dispatch | Synchronous REFRESH)
                                           ▼
                                        ANALYZE (if populated)
```

## Data flow — `refresh`

```text
PrimaryConnectionGuard ─▶ set lock_timeout/statement_timeout ─▶ ViewRefreshLock (advisory)
   ─▶ validate CONCURRENTLY preconditions ─▶ REFRESH ─▶ ANALYZE ─▶ record metrics
```

## Boundaries with the bundle

The bundle (`th3mouk/materialized-view-bundle`) owns: service wiring, `#[AsMaterializedViewProvider]` discovery, console commands, the `doctrine-lane` (advisory lock + `MigrateCommand`), Messenger dispatch of `AsyncRefreshRequest`, and the injectable readiness guard. It depends on this package; never the reverse.
