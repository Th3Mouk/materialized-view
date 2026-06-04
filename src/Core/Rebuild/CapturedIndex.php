<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

final readonly class CapturedIndex
{
    private function __construct(
        public string $name,
        public string $definition,
    ) {
    }

    public static function fromCatalogRow(string $name, string $definition): self
    {
        return new self($name, $definition);
    }
}
