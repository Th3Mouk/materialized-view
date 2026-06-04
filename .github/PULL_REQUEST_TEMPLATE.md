<!-- Thanks for contributing to th3mouk/materialized-view! -->

## What & why

<!-- What does this change and why? Link any issue. -->

## Checklist

- [ ] `composer cs:fix && composer rector && composer stan` pass
- [ ] `composer test:unit` passes; `composer test:pg` passes against PostgreSQL
- [ ] New PostgreSQL/Doctrine behaviour is referenced in `docs/internals/*-references.md`
- [ ] `src/Core` introduces **no** Symfony / Doctrine ORM dependency
- [ ] `CHANGELOG.md` updated under `[Unreleased]`
