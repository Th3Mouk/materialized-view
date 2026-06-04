<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

final readonly class RebuildStatementFactory
{
    public function __construct(
        private IdentifierQuoter $quoter,
    ) {
    }

    public function dropIfExists(MaterializedViewName $view, bool $cascade = false): string
    {
        return \sprintf(
            'DROP MATERIALIZED VIEW IF EXISTS %s%s',
            $this->qualifiedName($view),
            $cascade ? ' CASCADE' : '',
        );
    }

    public function create(MaterializedViewName $view, string $selectSql, bool $withData): string
    {
        return \sprintf(
            'CREATE MATERIALIZED VIEW %s AS %s WITH %s',
            $this->qualifiedName($view),
            $this->normalizeSelect($selectSql),
            $withData ? 'DATA' : 'NO DATA',
        );
    }

    public function createIndex(MaterializedViewName $view, MaterializedViewIndex $index, string $indexName): string
    {
        $sql = \sprintf(
            'CREATE %sINDEX %s ON %s',
            $index->unique ? 'UNIQUE ' : '',
            $this->quoter->quoteIdentifier($indexName),
            $this->qualifiedName($view),
        );

        if (null !== $index->method) {
            $sql .= \sprintf(' USING %s', $this->quoter->quoteIdentifier($index->method));
        }

        $sql .= \sprintf(' (%s)', $this->quoter->quoteColumnList($index->columns));

        if ([] !== $index->include) {
            $sql .= \sprintf(' INCLUDE (%s)', $this->quoter->quoteColumnList($index->include));
        }

        if (null !== $index->where) {
            $sql .= \sprintf(' WHERE %s', $index->where);
        }

        return $sql;
    }

    public function comment(MaterializedViewName $view, string $payload): string
    {
        return \sprintf(
            'COMMENT ON MATERIALIZED VIEW %s IS %s',
            $this->qualifiedName($view),
            $this->quoter->quoteStringLiteral($payload),
        );
    }

    public function renameView(MaterializedViewName $view, string $newName): string
    {
        return \sprintf(
            'ALTER MATERIALIZED VIEW %s RENAME TO %s',
            $this->qualifiedName($view),
            $this->quoter->quoteIdentifier($newName),
        );
    }

    public function renameIndex(MaterializedViewName $view, string $currentName, string $newName): string
    {
        return \sprintf(
            'ALTER INDEX %s RENAME TO %s',
            $this->qualifiedIndexName($view, $currentName),
            $this->quoter->quoteIdentifier($newName),
        );
    }

    public function refreshWithData(MaterializedViewName $view): string
    {
        return \sprintf('REFRESH MATERIALIZED VIEW %s WITH DATA', $this->qualifiedName($view));
    }

    public function lockForSwap(MaterializedViewName $view): string
    {
        return \sprintf('LOCK TABLE %s IN ACCESS EXCLUSIVE MODE', $this->qualifiedName($view));
    }

    private function qualifiedName(MaterializedViewName $view): string
    {
        return $this->quoter->quoteQualifiedName($view);
    }

    private function qualifiedIndexName(MaterializedViewName $view, string $indexName): string
    {
        return $this->quoter->quoteIdentifier($view->schema)
            .'.'
            .$this->quoter->quoteIdentifier($indexName);
    }

    private function normalizeSelect(string $selectSql): string
    {
        return rtrim(trim($selectSql), ';');
    }
}
