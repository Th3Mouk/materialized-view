# Getting started

This page gets a materialized view managed by the library in a few minutes, **without any framework**. On Symfony, you will usually prefer the [bundle](../../materialized-view-bundle) — it adds autoconfiguration, console commands and the locked deploy lane — but everything below still applies underneath.

## 1. Install

```bash
composer require th3mouk/materialized-view
```

Requirements: **PHP ≥ 8.4**, **Doctrine DBAL ≥ 4.4**, **PostgreSQL** (12+, tested to 17).

## 2. Write the view's SQL

Keep the query in a real `.sql` file — it gets syntax highlighting, is runnable in `psql`, and produces readable Git diffs.

Assume a base table `orders (id bigint, category text, amount numeric, created_at timestamptz)`.

`db/matviews/sales_by_category.sql`

```sql
SELECT
    category,
    count(*) AS order_count,
    sum(amount) AS total_amount
FROM orders
GROUP BY category
```

## 3. Declare the definition

The PHP class names the view, points at the SQL file, and declares its indexes, rebuild and population policies. It does **not** embed the query.

```php
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;

final class SalesByCategoryView
{
    public static function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.sales_by_category')
            ->fromSql(SqlFileSource::fromProjectPath('db/matviews/sales_by_category.sql'))
            ->withNoData()
            ->withRebuildStrategy(RebuildStrategy::DropCreate)
            ->withPopulationPolicy(PopulationPolicy::Manual)
            ->withIndex(MaterializedViewIndex::unique(
                name: 'ux_sales_by_category_category',
                columns: ['category'],
            ))
            ->withIndex(MaterializedViewIndex::regular(
                name: 'idx_sales_by_category_total_amount',
                columns: ['total_amount'],
            ));
    }
}
```

> The **unique index** is not optional if you ever want `REFRESH … CONCURRENTLY`: PostgreSQL requires one. See [Refresh runtime & locking](guide/refresh-and-locking.md).

## 4. Synchronise and refresh

```php
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

$registry = MaterializedViewRegistry::fromDefinitions([
    SalesByCategoryView::definition(),
]);

// Create missing views, rebuild drifted ones, (re-)create indexes, re-apply GRANTs,
// store the canonical hash in the view's COMMENT, then apply the population policy.
$manager->syncAll($registry);

// Later, refresh data (CONCURRENTLY when the view is populated and has a unique index).
$manager->refresh(SalesByCategoryView::definition());
```

`$manager` is a `Th3Mouk\MaterializedView\Core\MaterializedViewManager` built from your DBAL `Connection`. The bundle builds and wires it for you; framework-agnostic construction is shown in [Architecture](internals/architecture.md).

## 5. Read it

A materialized view is just a relation: query it with DBAL, or map a **read-only** Doctrine entity onto it — see [Doctrine ORM integration](guide/doctrine-orm-integration.md).

> ⚠️ A view created `WITH NO DATA` raises a hard error if you read it before its first refresh. Pick a [population policy](guide/population-and-readiness.md) that matches how the view is consumed.

## Where next

- The deploy story (drop → migrate → sync, across multiple databases): the [bundle getting started](../../materialized-view-bundle/docs/getting-started.md).
- Every advanced concept: the [guide](guide/).
- Why it is built this way: the [internals](internals/).
