<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Registry;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\DuplicateViewDefinition;
use Th3Mouk\MaterializedView\Core\Exception\ViewDefinitionNotFound;

final readonly class MaterializedViewRegistry
{
    /**
     * @param array<string, MaterializedViewDefinition> $definitionsByName
     */
    private function __construct(
        private array $definitionsByName,
    ) {
    }

    /**
     * @param iterable<MaterializedViewDefinition> $definitions
     */
    public static function fromDefinitions(iterable $definitions): self
    {
        $definitionsByName = [];

        foreach ($definitions as $definition) {
            $key = $definition->name()->qualifiedName();

            if (isset($definitionsByName[$key])) {
                throw DuplicateViewDefinition::byName($key);
            }

            $definitionsByName[$key] = $definition;
        }

        return new self($definitionsByName);
    }

    /**
     * @return list<MaterializedViewDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitionsByName);
    }

    public function get(string|MaterializedViewName $name): MaterializedViewDefinition
    {
        $key = $this->keyFor($name);

        return $this->definitionsByName[$key]
            ?? throw ViewDefinitionNotFound::byName($key);
    }

    public function has(string|MaterializedViewName $name): bool
    {
        return isset($this->definitionsByName[$this->keyFor($name)]);
    }

    public function count(): int
    {
        return \count($this->definitionsByName);
    }

    private function keyFor(string|MaterializedViewName $name): string
    {
        return $name instanceof MaterializedViewName
            ? $name->qualifiedName()
            : MaterializedViewName::fromString($name)->qualifiedName();
    }
}
