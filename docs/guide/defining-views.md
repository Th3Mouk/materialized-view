# Defining views

A managed materialized view is the pair **{ versioned `.sql` file, PHP definition }**. The SQL file is the source of truth for the query body; the PHP definition declares everything else.

## The definition model

`MaterializedViewDefinition` is an immutable value object built with a fluent factory:

```php
MaterializedViewDefinition::create('public.sales_by_category')
    ->fromSql(SqlFileSource::fromProjectPath('db/matviews/sales_by_category.sql'))
    ->withNoData()                                   // create WITH NO DATA (fast); populate later
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
```

| Concern | Type | Notes |
|---|---|---|
| Qualified name | `MaterializedViewName` | `schema.name`, strictly validated and quoted via DBAL — never string-interpolated |
| SQL source | `SqlSource` | `SqlFileSource` (recommended) or `InlineSqlSource` (tests/small examples only) |
| Indexes | `MaterializedViewIndex[]` | `unique()` / `regular()`; supports method, include, where, concurrently |
| Storage | options | `WITH DATA` / `WITH NO DATA`, tablespace where supported |
| Rebuild strategy | `RebuildStrategy` | `DropCreate` (default) or `SideBySide` — see [Rebuild strategies](rebuild-strategies.md) |
| Population policy | `PopulationPolicy` | how/when the first refresh happens — see [Population & read safety](population-and-readiness.md) |

Identifiers are always rendered through `IdentifierQuoter`. **Never** build DDL by concatenating user-provided strings.

## Why a `.sql` file rather than a PHP heredoc

A real `.sql` file gives you: editor syntax highlighting, direct execution in `psql`/any SQL client, readable Git diffs, easier review, scaffold generation, and better team adoption. The PHP class stays small — it names the view and declares indexes, options and strategy.

## Generated SQL

**Creation** (`WITH NO DATA` keeps boot fast; data comes from the first refresh):

```sql
CREATE MATERIALIZED VIEW public.sales_by_category AS
SELECT
    category,
    count(*) AS order_count,
    sum(amount) AS total_amount
FROM orders
GROUP BY category
WITH NO DATA;

CREATE UNIQUE INDEX ux_sales_by_category_category
ON public.sales_by_category (category);

CREATE INDEX idx_sales_by_category_total_amount
ON public.sales_by_category (total_amount);

COMMENT ON MATERIALIZED VIEW public.sales_by_category
IS '{"th3mouk_materialized_view":{"hash":"...","version":1}}';
```

**First population** (non-concurrent — concurrent refresh is impossible on an unpopulated view):

```sql
REFRESH MATERIALIZED VIEW public.sales_by_category WITH DATA;
ANALYZE public.sales_by_category;
```

**Later refreshes** (concurrent — note `WITH DATA` is the default and is omitted):

```sql
REFRESH MATERIALIZED VIEW CONCURRENTLY public.sales_by_category;
ANALYZE public.sales_by_category;
```

## File naming & versioning

`matview:generate` (bundle) creates the SQL file and a skeleton definition class. Two naming strategies are supported:

| Strategy | Example | When |
|---|---|---|
| **Stable name** (recommended default) | `db/matviews/sales_by_category.sql` | The real version is carried by the hash in the COMMENT |
| **Scenic-style versioned** | `db/matviews/sales_by_category_v001.sql` | Only if the team wants every past definition kept on disk |

If you enable the versioned strategy, the generator must provide a **bump workflow**: create `_v002.sql`, copy the previous SQL, and update the definition class to point at the new file. Otherwise old version files become dead history the library never consumes.

## Registry

A `MaterializedViewRegistry` is just the typed set of declared definitions:

```php
$registry = MaterializedViewRegistry::fromDefinitions([
    SalesByCategoryView::definition(),
    // ...
]);
```

The bundle builds the registry automatically from `#[AsMaterializedViewProvider]` classes.
