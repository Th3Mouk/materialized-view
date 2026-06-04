# Drift detection & hashing

The library decides whether a view must be rebuilt by comparing a **canonical hash** of its declared definition against the hash stored on the live object. Comparing raw SQL would be fragile — PostgreSQL normalises stored view definitions (whitespace, casing, schema-qualification) — so we never diff `pg_get_viewdef()` output.

## What the hash covers

- canonical SQL content;
- the qualified view name;
- the rebuild strategy;
- the declared index list;
- the population policy;
- significant options (`WITH DATA` / `WITH NO DATA`, tablespace where supported).

## Canonicalisation (must be frozen at MVP)

Because the hash drives potentially expensive fleet-wide rebuilds, the canonicalisation must be **stable and deterministic** so that a mere reformat does not trigger a rebuild everywhere. Minimum acceptable:

- `trim`;
- normalise line endings;
- strip non-significant SQL comments;
- collapse consecutive whitespace **outside string literals**;
- drop the trailing `;`;
- the hash is **distinct from the file path** (the path stays informative metadata only).

A real PostgreSQL SQL parser would be better for *semantic* comparison, but the MVP must at least prevent reformatting or comment changes from triggering rebuilds.

## Where the hash lives

In the view's COMMENT, so it **travels with database clones**:

```sql
COMMENT ON MATERIALIZED VIEW public.sales_by_category
IS '{"th3mouk_materialized_view":{"hash":"...","version":1,"source":"db/matviews/sales_by_category.sql"}}';
```

An optional metadata table may complement this for refresh observability.

## The COMMENT is not the only identity

If `CREATE MATERIALIZED VIEW` succeeds but `COMMENT` fails, the view exists without the management marker. Therefore:

- During `sync`, the **name present in the current registry is also authoritative**: a managed-but-uncommented view is repaired (the COMMENT is re-applied) rather than re-created (which would fail on *already exists*).
- When possible, `CREATE MATERIALIZED VIEW` + non-concurrent index creation + `COMMENT` run in **one transaction**.
- Non-transactional paths (`CREATE INDEX CONCURRENTLY`, some rebuild paths) must have an **idempotent resume** that recognises a declared-but-uncommented view.
