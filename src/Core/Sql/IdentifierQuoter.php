<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sql;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

/**
 * PostgreSQL-native identifier and literal quoting.
 *
 * Quoting is deterministic for PostgreSQL, so the library does it in plain PHP
 * instead of going through a database driver — this keeps `Core` free of any
 * connection dependency. The output is byte-for-byte identical to Doctrine
 * DBAL's `PostgreSQLPlatform`.
 */
final readonly class IdentifierQuoter
{
    public function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public function quoteQualifiedName(MaterializedViewName $name): string
    {
        return $this->quoteIdentifier($name->schema).'.'.$this->quoteIdentifier($name->name);
    }

    /**
     * @param list<string> $columns
     */
    public function quoteColumnList(array $columns): string
    {
        return implode(', ', array_map($this->quoteIdentifier(...), $columns));
    }

    public function quoteStringLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
