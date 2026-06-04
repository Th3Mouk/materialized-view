<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final class UnmanagedDependentFound extends RuntimeException implements MaterializedViewError
{
    /**
     * @param list<string> $dependentNames
     */
    public static function blockingDrop(MaterializedViewName $name, array $dependentNames): self
    {
        return new self(\sprintf(
            'Refusing to drop "%s": it has unmanaged dependents (%s). Drop or migrate them first; CASCADE is never implicit.',
            $name->qualifiedName(),
            '' === implode(', ', $dependentNames) ? 'unknown' : implode(', ', $dependentNames),
        ));
    }

    /**
     * @param list<string> $dependentNames
     */
    public static function blockingRebuild(MaterializedViewName $name, array $dependentNames): self
    {
        return new self(\sprintf(
            'Refusing to rebuild "%s": it has unmanaged dependents (%s) the library cannot recreate.',
            $name->qualifiedName(),
            '' === implode(', ', $dependentNames) ? 'unknown' : implode(', ', $dependentNames),
        ));
    }
}
