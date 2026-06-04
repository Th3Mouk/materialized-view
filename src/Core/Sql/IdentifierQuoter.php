<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class IdentifierQuoter
{
    private function __construct(
        private AbstractPlatform $platform,
    ) {
    }

    public static function forConnection(Connection $connection): self
    {
        return new self($connection->getDatabasePlatform());
    }

    public static function forPlatform(AbstractPlatform $platform): self
    {
        return new self($platform);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->platform->quoteSingleIdentifier($identifier);
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
        return $this->platform->quoteStringLiteral($value);
    }
}
