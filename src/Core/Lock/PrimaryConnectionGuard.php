<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;

final readonly class PrimaryConnectionGuard
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Pins subsequent statements to the primary before any DDL or refresh.
     *
     * The connection decides what this means: a Doctrine primary/replica
     * connection switches to the primary, a bare PDO handle does nothing.
     *
     * @throws DatabaseException
     */
    public function ensureConnectedToPrimary(): void
    {
        $this->connection->ensureConnectedToPrimary();
    }
}
