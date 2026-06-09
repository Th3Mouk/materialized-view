<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sql;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Throwable;

/**
 * Recognises the two PostgreSQL SQLSTATEs that signal a DDL statement was blocked by a
 * (materialized) view depending on the relation being altered — the trigger for the
 * reactive targeted-drop lane.
 *
 *   2BP01 (dependent_objects_still_exist) — DROP TABLE / DROP COLUMN blocked.
 *   0A000 (feature_not_supported)         — ALTER COLUMN ... TYPE blocked by a view/rule.
 *
 * Gate on SQLSTATE, never on the exception class or message text: PostgreSQL surfaces
 * both states as the generic Doctrine\DBAL\Exception\DriverException, and the wording is
 * emitted in the server's lc_messages locale. The state is read from the deepest
 * Doctrine\DBAL\Driver\Exception in the chain (mirrors {@see MissingDependencySqlState}).
 *
 * 0A000 is also raised by unrelated statements (e.g. TRUNCATE/foreign-key cases), so a
 * gated SQLSTATE alone is necessary but not sufficient: the caller still parses and, above
 * all, confirms the dependency closure against the system catalog before acting.
 */
final readonly class DependencyConflictSqlState
{
    public const string DEPENDENT_OBJECTS_STILL_EXIST = '2BP01';

    public const string FEATURE_NOT_SUPPORTED = '0A000';

    public static function isDependencyConflict(Throwable $throwable): bool
    {
        return self::isDependencyConflictState(self::resolveSqlState($throwable));
    }

    public static function isDependencyConflictState(?string $sqlState): bool
    {
        return self::DEPENDENT_OBJECTS_STILL_EXIST === $sqlState
            || self::FEATURE_NOT_SUPPORTED === $sqlState;
    }

    public static function resolveSqlState(Throwable $throwable): ?string
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
