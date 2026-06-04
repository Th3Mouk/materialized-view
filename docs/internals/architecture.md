# Architecture (maintainers)

This page maps the design to the source tree and shows the main data flows. It is the entry point for anyone implementing or modifying the library.

## Layering

```text
th3mouk/materialized-view
├── src/Core         # framework-free; depends only on doctrine/dbal
└── src/DoctrineOrm  # optional; depends on doctrine/orm (read-only mapping)
```

Hard rule: **`src/Core` must never import Symfony or Doctrine ORM.** Symfony integration is a separate package (`th3mouk/materialized-view-bundle`). The ORM read layer is isolated in `src/DoctrineOrm` and only loaded when `doctrine/orm` is present.

## Class map (folder → planned classes)

> The folders exist with `.gitkeep`; the classes below are the planned implementation surface (from the specification). Keep this table authoritative as code lands.

| Folder | Classes | Responsibility |
|---|---|---|
| `Core/Definition` | `MaterializedViewDefinition`, `MaterializedViewName`, `SqlSource` (iface), `SqlFileSource`, `InlineSqlSource`, `MaterializedViewIndex`, `PopulationPolicy` (enum), `RebuildStrategy` (enum), `RefreshOptions` | Immutable declaration of a view |
| `Core/Registry` | `MaterializedViewRegistry` | Typed collection of declared definitions |
| `Core/Sql` | `PostgreSqlMaterializedViewSqlGenerator`, `IdentifierQuoter` | Generates `CREATE`/`DROP`/`REFRESH`/`CREATE INDEX`/`COMMENT`/`ALTER … RENAME`; structured quoting |
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
| `Core` (root) | `MaterializedViewManager` | Runtime façade: `create()/drop()/refresh()/sync()/refreshAll()` |
| `DoctrineOrm/Mapping` | `#[MaterializedViewEntity]`, `MaterializedViewMetadataReader` | Link entity ↔ definition |
| `DoctrineOrm/Listener` | `MaterializedViewWriteGuard`, `MaterializedViewPostLoadListener` | Enforce read-only |
| `DoctrineOrm/Repository` | `MaterializedViewRepository` | Read-only base repository |
| `DoctrineOrm/Readiness` | ORM-facing readiness guard | Protect reads on unpopulated views |

## Construction (framework-agnostic)

```php
use Doctrine\DBAL\DriverManager;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;

$connection = DriverManager::getConnection(['url' => $databaseUrl]);
$manager    = MaterializedViewManager::forConnection($connection /*, LoggerInterface $logger = null */);
```

The bundle wires this as a service per connection.

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
