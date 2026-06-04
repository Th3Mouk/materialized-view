# Contributing

Thanks for helping improve `th3mouk/materialized-view`.

## Ground rules

- **PostgreSQL is the source of truth** for the physical object. The library orchestrates DDL/DML and introspection; it never tries to make the ORM own a materialized view's definition.
- **DBAL-first, framework-free core.** Nothing under `src/Core` may depend on Symfony or Doctrine ORM. ORM glue lives in `src/DoctrineOrm` and is optional.
- **No tight upper version bounds** on `doctrine/dbal`. Track new majors quickly; a view library's value compounds over years (see `docs/internals/compatibility-and-evolution.md`).
- Every behaviour that touches `pg_catalog`, refresh semantics, locking or cloning **must cite the upstream documentation** it relies on, in `docs/internals/*-references.md`.

## Quality gate

Run before every push:

```bash
composer cs:fix      # friendsofphp/php-cs-fixer
composer rector      # rector/rector
composer stan        # phpstan, level 8
composer test:unit   # phpunit, no database
composer test:pg     # phpunit, requires a PostgreSQL instance
```

## Test taxonomy

| Suite | Location | Needs PostgreSQL | Purpose |
|---|---|---|---|
| Unit | `tests/Unit` | no | SQL generation snapshots, identifier quoting, canonical hashing, comparator, lock-key generation |
| Integration | `tests/Integration` | yes | real `CREATE`/`REFRESH`/`DROP`, `relispopulated`, `pg_depend` ordering, GRANT snapshot/replay, rebuild strategies |

The authoritative validation matrix (every scenario the suites must cover) lives in
[`docs/internals/validation-plan.md`](docs/internals/validation-plan.md).

## Commits & releases

- [Conventional Commits](https://www.conventionalcommits.org/).
- [Semantic Versioning](https://semver.org/). Pre-1.0, minor versions may break.
- Update `CHANGELOG.md` under `[Unreleased]`.

## License & attribution

Licensed under [Apache-2.0](LICENSE). By submitting a contribution you agree it is
provided under those same terms (Apache-2.0 §5) — no separate CLA is required.

Please **contribute upstream rather than maintaining a public fork**: open an issue
or a pull request here. The [`NOTICE`](NOTICE) attribution to Jérémy Marodon (th3mouk)
and the copyright/trademark notices must be preserved in any redistribution or
derivative work (Apache-2.0 §4 and §6).
