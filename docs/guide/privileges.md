# Privileges (GRANTs)

`DROP MATERIALIZED VIEW` removes the object's privileges. After a rebuild the new object has only default privileges — so any role that was granted access (an application role, a BI/reporting role, a read-only role used by a dashboard tool) **loses it** unless the library re-applies it.

A reusable library cannot assume the infrastructure re-grants privileges right after a rebuild. The synchronizer therefore supports at least one of:

- **Snapshot & replay** — capture existing GRANTs before the drop (`PrivilegeSnapshotter`) and replay them after create/swap (`PrivilegeReplayer`).
- **Declared GRANTs** — privileges declared on the `MaterializedViewDefinition`, used for brand-new views that have nothing to snapshot.
- **Explicit trust in `ALTER DEFAULT PRIVILEGES`** — enabled by configuration when the platform sets default privileges centrally.

## MVP recommendation

- `preserve_existing_grants: true` by default **when the view already exists** (snapshot + replay).
- Declared GRANTs for new views.
- On `prune` (dropping a view that left the registry), GRANTs may be ignored — `prune` is explicitly destructive.

## Object privileges vs database privileges (clones)

This distinction matters when databases are created by cloning a template (`CREATE DATABASE … TEMPLATE …`):

- **Object-level GRANTs** on a materialized view **are** copied into the clone (they live in the catalog rows that are physically cloned).
- **Database-level GRANTs** (privileges on the database object itself) are **not** copied — they must be applied by provisioning, per database.

So a rebuild must restore object GRANTs, while database-level access remains the provisioning layer's responsibility. See [PostgreSQL references](../internals/postgresql-references.md) and the [bundle templates page](../../../materialized-view-bundle/docs/guide/templates-and-cloning.md).
