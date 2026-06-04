<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Hashing;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;

final readonly class DefinitionHasher
{
    public const int CANONICALIZATION_VERSION = 1;

    private const string HASH_ALGORITHM = 'sha256';

    public function __construct(
        private SqlCanonicalizer $canonicalizer,
    ) {
    }

    public static function create(): self
    {
        return new self(new SqlCanonicalizer());
    }

    public function hash(MaterializedViewDefinition $definition): string
    {
        return hash(self::HASH_ALGORITHM, $this->canonicalPayload($definition));
    }

    private function canonicalPayload(MaterializedViewDefinition $definition): string
    {
        return (string) json_encode(
            [
                'version' => self::CANONICALIZATION_VERSION,
                'name' => $definition->name()->qualifiedName(),
                'sql' => $this->canonicalizer->canonicalize($definition->sqlSource()->sql()),
                'rebuild_strategy' => $definition->rebuildStrategy()->value,
                'population_policy' => $definition->populationPolicy()->value,
                'create_with_data' => $definition->createWithData(),
                'indexes' => $this->canonicalIndexes($definition),
            ],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function canonicalIndexes(MaterializedViewDefinition $definition): array
    {
        return array_map(
            static fn (MaterializedViewIndex $index): array => [
                'name' => $index->name,
                'columns' => $index->columns,
                'unique' => $index->unique,
                'method' => $index->method,
                'include' => $index->include,
                'where' => $index->where,
                'concurrently' => $index->concurrently,
            ],
            $definition->indexes(),
        );
    }
}
