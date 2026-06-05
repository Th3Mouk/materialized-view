<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class ViewRefreshLock
{
    private LoggerInterface $logger;

    public function __construct(
        private Connection $connection,
        private StableLockKeyGenerator $keyGenerator,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws Exception
     */
    public function acquire(MaterializedViewName $name): void
    {
        $lockKey = $this->keyGenerator->forView($name);

        $this->connection->executeStatement(
            'SELECT pg_advisory_lock(?, ?)',
            [$lockKey->namespace, $lockKey->key],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $this->logger->debug('Acquired refresh advisory lock for materialized view "{view}".', [
            'view' => $name->qualifiedName(),
            'lock_key' => $lockKey->key,
        ]);
    }

    /**
     * @throws Exception
     */
    public function tryAcquire(MaterializedViewName $name): bool
    {
        $lockKey = $this->keyGenerator->forView($name);

        $acquired = (bool) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(?, ?)',
            [$lockKey->namespace, $lockKey->key],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        if (!$acquired) {
            $this->logger->warning(
                'Could not acquire refresh advisory lock for materialized view "{view}": held by another session.',
                ['view' => $name->qualifiedName(), 'lock_key' => $lockKey->key],
            );

            return false;
        }

        $this->logger->debug('Acquired refresh advisory lock for materialized view "{view}".', [
            'view' => $name->qualifiedName(),
            'lock_key' => $lockKey->key,
        ]);

        return true;
    }

    /**
     * @throws Exception
     */
    public function release(MaterializedViewName $name): bool
    {
        $lockKey = $this->keyGenerator->forView($name);

        $released = (bool) $this->connection->fetchOne(
            'SELECT pg_advisory_unlock(?, ?)',
            [$lockKey->namespace, $lockKey->key],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $this->logger->debug('Released refresh advisory lock for materialized view "{view}".', [
            'view' => $name->qualifiedName(),
            'lock_key' => $lockKey->key,
            'released' => $released,
        ]);

        return $released;
    }
}
