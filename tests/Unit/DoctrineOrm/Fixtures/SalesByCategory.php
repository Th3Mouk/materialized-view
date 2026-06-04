<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewEntity;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sales_by_category')]
#[MaterializedViewEntity(definition: SalesByCategoryView::class)]
final class SalesByCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    public string $category = '';

    #[ORM\Column(type: 'integer')]
    public int $orderCount = 0;

    #[ORM\Column(type: 'string')]
    public string $totalAmount = '0';
}
