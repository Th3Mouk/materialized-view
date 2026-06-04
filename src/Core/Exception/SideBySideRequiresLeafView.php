<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final class SideBySideRequiresLeafView extends RuntimeException implements MaterializedViewError
{
    /**
     * @param list<string> $dependentViewNames
     */
    public static function forView(MaterializedViewName $name, array $dependentViewNames): self
    {
        return new self(\sprintf(
            'The side-by-side rebuild strategy is invalid for "%s" because it has dependents (%s); renaming the old view does not re-point dependents at the new OID.',
            $name->qualifiedName(),
            '' === implode(', ', $dependentViewNames) ? 'unknown' : implode(', ', $dependentViewNames),
        ));
    }
}
