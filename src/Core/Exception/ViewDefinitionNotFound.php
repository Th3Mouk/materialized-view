<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;

final class ViewDefinitionNotFound extends RuntimeException implements MaterializedViewError
{
    public static function byName(string $qualifiedName): self
    {
        return new self(\sprintf(
            'No materialized view definition is registered under "%s".',
            $qualifiedName,
        ));
    }
}
