<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception;

final readonly class PrimaryConnectionGuard
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function ensureConnectedToPrimary(): void
    {
        if (!$this->connection instanceof PrimaryReadReplicaConnection) {
            return;
        }

        $this->connection->ensureConnectedToPrimary();
    }
}
