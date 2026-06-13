<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Database;

use RuntimeException;
use Throwable;

/**
 * Backend-agnostic wrapper for a failed PostgreSQL statement.
 *
 * {@see Connection} adapters translate their native failure
 * (`Doctrine\DBAL\Exception`, `PDOException`, …) into this type and carry the
 * five-character SQLSTATE so the engine can reason about the error without
 * knowing which backend produced it.
 */
class DatabaseException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?string $sqlState = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The five-character SQLSTATE of the underlying failure, when available.
     */
    public function sqlState(): ?string
    {
        return $this->sqlState;
    }
}
