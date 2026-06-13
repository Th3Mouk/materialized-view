<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Introspection;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class PostgreSqlMaterializedViewIntrospector
{
    private const string MATERIALIZED_VIEWS_IN_SCHEMA_SQL = <<<'SQL'
        SELECT
            n.nspname AS schema_name,
            c.relname AS view_name,
            pg_get_viewdef(c.oid, true) AS definition,
            c.relispopulated AS is_populated,
            obj_description(c.oid, 'pg_class') AS comment
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relkind = 'm'
          AND n.nspname = :schema_name
        ORDER BY c.relname
        SQL;

    private const string SINGLE_MATERIALIZED_VIEW_SQL = <<<'SQL'
        SELECT
            n.nspname AS schema_name,
            c.relname AS view_name,
            pg_get_viewdef(c.oid, true) AS definition,
            c.relispopulated AS is_populated,
            obj_description(c.oid, 'pg_class') AS comment
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relkind = 'm'
          AND n.nspname = :schema_name
          AND c.relname = :view_name
        SQL;

    private const string INDEXES_SQL = <<<'SQL'
        SELECT indexname, indexdef
        FROM pg_indexes
        WHERE schemaname = :schema_name
          AND tablename = :view_name
        ORDER BY indexname
        SQL;

    private LoggerInterface $logger;

    public function __construct(
        private Connection $connection,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return list<IntrospectedMaterializedView>
     */
    public function introspectSchema(string $schema = MaterializedViewName::DEFAULT_SCHEMA): array
    {
        $this->logger->debug('Probing pg_class for materialized views in schema "{schema}".', ['schema' => $schema]);

        $rows = $this->connection->fetchAllAssociative(
            self::MATERIALIZED_VIEWS_IN_SCHEMA_SQL,
            ['schema_name' => $schema],
        );

        $views = [];

        foreach ($rows as $row) {
            $name = MaterializedViewName::create(
                $this->stringColumn($row, 'schema_name'),
                $this->stringColumn($row, 'view_name'),
            );

            $views[] = $this->hydrateView($name, $row);
        }

        $this->logger->debug('Found {count} materialized view(s) in schema "{schema}".', [
            'schema' => $schema,
            'count' => \count($views),
        ]);

        return $views;
    }

    public function find(MaterializedViewName $name): ?IntrospectedMaterializedView
    {
        $this->logger->debug('Probing pg_class for materialized view "{view}".', [
            'view' => $name->qualifiedName(),
        ]);

        $row = $this->connection->fetchAssociative(
            self::SINGLE_MATERIALIZED_VIEW_SQL,
            [
                'schema_name' => $name->schema,
                'view_name' => $name->name,
            ],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateView($name, $row);
    }

    public function exists(MaterializedViewName $name): bool
    {
        return null !== $this->find($name);
    }

    /**
     * @return list<IntrospectedIndex>
     */
    public function introspectIndexes(MaterializedViewName $name): array
    {
        $this->logger->debug('Probing pg_indexes for materialized view "{view}".', [
            'view' => $name->qualifiedName(),
        ]);

        $rows = $this->connection->fetchAllAssociative(
            self::INDEXES_SQL,
            [
                'schema_name' => $name->schema,
                'view_name' => $name->name,
            ],
        );

        $indexes = [];

        foreach ($rows as $row) {
            $indexes[] = IntrospectedIndex::create(
                $this->stringColumn($row, 'indexname'),
                $this->stringColumn($row, 'indexdef'),
            );
        }

        return $indexes;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateView(MaterializedViewName $name, array $row): IntrospectedMaterializedView
    {
        return IntrospectedMaterializedView::create(
            name: $name,
            definition: $this->stringColumn($row, 'definition'),
            isPopulated: $this->boolColumn($row, 'is_populated'),
            comment: $this->nullableStringColumn($row, 'comment'),
            indexes: $this->introspectIndexes($name),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringColumn(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringColumn(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function boolColumn(array $row, string $column): bool
    {
        $value = $row[$column] ?? null;

        if (\is_bool($value)) {
            return $value;
        }

        return \in_array($value, [1, '1', 't', 'true'], true);
    }
}
