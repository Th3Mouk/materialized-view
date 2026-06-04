<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Exception;

use LogicException;
use Th3Mouk\MaterializedView\Core\Exception\MaterializedViewError;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewEntity;

final class NotAMaterializedViewEntity extends LogicException implements MaterializedViewError
{
    public static function missingAttribute(string $entityClass): self
    {
        return new self(\sprintf(
            'The class "%s" is not a materialized view entity; add the #[%s] attribute.',
            $entityClass,
            MaterializedViewEntity::class,
        ));
    }
}
