<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sql;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\ConcurrentRefreshUnsupported;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshOptions;

final readonly class PostgreSqlMaterializedViewSqlGenerator
{
    public function __construct(
        private IdentifierQuoter $quoter,
    ) {
    }

    public function create(MaterializedViewDefinition $definition): string
    {
        $dataClause = $definition->createWithData() ? 'WITH DATA' : 'WITH NO DATA';

        return \sprintf(
            'CREATE MATERIALIZED VIEW %s AS %s %s',
            $this->quoter->quoteQualifiedName($definition->name()),
            $this->selectBody($definition),
            $dataClause,
        );
    }

    public function drop(MaterializedViewName $name, bool $ifExists = false, bool $cascade = false): string
    {
        $ifExistsClause = $ifExists ? 'IF EXISTS ' : '';
        $cascadeClause = $cascade ? ' CASCADE' : '';

        return \sprintf(
            'DROP MATERIALIZED VIEW %s%s%s',
            $ifExistsClause,
            $this->quoter->quoteQualifiedName($name),
            $cascadeClause,
        );
    }

    public function refresh(MaterializedViewName $name, RefreshOptions $options): string
    {
        if ($options->concurrently && !$options->withData) {
            throw ConcurrentRefreshUnsupported::withNoData($name);
        }

        $concurrentlyClause = $options->concurrently ? 'CONCURRENTLY ' : '';
        $dataClause = $options->withData ? '' : ' WITH NO DATA';

        return \sprintf(
            'REFRESH MATERIALIZED VIEW %s%s%s',
            $concurrentlyClause,
            $this->quoter->quoteQualifiedName($name),
            $dataClause,
        );
    }

    public function createIndex(MaterializedViewName $viewName, MaterializedViewIndex $index): string
    {
        $uniqueClause = $index->unique ? 'UNIQUE ' : '';
        $concurrentlyClause = $index->concurrently ? 'CONCURRENTLY ' : '';
        $usingClause = null === $index->method
            ? ''
            : ' USING '.$this->quoter->quoteIdentifier($index->method);
        $includeClause = [] === $index->include
            ? ''
            : \sprintf(' INCLUDE (%s)', $this->quoter->quoteColumnList($index->include));
        $whereClause = null === $index->where
            ? ''
            : ' WHERE '.$index->where;

        return \sprintf(
            'CREATE %sINDEX %s%s ON %s%s (%s)%s%s',
            $uniqueClause,
            $concurrentlyClause,
            $this->quoter->quoteIdentifier($index->name),
            $this->quoter->quoteQualifiedName($viewName),
            $usingClause,
            $this->quoter->quoteColumnList($index->columns),
            $includeClause,
            $whereClause,
        );
    }

    public function comment(MaterializedViewName $name, ManagementMarker $marker): string
    {
        return \sprintf(
            'COMMENT ON MATERIALIZED VIEW %s IS %s',
            $this->quoter->quoteQualifiedName($name),
            $this->quoter->quoteStringLiteral($marker->toJson()),
        );
    }

    public function rename(MaterializedViewName $name, string $newName): string
    {
        return \sprintf(
            'ALTER MATERIALIZED VIEW %s RENAME TO %s',
            $this->quoter->quoteQualifiedName($name),
            $this->quoter->quoteIdentifier($newName),
        );
    }

    private function selectBody(MaterializedViewDefinition $definition): string
    {
        $sql = trim($definition->sqlSource()->sql());

        return rtrim($sql, "; \t\n\r\0\x0B");
    }
}
