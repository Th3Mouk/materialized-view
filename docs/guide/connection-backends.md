# Connection backends

The library manages PostgreSQL through a tiny **connection port**, not through a specific database library. `Core` depends only on `php` and `psr/log`; you choose the backend. **Doctrine is optional** — and natively supported.

## The port

Everything the engine needs from a connection is declared by one interface:

```php
namespace Th3Mouk\MaterializedView\Core\Database;

interface Connection
{
    public function executeStatement(string $sql, array $params = [], array $types = []): int;
    public function fetchOne(string $sql, array $params = [], array $types = []): mixed;
    public function fetchAllAssociative(string $sql, array $params = [], array $types = []): array;
    public function fetchAssociative(string $sql, array $params = [], array $types = []): array|false;
    public function transactional(\Closure $operation): mixed;
    public function ensureConnectedToPrimary(): void;
}
```

`$types` entries are `Th3Mouk\MaterializedView\Core\Database\ParameterType` cases (`Integer`, `String`, `Boolean`); each adapter maps them to its native binding types. Any failure is surfaced as `Th3Mouk\MaterializedView\Core\Database\DatabaseException`, which carries the five-character **SQLSTATE** — that is how missing-dependency handling (`42P01` / `3F000`) stays backend-independent.

Two adapters ship with the package.

## Doctrine DBAL (recommended)

```php
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;

$manager = MaterializedViewManager::forConnection($dbalConnection);
```

`forConnection()` wraps the Doctrine connection in `Th3Mouk\MaterializedView\Dbal\DbalConnection`. This is the recommended backend because the DBAL connection brings real operational value the engine reuses:

- **Primary/replica routing** — `ensureConnectedToPrimary()` switches a `PrimaryReadReplicaConnection` to the primary before any DDL or refresh, so writes never hit a replica.
- **Middlewares & profiling** — your DBAL stack (logging middleware, the Symfony profiler, Datadog/OpenTelemetry tracing, …) sees every statement the library runs.
- **The read-only ORM mapping** — map a materialized view as a read-only Doctrine entity, guarded against writes and unpopulated reads. See [Doctrine ORM integration](doctrine-orm-integration.md).
- **The Symfony bundle** — autoconfiguration, console commands, the locked deploy lane and async refresh, via [`th3mouk/materialized-view-bundle`](../../../materialized-view-bundle).

Install it explicitly (it is a `suggest`, not a hard dependency):

```bash
composer require doctrine/dbal       # and doctrine/orm for the read layer
```

## Bare PDO (no Doctrine)

For a project that does not run Doctrine, hand the manager a `PDO` connected to PostgreSQL:

```php
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;

$pdo = new PDO('pgsql:host=localhost;dbname=app', $user, $password);

$manager = MaterializedViewManager::forPdo($pdo);
```

`forPdo()` wraps the handle in `Th3Mouk\MaterializedView\Pdo\PdoConnection`. It requires only the **`pdo_pgsql`** extension — no extra Composer dependency. The adapter forces `PDO::ERRMODE_EXCEPTION` and translates `PDOException` to `DatabaseException`. There is no primary/replica routing or middleware layer, so `ensureConnectedToPrimary()` is a no-op; everything else — create, sync, drift detection, rebuilds, `REFRESH … CONCURRENTLY`, advisory locks, `ANALYZE` — behaves identically.

## Your own backend

`forDriver()` accepts any `Connection` implementation, so you can wrap a connection pool, a test double, or another database library:

```php
$manager = MaterializedViewManager::forDriver($myConnection);
```

`forConnection()` and `forPdo()` are thin conveniences over `forDriver()`.

## Choosing

| | Doctrine DBAL (`forConnection`) | Bare PDO (`forPdo`) |
|---|---|---|
| Composer dependency | `doctrine/dbal` | none (`ext-pdo_pgsql`) |
| Primary/replica routing | ✅ | — (no-op) |
| Middlewares / profiling | ✅ | — |
| Read-only ORM mapping | ✅ (`doctrine/orm`) | — |
| Symfony bundle | ✅ | — |
| Core management features | ✅ | ✅ |

Use Doctrine DBAL if your application already has it (most do) — it is the richer, natively-supported path. Reach for PDO when you want the materialized-view engine without pulling Doctrine into the project.
