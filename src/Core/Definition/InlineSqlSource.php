<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Definition;

use Th3Mouk\MaterializedView\Core\Exception\CannotReadSqlSource;

final readonly class InlineSqlSource implements SqlSource
{
    private const string IDENTIFIER_PREFIX = 'inline:';

    private function __construct(
        private string $sql,
    ) {
    }

    public static function fromString(string $sql): self
    {
        if ('' === trim($sql)) {
            throw CannotReadSqlSource::emptyInlineSql();
        }

        return new self($sql);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    public function identifier(): string
    {
        return self::IDENTIFIER_PREFIX.hash('crc32b', $this->sql);
    }
}
