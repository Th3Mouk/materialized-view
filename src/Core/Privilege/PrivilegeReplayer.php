<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Privilege;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class PrivilegeReplayer
{
    private LoggerInterface $logger;

    public function __construct(
        private Connection $connection,
        private GrantStatementGenerator $grantStatementGenerator,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function replay(PrivilegeSnapshot $snapshot): int
    {
        $statements = $this->grantStatementGenerator->forSnapshot($snapshot);

        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }

        $this->logger->debug('Replayed grants for materialized view "{view}".', [
            'view' => $snapshot->view->qualifiedName(),
            'count' => \count($statements),
        ]);

        return \count($statements);
    }
}
