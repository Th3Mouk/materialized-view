<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Privilege;

use Doctrine\DBAL\Connection;

final readonly class PrivilegeReplayer
{
    public function __construct(
        private Connection $connection,
        private GrantStatementGenerator $grantStatementGenerator,
    ) {
    }

    public function replay(PrivilegeSnapshot $snapshot): int
    {
        $statements = $this->grantStatementGenerator->forSnapshot($snapshot);

        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }

        return \count($statements);
    }
}
