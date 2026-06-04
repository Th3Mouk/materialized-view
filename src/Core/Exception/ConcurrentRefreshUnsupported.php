<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final class ConcurrentRefreshUnsupported extends RuntimeException implements MaterializedViewError
{
    public static function withNoData(MaterializedViewName $name): self
    {
        return new self(\sprintf(
            'CONCURRENTLY and WITH NO DATA cannot be combined for "%s".',
            $name->qualifiedName(),
        ));
    }

    public static function partialUniqueIndexOnly(MaterializedViewName $name): self
    {
        return new self(\sprintf(
            'CONCURRENTLY refresh of "%s" is unsupported: its only UNIQUE index is partial or expression-based and does not cover all rows.',
            $name->qualifiedName(),
        ));
    }
}
