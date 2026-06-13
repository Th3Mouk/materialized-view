<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Pdo;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;
use Th3Mouk\MaterializedView\Core\Database\ParameterType;
use Throwable;

/**
 * {@see Connection} adapter backed by a bare PDO handle.
 *
 * Lets the library run without Doctrine. Pass a PDO connected to PostgreSQL
 * (the `pdo_pgsql` driver). There is no primary/replica routing, middleware or
 * profiling — use {@see \Th3Mouk\MaterializedView\Dbal\DbalConnection} for those.
 */
final readonly class PdoConnection implements Connection
{
    public function __construct(
        private PDO $pdo,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        return $this->execute($sql, $params, $types)->rowCount();
    }

    public function fetchOne(string $sql, array $params = [], array $types = []): mixed
    {
        return $this->execute($sql, $params, $types)->fetchColumn(0);
    }

    public function fetchAllAssociative(string $sql, array $params = [], array $types = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->execute($sql, $params, $types)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function fetchAssociative(string $sql, array $params = [], array $types = []): array|false
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->execute($sql, $params, $types)->fetch(PDO::FETCH_ASSOC);

        return $row;
    }

    public function transactional(Closure $operation): mixed
    {
        $this->guard(fn (): bool => $this->pdo->beginTransaction());

        try {
            $result = $operation($this);
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }

        $this->guard(fn (): bool => $this->pdo->commit());

        return $result;
    }

    public function ensureConnectedToPrimary(): void
    {
        // A bare PDO handle has no read/replica routing; nothing to pin.
    }

    /**
     * @param array<int<0, max>|string, mixed>         $params
     * @param array<int<0, max>|string, ParameterType> $types
     */
    private function execute(string $sql, array $params, array $types): PDOStatement
    {
        return $this->guard(function () use ($sql, $params, $types): PDOStatement {
            $statement = $this->pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $statement->bindValue(self::placeholder($key), $value, self::pdoType($types[$key] ?? null, $value));
            }

            $statement->execute();

            return $statement;
        });
    }

    private static function placeholder(int|string $key): int|string
    {
        if (\is_int($key)) {
            return $key + 1;
        }

        return str_starts_with($key, ':') ? $key : ':'.$key;
    }

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    private function guard(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException $exception) {
            throw self::wrap($exception);
        }
    }

    private static function pdoType(?ParameterType $type, mixed $value): int
    {
        return match ($type) {
            ParameterType::Integer => PDO::PARAM_INT,
            ParameterType::Boolean => PDO::PARAM_BOOL,
            ParameterType::String => PDO::PARAM_STR,
            null => self::inferType($value),
        };
    }

    private static function inferType(mixed $value): int
    {
        return match (true) {
            null === $value => PDO::PARAM_NULL,
            \is_int($value) => PDO::PARAM_INT,
            \is_bool($value) => PDO::PARAM_BOOL,
            default => PDO::PARAM_STR,
        };
    }

    private static function wrap(PDOException $exception): DatabaseException
    {
        return new DatabaseException($exception->getMessage(), self::sqlStateOf($exception), $exception);
    }

    private static function sqlStateOf(PDOException $exception): ?string
    {
        $errorInfo = $exception->errorInfo;
        $sqlState = \is_array($errorInfo) && isset($errorInfo[0])
            ? (string) $errorInfo[0]
            : (string) $exception->getCode();

        return '' !== $sqlState && '00000' !== $sqlState ? $sqlState : null;
    }
}
