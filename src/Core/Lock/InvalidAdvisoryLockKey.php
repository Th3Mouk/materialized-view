<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use InvalidArgumentException;
use Th3Mouk\MaterializedView\Core\Exception\MaterializedViewError;

final class InvalidAdvisoryLockKey extends InvalidArgumentException implements MaterializedViewError
{
    public static function outOfInt4Range(int $value, int $min, int $max): self
    {
        return new self(\sprintf(
            'An advisory lock key must be a signed int4 between %d and %d, got %d.',
            $min,
            $max,
            $value,
        ));
    }
}
