<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use InvalidArgumentException;

final class InvalidViewName extends InvalidArgumentException implements MaterializedViewError
{
    public static function empty(): self
    {
        return new self('A materialized view name cannot be empty.');
    }

    public static function tooManyParts(string $rawName): self
    {
        return new self(\sprintf(
            'A materialized view name must be "name" or "schema.name", got "%s".',
            $rawName,
        ));
    }

    public static function blankSegment(string $rawName): self
    {
        return new self(\sprintf(
            'A materialized view name has an empty schema or name segment in "%s".',
            $rawName,
        ));
    }

    public static function unsupportedCharacters(string $segment): self
    {
        return new self(\sprintf(
            'The identifier "%s" contains unsupported characters; allowed: letters, digits and underscore, not starting with a digit.',
            $segment,
        ));
    }

    public static function tooLong(string $segment, int $maxLength): self
    {
        return new self(\sprintf(
            'The identifier "%s" exceeds the PostgreSQL maximum length of %d bytes.',
            $segment,
            $maxLength,
        ));
    }
}
