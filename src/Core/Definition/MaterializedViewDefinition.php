<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Definition;

use Th3Mouk\MaterializedView\Core\Exception\MissingSqlSource;

final readonly class MaterializedViewDefinition
{
    /**
     * @param list<MaterializedViewIndex> $indexes
     * @param list<MaterializedViewName>  $manualDependencies
     */
    private function __construct(
        private MaterializedViewName $name,
        private ?SqlSource $sqlSource,
        private bool $createWithData,
        private RebuildStrategy $rebuildStrategy,
        private PopulationPolicy $populationPolicy,
        private array $indexes,
        private array $manualDependencies,
    ) {
    }

    public static function create(string $name): self
    {
        return new self(
            name: MaterializedViewName::fromString($name),
            sqlSource: null,
            createWithData: false,
            rebuildStrategy: RebuildStrategy::DropCreate,
            populationPolicy: PopulationPolicy::Manual,
            indexes: [],
            manualDependencies: [],
        );
    }

    public function fromSql(SqlSource $sqlSource): self
    {
        return new self(
            name: $this->name,
            sqlSource: $sqlSource,
            createWithData: $this->createWithData,
            rebuildStrategy: $this->rebuildStrategy,
            populationPolicy: $this->populationPolicy,
            indexes: $this->indexes,
            manualDependencies: $this->manualDependencies,
        );
    }

    public function withNoData(): self
    {
        return new self(
            name: $this->name,
            sqlSource: $this->sqlSource,
            createWithData: false,
            rebuildStrategy: $this->rebuildStrategy,
            populationPolicy: $this->populationPolicy,
            indexes: $this->indexes,
            manualDependencies: $this->manualDependencies,
        );
    }

    public function withData(): self
    {
        return new self(
            name: $this->name,
            sqlSource: $this->sqlSource,
            createWithData: true,
            rebuildStrategy: $this->rebuildStrategy,
            populationPolicy: $this->populationPolicy,
            indexes: $this->indexes,
            manualDependencies: $this->manualDependencies,
        );
    }

    public function withRebuildStrategy(RebuildStrategy $rebuildStrategy): self
    {
        return new self(
            name: $this->name,
            sqlSource: $this->sqlSource,
            createWithData: $this->createWithData,
            rebuildStrategy: $rebuildStrategy,
            populationPolicy: $this->populationPolicy,
            indexes: $this->indexes,
            manualDependencies: $this->manualDependencies,
        );
    }

    public function withPopulationPolicy(PopulationPolicy $populationPolicy): self
    {
        return new self(
            name: $this->name,
            sqlSource: $this->sqlSource,
            createWithData: $this->createWithData,
            rebuildStrategy: $this->rebuildStrategy,
            populationPolicy: $populationPolicy,
            indexes: $this->indexes,
            manualDependencies: $this->manualDependencies,
        );
    }

    public function withIndex(MaterializedViewIndex $index): self
    {
        return new self(
            name: $this->name,
            sqlSource: $this->sqlSource,
            createWithData: $this->createWithData,
            rebuildStrategy: $this->rebuildStrategy,
            populationPolicy: $this->populationPolicy,
            indexes: [...$this->indexes, $index],
            manualDependencies: $this->manualDependencies,
        );
    }

    public function dependsOn(string ...$qualifiedNames): self
    {
        $dependencies = $this->manualDependencies;

        foreach ($qualifiedNames as $qualifiedName) {
            $dependencies[] = MaterializedViewName::fromString($qualifiedName);
        }

        return new self(
            name: $this->name,
            sqlSource: $this->sqlSource,
            createWithData: $this->createWithData,
            rebuildStrategy: $this->rebuildStrategy,
            populationPolicy: $this->populationPolicy,
            indexes: $this->indexes,
            manualDependencies: $dependencies,
        );
    }

    public function name(): MaterializedViewName
    {
        return $this->name;
    }

    public function hasSqlSource(): bool
    {
        return null !== $this->sqlSource;
    }

    public function sqlSource(): SqlSource
    {
        if (null === $this->sqlSource) {
            throw MissingSqlSource::forView($this->name);
        }

        return $this->sqlSource;
    }

    public function createWithData(): bool
    {
        return $this->createWithData;
    }

    public function rebuildStrategy(): RebuildStrategy
    {
        return $this->rebuildStrategy;
    }

    public function populationPolicy(): PopulationPolicy
    {
        return $this->populationPolicy;
    }

    /**
     * @return list<MaterializedViewIndex>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return list<MaterializedViewName>
     */
    public function manualDependencies(): array
    {
        return $this->manualDependencies;
    }
}
