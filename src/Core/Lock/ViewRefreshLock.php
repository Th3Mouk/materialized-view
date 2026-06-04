<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class ViewRefreshLock
{
    public function __construct(
        private Connection $connection,
        private StableLockKeyGenerator $keyGenerator,
    ) {
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
    }

    /**
     * @throws Exception
     */
    public function tryAcquire(MaterializedViewName $name): bool
    {
        $lockKey = $this->keyGenerator->forView($name);

        return (bool) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(?, ?)',
            [$lockKey->namespace, $lockKey->key],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /**
     * @throws Exception
     */
    public function release(MaterializedViewName $name): bool
    {
        $lockKey = $this->keyGenerator->forView($name);

        return (bool) $this->connection->fetchOne(
            'SELECT pg_advisory_unlock(?, ?)',
            [$lockKey->namespace, $lockKey->key],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }
}
