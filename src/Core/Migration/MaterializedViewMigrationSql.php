<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Migration;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final class MaterializedViewMigrationSql
{
    /**
     * @return iterable<string>
     */
    public static function create(MaterializedViewDefinition $definition): iterable
    {
        $platform = new PostgreSQLPlatform();
        $quotedName = self::quoteQualifiedName($definition->name(), $platform);

        yield self::dropStatement($quotedName);
        yield self::createStatement($quotedName, $definition);

        foreach ($definition->indexes() as $index) {
            yield self::createIndexStatement($quotedName, $index, $platform);
        }
    }

    /**
     * @return iterable<string>
     */
    public static function drop(MaterializedViewDefinition $definition): iterable
    {
        $platform = new PostgreSQLPlatform();

        yield self::dropStatement(self::quoteQualifiedName($definition->name(), $platform));
    }

    private static function dropStatement(string $quotedName): string
    {
        return \sprintf('DROP MATERIALIZED VIEW IF EXISTS %s', $quotedName);
    }

    private static function createStatement(string $quotedName, MaterializedViewDefinition $definition): string
    {
        return \sprintf(
            'CREATE MATERIALIZED VIEW %s AS %s WITH %s',
            $quotedName,
            self::normalizedBody($definition),
            $definition->createWithData() ? 'DATA' : 'NO DATA',
        );
    }

    private static function createIndexStatement(
        string $quotedViewName,
        MaterializedViewIndex $index,
        PostgreSQLPlatform $platform,
    ): string {
        $statement = 'CREATE ';

        if ($index->unique) {
            $statement .= 'UNIQUE ';
        }

        $statement .= 'INDEX ';

        if ($index->concurrently) {
            $statement .= 'CONCURRENTLY ';
        }

        $statement .= $platform->quoteSingleIdentifier($index->name);
        $statement .= ' ON '.$quotedViewName;

        if (null !== $index->method) {
            $statement .= ' USING '.$platform->quoteSingleIdentifier($index->method);
        }

        $statement .= ' ('.self::quoteColumnList($index->columns, $platform).')';

        if ([] !== $index->include) {
            $statement .= ' INCLUDE ('.self::quoteColumnList($index->include, $platform).')';
        }

        if (null !== $index->where) {
            $statement .= ' WHERE '.$index->where;
        }

        return $statement;
    }

    private static function quoteQualifiedName(MaterializedViewName $name, PostgreSQLPlatform $platform): string
    {
        return $platform->quoteSingleIdentifier($name->schema)
            .'.'
            .$platform->quoteSingleIdentifier($name->name);
    }

    /**
     * @param list<string> $columns
     */
    private static function quoteColumnList(array $columns, PostgreSQLPlatform $platform): string
    {
        return implode(', ', array_map($platform->quoteSingleIdentifier(...), $columns));
    }

    private static function normalizedBody(MaterializedViewDefinition $definition): string
    {
        $body = trim($definition->sqlSource()->sql());

        return rtrim($body, "; \t\n\r\0\x0B");
    }
}
