<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Definition;

use Th3Mouk\MaterializedView\Core\Exception\InvalidIndexDefinition;

final readonly class MaterializedViewIndex
{
    /**
     * @param list<string> $columns
     * @param list<string> $include
     */
    private function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public ?string $method,
        public array $include,
        public ?string $where,
        public bool $concurrently,
    ) {
    }

    /**
     * @param list<string> $columns
     * @param list<string> $include
     */
    public static function unique(
        string $name,
        array $columns,
        ?string $method = null,
        array $include = [],
        bool $concurrently = false,
    ): self {
        return self::build(
            name: $name,
            columns: $columns,
            unique: true,
            method: $method,
            include: $include,
            where: null,
            concurrently: $concurrently,
        );
    }

    /**
     * @param list<string> $columns
     * @param list<string> $include
     */
    public static function regular(
        string $name,
        array $columns,
        ?string $method = null,
        array $include = [],
        ?string $where = null,
        bool $concurrently = false,
    ): self {
        return self::build(
            name: $name,
            columns: $columns,
            unique: false,
            method: $method,
            include: $include,
            where: $where,
            concurrently: $concurrently,
        );
    }

    public function coversAllRowsByColumnNamesOnly(): bool
    {
        return $this->unique && null === $this->where;
    }

    /**
     * @param list<string> $columns
     * @param list<string> $include
     */
    private static function build(
        string $name,
        array $columns,
        bool $unique,
        ?string $method,
        array $include,
        ?string $where,
        bool $concurrently,
    ): self {
        if ('' === trim($name)) {
            throw InvalidIndexDefinition::emptyName();
        }

        if ([] === $columns) {
            throw InvalidIndexDefinition::noColumns($name);
        }

        foreach ($columns as $column) {
            if ('' === trim($column)) {
                throw InvalidIndexDefinition::blankColumn($name);
            }
        }

        foreach ($include as $includeColumn) {
            if ('' === trim($includeColumn)) {
                throw InvalidIndexDefinition::blankIncludeColumn($name);
            }
        }

        if (null !== $method && '' === trim($method)) {
            throw InvalidIndexDefinition::blankMethod($name);
        }

        if (null !== $where && '' === trim($where)) {
            throw InvalidIndexDefinition::blankWhereClause($name);
        }

        return new self(
            name: $name,
            columns: array_values($columns),
            unique: $unique,
            method: $method,
            include: array_values($include),
            where: $where,
            concurrently: $concurrently,
        );
    }
}
