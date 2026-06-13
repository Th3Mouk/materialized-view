<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Dbal;

use Closure;
use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType as DbalParameterType;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;
use Th3Mouk\MaterializedView\Core\Database\ParameterType;
use Throwable;

/**
 * {@see Connection} adapter backed by Doctrine DBAL.
 *
 * Keeps everything the DBAL connection brings: primary/replica routing,
 * middlewares, profiling and the configured PostgreSQL driver. Native DBAL
 * failures are translated to {@see DatabaseException}, carrying the SQLSTATE.
 */
final readonly class DbalConnection implements Connection
{
    public function __construct(
        private DoctrineConnection $connection,
    ) {
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        try {
            return (int) $this->connection->executeStatement($sql, $params, self::mapTypes($types));
        } catch (DbalException $exception) {
            throw self::wrap($exception);
        }
    }

    public function fetchOne(string $sql, array $params = [], array $types = []): mixed
    {
        try {
            return $this->connection->fetchOne($sql, $params, self::mapTypes($types));
        } catch (DbalException $exception) {
            throw self::wrap($exception);
        }
    }

    public function fetchAllAssociative(string $sql, array $params = [], array $types = []): array
    {
        try {
            return $this->connection->fetchAllAssociative($sql, $params, self::mapTypes($types));
        } catch (DbalException $exception) {
            throw self::wrap($exception);
        }
    }

    public function fetchAssociative(string $sql, array $params = [], array $types = []): array|false
    {
        try {
            return $this->connection->fetchAssociative($sql, $params, self::mapTypes($types));
        } catch (DbalException $exception) {
            throw self::wrap($exception);
        }
    }

    public function transactional(Closure $operation): mixed
    {
        try {
            return $this->connection->transactional(fn (): mixed => $operation($this));
        } catch (DbalException $exception) {
            throw self::wrap($exception);
        }
    }

    public function ensureConnectedToPrimary(): void
    {
        if (!$this->connection instanceof PrimaryReadReplicaConnection) {
            return;
        }

        try {
            $this->connection->ensureConnectedToPrimary();
        } catch (DbalException $exception) {
            throw self::wrap($exception);
        }
    }

    /**
     * @param array<int<0, max>|string, ParameterType> $types
     *
     * @return array<int<0, max>|string, DbalParameterType>
     */
    private static function mapTypes(array $types): array
    {
        return array_map(static fn (ParameterType $type): DbalParameterType => match ($type) {
            ParameterType::String => DbalParameterType::STRING,
            ParameterType::Integer => DbalParameterType::INTEGER,
            ParameterType::Boolean => DbalParameterType::BOOLEAN,
        }, $types);
    }

    private static function wrap(DbalException $exception): DatabaseException
    {
        return new DatabaseException($exception->getMessage(), self::sqlStateOf($exception), $exception);
    }

    private static function sqlStateOf(Throwable $throwable): ?string
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException) {
                $sqlState = $current->getSQLState();

                if (null !== $sqlState) {
                    return $sqlState;
                }
            }
        }

        return null;
    }
}
