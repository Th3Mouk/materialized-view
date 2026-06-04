# th3mouk/materialized-view

> Declarative, PostgreSQL-native management of **materialized views** on top of Doctrine DBAL.

Define a materialized view once, as a **versioned `.sql` file plus a small PHP definition**, and let the library create it, detect drift, rebuild it safely, refresh it (including `CONCURRENTLY`), and let your app read it without surprises. No ORM owns the DDL; PostgreSQL stays the source of truth for the physical object.

This is the **framework-agnostic core** (depends only on `doctrine/dbal`). For Symfony — autoconfiguration, console commands, the locked deploy lane and async refresh — use [`th3mouk/materialized-view-bundle`](../materialized-view-bundle).

## Why this library exists

A materialized view is a **physical PostgreSQL object with its own lifecycle**: `CREATE MATERIALIZED VIEW … AS …`, `REFRESH MATERIALIZED VIEW [CONCURRENTLY]`, no `CREATE OR REPLACE`, destructive redefinition, unique-index preconditions for concurrent refresh, dependency ordering. Doctrine ORM can *read* the rows as a read-only projection, but it cannot *express* any of that — and at the time of writing **no maintained, framework-agnostic PHP library** manages PostgreSQL materialized views. This library fills that gap, DBAL-first.

See [`docs/internals/design-rationale.md`](docs/internals/design-rationale.md) for the full reasoning and the competitive landscape.

## Highlights

- **Declarative**: SQL lives in `db/matviews/*.sql`; a tiny PHP class declares name, indexes, rebuild & population policy.
- **Drift detection** via a canonical hash stored in `COMMENT ON MATERIALIZED VIEW` (travels with database clones).
- **Safe rebuilds**: `drop_create` and a low-lock `side_by_side` strategy, with **index and GRANT re-application**.
- **Refresh runtime**: `CONCURRENTLY` with precondition validation, primary/replica awareness, `lock_timeout`/`statement_timeout`, advisory locks, `ANALYZE`.
- **Catalog-derived dependency ordering** (`pg_depend`/`pg_rewrite`) — no hand-maintained graph, no drift.
- **Read-only ORM mapping** (optional) with a write guard and an unpopulated-read readiness guard.

## Installation

```bash
composer require th3mouk/materialized-view
```

Requirements: **PHP ≥ 8.4**, **Doctrine DBAL ≥ 4.4**, **PostgreSQL** (12+; tested against 17). The optional ORM layer requires `doctrine/orm` ≥ 3.6.

## 60-second example (framework-agnostic)

Given a base table `orders (id bigint, category text, amount numeric, created_at timestamptz)`:

`db/matviews/sales_by_category.sql`

```sql
SELECT category, count(*) AS order_count, sum(amount) AS total_amount
FROM orders
GROUP BY category
```

```php
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

$definition = MaterializedViewDefinition::create('public.sales_by_category')
    ->fromSql(SqlFileSource::fromProjectPath('db/matviews/sales_by_category.sql'))
    ->withIndex(MaterializedViewIndex::unique(
        name: 'ux_sales_by_category_category',
        columns: ['category'],
    ));

$registry = MaterializedViewRegistry::fromDefinitions([$definition]);

$manager->syncAll($registry);          // create / rebuild on drift
$manager->refresh($definition);        // REFRESH MATERIALIZED VIEW (CONCURRENTLY when possible)
```

## Documentation

| Tier | Audience | Start here |
|---|---|---|
| **Getting started** | Users — set it up fast | [`docs/getting-started.md`](docs/getting-started.md) |
| **Guide** | Users — advanced concepts | [`docs/guide/`](docs/guide/) |
| **Internals** | Maintainers — design & upstream references | [`docs/internals/`](docs/internals/) |

A full table of contents is in [`docs/README.md`](docs/README.md).

## Compatibility

| This library | PHP | Doctrine DBAL | Doctrine ORM (optional) | PostgreSQL |
|---|---|---|---|---|
| `^0.1` | ≥ 8.4 | ^4.4 | ^3.6 | 12 – 17 |

We track DBAL major versions deliberately and **do not pin a tight upper bound that would strand the library** (see [`docs/internals/compatibility-and-evolution.md`](docs/internals/compatibility-and-evolution.md)).

## License

[Apache-2.0](LICENSE) — Copyright © 2026 Jérémy Marodon (th3mouk). See [`NOTICE`](NOTICE).

If you use or redistribute this package, keep the [`NOTICE`](NOTICE) attribution —
crediting **Jérémy Marodon (th3mouk)** and naming this library in your product's
documentation or credits. Please [contribute](CONTRIBUTING.md) upstream rather than
maintaining a public fork.
