<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Privilege;

use Doctrine\DBAL\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class PrivilegeSnapshotter
{
    private const string GRANTS_QUERY = <<<'SQL'
        SELECT grantee, privilege_type, is_grantable
        FROM information_schema.role_table_grants
        WHERE table_schema = :schema
          AND table_name = :name
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function capture(MaterializedViewName $view): PrivilegeSnapshot
    {
        $rows = $this->connection->fetchAllAssociative(
            self::GRANTS_QUERY,
            [
                'schema' => $view->schema,
                'name' => $view->name,
            ],
        );

        $privileges = [];

        foreach ($rows as $row) {
            $privileges[] = ObjectPrivilege::fromCatalogRow(
                grantee: (string) $row['grantee'],
                privilegeType: (string) $row['privilege_type'],
                isGrantable: (string) $row['is_grantable'],
            );
        }

        return PrivilegeSnapshot::forView($view, $privileges);
    }
}
