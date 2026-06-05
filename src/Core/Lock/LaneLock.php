<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
     * @throws Exception
     */
    public function acquire(): void
    {
        $this->connection->executeStatement(
            'SELECT pg_advisory_lock(?)',
            [$this->laneNamespace],
            [ParameterType::INTEGER],
        );

        $this->logger->debug('Acquired deploy-lane advisory lock.', ['lock_key' => $this->laneNamespace]);
    }

    /**
     * @throws Exception
     */
    public function tryAcquire(): bool
    {
        $acquired = (bool) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(?)',
            [$this->laneNamespace],
            [ParameterType::INTEGER],
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
     * @throws Exception
     */
    public function release(): bool
    {
        $released = (bool) $this->connection->fetchOne(
            'SELECT pg_advisory_unlock(?)',
            [$this->laneNamespace],
            [ParameterType::INTEGER],
        );

        $this->logger->debug('Released deploy-lane advisory lock.', [
            'lock_key' => $this->laneNamespace,
            'released' => $released,
        ]);

        return $released;
    }
}
