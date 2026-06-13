<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Migration;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

final class MaterializedViewMigrationSql
{
    /**
     * @return iterable<string>
     */
    public static function create(MaterializedViewDefinition $definition): iterable
    {
        $quoter = new IdentifierQuoter();
        $quotedName = $quoter->quoteQualifiedName($definition->name());

        yield self::dropStatement($quotedName);
        yield self::createStatement($quotedName, $definition);

        foreach ($definition->indexes() as $index) {
            yield self::createIndexStatement($quotedName, $index, $quoter);
        }
    }

    /**
     * @return iterable<string>
     */
    public static function drop(MaterializedViewDefinition $definition): iterable
    {
        $quoter = new IdentifierQuoter();

        yield self::dropStatement($quoter->quoteQualifiedName($definition->name()));
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
        IdentifierQuoter $quoter,
    ): string {
        $statement = 'CREATE ';

        if ($index->unique) {
            $statement .= 'UNIQUE ';
        }

        $statement .= 'INDEX ';

        if ($index->concurrently) {
            $statement .= 'CONCURRENTLY ';
        }

        $statement .= $quoter->quoteIdentifier($index->name);
        $statement .= ' ON '.$quotedViewName;

        if (null !== $index->method) {
            $statement .= ' USING '.$quoter->quoteIdentifier($index->method);
        }

        $statement .= ' ('.$quoter->quoteColumnList($index->columns).')';

        if ([] !== $index->include) {
            $statement .= ' INCLUDE ('.$quoter->quoteColumnList($index->include).')';
        }

        if (null !== $index->where) {
            $statement .= ' WHERE '.$index->where;
        }

        return $statement;
    }

    private static function normalizedBody(MaterializedViewDefinition $definition): string
    {
        $body = trim($definition->sqlSource()->sql());

        return rtrim($body, "; \t\n\r\0\x0B");
    }
}
