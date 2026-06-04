<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use InvalidArgumentException;

final class DuplicateViewDefinition extends InvalidArgumentException implements MaterializedViewError
{
    public static function byName(string $qualifiedName): self
    {
        return new self(\sprintf(
            'The materialized view "%s" is declared more than once in the registry.',
            $qualifiedName,
        ));
    }
}
