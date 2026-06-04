<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final class MissingUniqueIndexForConcurrentRefresh extends RuntimeException implements MaterializedViewError
{
    public static function forView(MaterializedViewName $name): self
    {
        return new self(\sprintf(
            'CONCURRENTLY refresh requires "%s" to have at least one UNIQUE index that uses only column names and covers all rows (no expression, no WHERE clause).',
            $name->qualifiedName(),
        ));
    }
}
