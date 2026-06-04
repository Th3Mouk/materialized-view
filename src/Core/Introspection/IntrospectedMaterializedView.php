<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Introspection;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class IntrospectedMaterializedView
{
    /**
     * @param list<IntrospectedIndex> $indexes
     */
    private function __construct(
        public MaterializedViewName $name,
        public string $definition,
        public bool $isPopulated,
        public ?string $comment,
        public array $indexes,
    ) {
    }

    /**
     * @param list<IntrospectedIndex> $indexes
     */
    public static function create(
        MaterializedViewName $name,
        string $definition,
        bool $isPopulated,
        ?string $comment,
        array $indexes,
    ): self {
        return new self(
            name: $name,
            definition: $definition,
            isPopulated: $isPopulated,
            comment: $comment,
            indexes: array_values($indexes),
        );
    }

    public function hasComment(): bool
    {
        return null !== $this->comment && '' !== $this->comment;
    }
}
