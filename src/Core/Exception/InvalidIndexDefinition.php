<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use InvalidArgumentException;

final class InvalidIndexDefinition extends InvalidArgumentException implements MaterializedViewError
{
    public static function emptyName(): self
    {
        return new self('A materialized view index must have a name.');
    }

    public static function noColumns(string $indexName): self
    {
        return new self(\sprintf('The index "%s" must declare at least one column.', $indexName));
    }

    public static function blankColumn(string $indexName): self
    {
        return new self(\sprintf('The index "%s" declares an empty column name.', $indexName));
    }

    public static function blankIncludeColumn(string $indexName): self
    {
        return new self(\sprintf('The index "%s" declares an empty INCLUDE column name.', $indexName));
    }

    public static function blankMethod(string $indexName): self
    {
        return new self(\sprintf('The index "%s" declares an empty access method.', $indexName));
    }

    public static function blankWhereClause(string $indexName): self
    {
        return new self(\sprintf('The index "%s" declares a WHERE clause that is blank.', $indexName));
    }
}
