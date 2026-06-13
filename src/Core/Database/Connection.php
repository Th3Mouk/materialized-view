<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Database;

use Closure;

/**
 * Minimal PostgreSQL execution port the library needs.
 *
 * The synchronous engine (`create`/`drop`/`refresh`/`sync`) talks to PostgreSQL
 * exclusively through this interface, so it never depends on Doctrine DBAL.
 * Ship a {@see \Th3Mouk\MaterializedView\Dbal\DbalConnection} to keep DBAL's
 * primary/replica routing, middlewares and profiling, or a
 * {@see \Th3Mouk\MaterializedView\Pdo\PdoConnection} to run on a bare PDO handle.
 *
 * Implementations MUST surface query failures as {@see DatabaseException} so the
 * engine can read the SQLSTATE (e.g. to skip a view whose dependency is missing).
 */
interface Connection
{
    /**
     * @param array<int<0, max>|string, mixed>         $params
     * @param array<int<0, max>|string, ParameterType> $types
     *
     * @return int number of affected rows
     *
     * @throws DatabaseException
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): int;

    /**
     * @param array<int<0, max>|string, mixed>         $params
     * @param array<int<0, max>|string, ParameterType> $types
     *
     * @return mixed the first column of the first row, or false when there is no row
     *
     * @throws DatabaseException
     */
    public function fetchOne(string $sql, array $params = [], array $types = []): mixed;

    /**
     * @param array<int<0, max>|string, mixed>         $params
     * @param array<int<0, max>|string, ParameterType> $types
     *
     * @return list<array<string, mixed>>
     *
     * @throws DatabaseException
     */
    public function fetchAllAssociative(string $sql, array $params = [], array $types = []): array;

    /**
     * @param array<int<0, max>|string, mixed>         $params
     * @param array<int<0, max>|string, ParameterType> $types
     *
     * @return array<string, mixed>|false the first row, or false when there is no row
     *
     * @throws DatabaseException
     */
    public function fetchAssociative(string $sql, array $params = [], array $types = []): array|false;

    /**
     * Runs the operation inside a single transaction, committing on success and
     * rolling back on any throwable. The operation receives this same connection.
     *
     * @template T
     *
     * @param Closure(self): T $operation
     *
     * @return T
     *
     * @throws DatabaseException
     */
    public function transactional(Closure $operation): mixed;

    /**
     * Pins subsequent statements to the primary node before any DDL or refresh.
     *
     * A no-op for connections without read/write splitting (e.g. a bare PDO handle).
     *
     * @throws DatabaseException
     */
    public function ensureConnectedToPrimary(): void;
}
