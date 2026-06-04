<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_plain')]
final class PlainEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id = 0;
}
