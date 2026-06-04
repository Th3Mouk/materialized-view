# Doctrine ORM integration

The ORM is for **reading** the projection, not for creating or owning it. This layer lives in `src/DoctrineOrm` and is optional (it requires `doctrine/orm`).

## Read-only entity mapping

```php
use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewEntity;

#[ORM\Entity(readOnly: true, repositoryClass: SalesByCategoryRepository::class)]
#[ORM\Table(name: 'sales_by_category')]
#[MaterializedViewEntity(definition: SalesByCategoryView::class)]
final class SalesByCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    public string $category;

    #[ORM\Column(type: 'integer')]
    public int $orderCount;

    #[ORM\Column(type: 'string')]
    public string $totalAmount;
}
```

`#[ORM\Entity(readOnly: true)]` reduces the risk of accidental writes, but is not sufficient on its own — hence the write guard.

## Classes

| Class | Role |
|---|---|
| `#[MaterializedViewEntity]` | Links the entity to its `MaterializedViewDefinition`. |
| `MaterializedViewMetadataReader` | Reads the attributes and validates the mapping. |
| `MaterializedViewWriteGuard` | `onFlush` listener that fails loudly on insert/update/delete of a matview entity, with a clear message. |
| `MaterializedViewPostLoadListener` | Optional: calls `UnitOfWork::markReadOnly()` after load. |
| `MaterializedViewRepository` | Base read-only repository. |

## Reading a not-yet-populated view

A read-only entity mapped onto a view created `WITH NO DATA` will **error** until the first refresh. Pair the mapping with a read strategy from [Population & read safety](population-and-readiness.md): a guaranteed `Synchronous` population for critical views, a `ReadinessChecker` guard, an explicit exception, or a business fallback. `matview:validate` flags entities mapped onto unpopulated managed views.

## Why the ORM does not own the DDL

A materialized view is a physical projection with a lifecycle the ORM cannot express (`REFRESH`, no `CREATE OR REPLACE`, `CONCURRENTLY` preconditions, dependency-ordered rebuilds). The ORM reads it as a typed, read-only row source; it must never persist it nor own its DDL. See [Design rationale](../internals/design-rationale.md).
