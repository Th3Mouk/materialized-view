<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class IndexSnapshotter
{
    private const string PG_INDEXES_QUERY = <<<'SQL'
        SELECT indexname, indexdef
        FROM pg_indexes
        WHERE schemaname = :schema_name
          AND tablename = :view_name
        ORDER BY indexname
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function capture(MaterializedViewName $view): IndexSnapshot
    {
        $rows = $this->connection->fetchAllAssociative(
            self::PG_INDEXES_QUERY,
            [
                'schema_name' => $view->schema,
                'view_name' => $view->name,
            ],
        );

        $captured = [];

        foreach ($rows as $row) {
            $captured[] = CapturedIndex::fromCatalogRow(
                (string) $row['indexname'],
                (string) $row['indexdef'],
            );
        }

        return IndexSnapshot::forView($view, $captured);
    }
}
