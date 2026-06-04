<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final class ViewNotPopulated extends RuntimeException implements MaterializedViewError
{
    public static function forRead(MaterializedViewName $name): self
    {
        return new self(\sprintf(
            'The materialized view "%s" has not been populated and cannot be read; refresh it first.',
            $name->qualifiedName(),
        ));
    }

    public static function forConcurrentRefresh(MaterializedViewName $name): self
    {
        return new self(\sprintf(
            'CONCURRENTLY refresh is impossible on "%s": the materialized view has not been populated yet.',
            $name->qualifiedName(),
        ));
    }
}
