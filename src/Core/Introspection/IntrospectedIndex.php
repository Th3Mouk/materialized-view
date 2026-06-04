<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Introspection;

final readonly class IntrospectedIndex
{
    private function __construct(
        public string $name,
        public string $definition,
    ) {
    }

    public static function create(string $name, string $definition): self
    {
        return new self(
            name: $name,
            definition: $definition,
        );
    }
}
