<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewEntity;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'summary', schema: 'analytics')]
#[MaterializedViewEntity(definition: SalesByCategoryView::class)]
final class SchemaQualifiedSummary
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;
}
