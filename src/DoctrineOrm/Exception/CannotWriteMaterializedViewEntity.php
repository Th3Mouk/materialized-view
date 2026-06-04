<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Exception;

use RuntimeException;
use Th3Mouk\MaterializedView\Core\Exception\MaterializedViewError;

final class CannotWriteMaterializedViewEntity extends RuntimeException implements MaterializedViewError
{
    public static function onInsert(string $entityClass): self
    {
        return self::because($entityClass, 'inserted');
    }

    public static function onUpdate(string $entityClass): self
    {
        return self::because($entityClass, 'updated');
    }

    public static function onDelete(string $entityClass): self
    {
        return self::because($entityClass, 'deleted');
    }

    private static function because(string $entityClass, string $operation): self
    {
        return new self(\sprintf(
            'The entity "%s" maps a materialized view and is read-only; it cannot be %s. '
            .'Refresh the view through the materialized view manager instead.',
            $entityClass,
            $operation,
        ));
    }
}
