<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;

final readonly class LaneLock
{
    public function __construct(
        private Connection $connection,
        private int $laneNamespace,
    ) {
    }

    /**
     * @throws Exception
     */
    public function acquire(): void
    {
        $this->connection->executeStatement(
            'SELECT pg_advisory_lock(?)',
            [$this->laneNamespace],
            [ParameterType::INTEGER],
        );
    }

    /**
     * @throws Exception
     */
    public function tryAcquire(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(?)',
            [$this->laneNamespace],
            [ParameterType::INTEGER],
        );
    }

    /**
     * @throws Exception
     */
    public function release(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT pg_advisory_unlock(?)',
            [$this->laneNamespace],
            [ParameterType::INTEGER],
        );
    }
}
