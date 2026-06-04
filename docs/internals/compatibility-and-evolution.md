# Compatibility & evolution (maintainers)

A view library's value compounds over years. The libraries it replaces died mainly from **version lock** and **bus-factor-1 abandonment**. This page is the standing policy to avoid both, and the checklist to re-adapt when PostgreSQL, Doctrine or Symfony evolve.

## Version policy

- **Never pin a tight upper bound** on `doctrine/dbal`. Track new majors quickly and widen constraints promptly. (The cautionary tale: `kenny1911/doctrine-views-sync` stranded itself at `doctrine/dbal <4.3`.)
- **SemVer.** Pre-1.0, minor versions may break. After 1.0, breaking changes only on majors.
- **CI matrix is the contract.** The PostgreSQL matrix (14–17) and the PHP/DBAL versions in CI define what "supported" means. Add a new PostgreSQL major to the matrix *before* claiming support.
- **Optional dependencies stay optional.** `doctrine/orm` (read layer) and Symfony (bundle) must never leak into `src/Core`.

## What to watch upstream, and why

| Upstream | What could change | Where it would hit us | How to re-adapt |
|---|---|---|---|
| **PostgreSQL** | `relkind` values / catalog shape; `pg_depend`/`pg_rewrite` semantics | `Introspection`, `Dependency` | Re-validate the introspection queries; integration suite on the new major |
| **PostgreSQL** | `REFRESH … CONCURRENTLY` preconditions; new refresh modes | `Refresh`, precondition validation | Re-read the REFRESH page; adjust validation |
| **PostgreSQL** | advisory-lock semantics / scoping | `Lock` | Re-confirm per-database scoping in `pg_locks` docs |
| **PostgreSQL** | `CREATE DATABASE … TEMPLATE` copy semantics | `template.policy` (bundle) | Re-check what is/ isn't copied |
| **PostgreSQL** | internal functions (`hashtext`) | nothing (we forbid them) | Keep the ban; keys are computed in PHP |
| **Doctrine DBAL** | `Schema`/`Comparator` gains materialized-view modelling | "matviews invisible to diff" assumption | If DBAL ever models matviews, reconcile with our COMMENT-hash drift detection |
| **Doctrine DBAL** | `introspectViews()` / schema-manager API | `Introspection` | Re-verify symbols; prefer native introspection where it covers matviews |
| **Doctrine ORM** | `readOnly` attribute / `UnitOfWork::markReadOnly` | `DoctrineOrm` | Re-verify symbols on bump |
| **Doctrine Migrations** | `DependencyFactory` / `Migrator` API; service id | bundle `doctrine-lane` | Re-verify `doctrine.migrations.dependency_factory` and the migrator signature |
| **Symfony** | `AbstractBundle`/autoconfiguration; Messenger/Scheduler | bundle | Re-verify in the bundle's references page |

## Re-verification ritual (per dependency bump)

1. Bump the constraint; run the full CI matrix.
2. Re-read the relevant pages in [PostgreSQL references](postgresql-references.md) and [Doctrine references](doctrine-references.md); fix any drifted statement.
3. Re-run the [validation plan](validation-plan.md) integration scenarios on the new version.
4. Record the verified symbol/page in the references file (so the next maintainer trusts it).

## Anti-abandonment

- Keep `src/Core` free of framework coupling so the library survives framework churn.
- Broad CI matrix; clear `CONTRIBUTING.md`; a stable public surface.
- Each non-obvious behaviour is anchored to an upstream link — knowledge lives in the repo, not in one maintainer's head.
