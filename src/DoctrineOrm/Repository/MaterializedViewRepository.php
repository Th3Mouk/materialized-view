<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * @template T of object
 *
 * @extends EntityRepository<T>
 */
abstract class MaterializedViewRepository extends EntityRepository
{
}
