<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;

final class DependencyCycleDetected extends RuntimeException implements MaterializedViewError
{
    /**
     * @param list<string> $cycle
     */
    public static function amongViews(array $cycle): self
    {
        return new self(\sprintf(
            'A dependency cycle was detected among managed materialized views: %s.',
            '' === implode(' -> ', $cycle) ? 'unknown' : implode(' -> ', $cycle),
        ));
    }
}
