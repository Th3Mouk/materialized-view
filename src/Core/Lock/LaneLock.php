<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;
use Th3Mouk\MaterializedView\Core\Database\ParameterType;

final readonly class LaneLock
{
    private LoggerInterface $logger;

    public function __construct(
        private Connection $connection,
        private int $laneNamespace,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws DatabaseException
     */
    public function acquire(): void
    {
        $this->connection->executeStatement(
            'SELECT pg_advisory_lock(?)',
            [$this->laneNamespace],
            [ParameterType::Integer],
        );

        $this->logger->debug('Acquired deploy-lane advisory lock.', ['lock_key' => $this->laneNamespace]);
    }

    /**
     * @throws DatabaseException
     */
    public function tryAcquire(): bool
    {
        $acquired = (bool) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(?)',
            [$this->laneNamespace],
            [ParameterType::Integer],
        );

        if (!$acquired) {
            $this->logger->warning(
                'Could not acquire deploy-lane advisory lock: held by another session.',
                ['lock_key' => $this->laneNamespace],
            );

            return false;
        }

        $this->logger->debug('Acquired deploy-lane advisory lock.', ['lock_key' => $this->laneNamespace]);

        return true;
    }

    /**
     * @throws DatabaseException
     */
    public function release(): bool
    {
        $released = (bool) $this->connection->fetchOne(
            'SELECT pg_advisory_unlock(?)',
            [$this->laneNamespace],
            [ParameterType::Integer],
        );

        $this->logger->debug('Released deploy-lane advisory lock.', [
            'lock_key' => $this->laneNamespace,
            'released' => $released,
        ]);

        return $released;
    }
}
