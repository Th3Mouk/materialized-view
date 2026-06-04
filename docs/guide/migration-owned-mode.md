# Migration-owned mode (optional)

The default mode is **declarative**: views are owned by `sync`, not by hand-written migrations (this is what makes the boot lane safe when the same managed views run across many databases/connections). For projects that prefer to own materialized views inside Doctrine Migrations — typically isolated, single-database cases — a static helper is provided.

```php
use App\Analytics\View\SalesByCategoryView;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Th3Mouk\MaterializedView\Core\Migration\MaterializedViewMigrationSql;

final class Version20260604120000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !($platform instanceof PostgreSQLPlatform),
            'Materialized views are PostgreSQL-specific.',
        );

        foreach (MaterializedViewMigrationSql::create(SalesByCategoryView::definition()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (MaterializedViewMigrationSql::drop(SalesByCategoryView::definition()) as $sql) {
            $this->addSql($sql);
        }
    }
}
```

If a migration contains `CREATE INDEX CONCURRENTLY`, it must be non-transactional:

```php
public function isTransactional(): bool
{
    return false;
}
```

## Why this is secondary

The migration-owned mode is traceable, but it does **not** automatically resolve table-DDL ↔ materialized-view dependencies: when a later migration alters a source column, you must remember to drop the dependent view first. Across a fleet of many databases with frequent schema changes that re-introduces exactly the "guess the conflict" problem the declarative mode removes. Migrations keep ownership of **tables**; `sync` owns the **views**.

See the [bundle boot lane](../../../materialized-view-bundle/docs/guide/boot-lane.md) for the declarative deploy story.
