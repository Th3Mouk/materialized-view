<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;

final class CannotReadSqlSource extends RuntimeException implements MaterializedViewError
{
    public static function fileNotFound(string $path): self
    {
        return new self(\sprintf('The SQL source file "%s" does not exist.', $path));
    }

    public static function fileNotReadable(string $path): self
    {
        return new self(\sprintf('The SQL source file "%s" exists but is not readable.', $path));
    }

    public static function fileIsEmpty(string $path): self
    {
        return new self(\sprintf('The SQL source file "%s" is empty.', $path));
    }

    public static function emptyInlineSql(): self
    {
        return new self('An inline SQL source cannot be empty.');
    }
}
