# Dependencies & refresh order

When materialized views build on each other, both **refresh** and **rebuild** must happen in dependency order. The library derives that order **from the catalog**, not from a hand-maintained list — a declared list drifts; the catalog cannot.

## Deriving the graph

- Identify managed matviews: `pg_class.relkind = 'm'` **and** the management COMMENT.
- Read rewrite dependencies via `pg_depend` / `pg_rewrite`, **with the mandatory filters** (see below).
- Traverse intermediate **plain views** (`relkind = 'v'`) so a `matview → view → matview` chain is not missed.
- Build the final order over the managed matviews only.
- Ignore dependencies on tables for refresh ordering, but surface them in `matview:validate`.

### The filters are not cosmetic

The dependency query **must** filter, or it will report false self-edges and break the topological sort:

```sql
WHERE dep.classid    = 'pg_rewrite'::regclass
  AND dep.refclassid = 'pg_class'::regclass
  AND dep.deptype    = 'n'                       -- normal deps only
  AND dependent_class.oid <> referenced_class.oid -- exclude self-reference
```

Without `deptype = 'n'`, a view's `_RETURN` rewrite rule reports a dependency of the view on **itself**, creating a self-edge that the topological sort sees as a false cycle. The full query is in [PostgreSQL references](../internals/postgresql-references.md).

## Using the graph for rebuilds

The same graph drives rebuilds: if a matview must be rebuilt, all of its **managed** dependents must be rebuilt too — dropped in reverse dependency order, then created/refreshed in topological order.

A targeted rebuild **refuses** if it detects **unmanaged** dependents it cannot recreate. An unmanaged dependent is, notably:

- a plain view created outside the registry;
- a matview with no management COMMENT, or absent from the current registry;
- a BI/reporting object created by another team;
- any PostgreSQL dependency the library does not know how to recreate.

`matview:validate` lists these dependents and tells you which commands (`sync`, `drop --if-pending`, `prune`) they would block.

> **Scope of the conflict-avoidance guarantee:** automatic, no-guess behaviour holds for views whose dependent closure is **entirely managed**. An unmanaged dependent makes the guard refuse the drop (correct — it never `CASCADE`s) which can re-surface a manual step. Fail loud beats destroy silently.

## Manual `dependsOn()`

`dependsOn()` remains available for what the catalog cannot describe:

- dependencies outside PostgreSQL;
- a specific business ordering;
- a future extension toward external refresh jobs.

It must **not** be the primary mechanism for ordering refreshes between materialized views — the catalog already knows those.
