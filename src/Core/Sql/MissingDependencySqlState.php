<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sql;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Throwable;

final readonly class MissingDependencySqlState
{
    private const string UNDEFINED_TABLE = '42P01';

    private const string INVALID_SCHEMA_NAME = '3F000';

    public static function isMissingDependency(Throwable $throwable): bool
    {
        $sqlState = self::resolveSqlState($throwable);

        return self::UNDEFINED_TABLE === $sqlState || self::INVALID_SCHEMA_NAME === $sqlState;
    }

    private static function resolveSqlState(Throwable $throwable): ?string
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException) {
                $sqlState = $current->getSQLState();

                if (null !== $sqlState) {
                    return $sqlState;
                }
            }
        }

        return null;
    }
}
