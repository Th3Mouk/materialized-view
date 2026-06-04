<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sync;

use JsonException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Hashing\DefinitionHasher;
use Th3Mouk\MaterializedView\Core\Introspection\IntrospectedMaterializedView;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;

final readonly class MaterializedViewComparator
{
    public function __construct(
        private PostgreSqlMaterializedViewIntrospector $introspector,
        private DefinitionHasher $hasher,
    ) {
    }

    public function compare(MaterializedViewRegistry $registry): MaterializedViewComparisonPlan
    {
        $liveByName = $this->introspectManagedSchemas($registry);

        $comparisons = [];
        $declaredNames = [];

        foreach ($registry->all() as $definition) {
            $qualifiedName = $definition->name()->qualifiedName();
            $declaredNames[$qualifiedName] = true;

            $comparisons[] = $this->compareDeclared($definition, $liveByName[$qualifiedName] ?? null);
        }

        foreach ($liveByName as $qualifiedName => $liveState) {
            if (isset($declaredNames[$qualifiedName])) {
                continue;
            }

            if (!$liveState->hasComment() || null === $this->extractHash($liveState->comment)) {
                continue;
            }

            $comparisons[] = MaterializedViewComparison::orphan($liveState);
        }

        return MaterializedViewComparisonPlan::of($comparisons);
    }

    public function compareOne(
        MaterializedViewDefinition $definition,
        ?IntrospectedMaterializedView $liveState,
    ): MaterializedViewComparison {
        return $this->compareDeclared($definition, $liveState);
    }

    private function compareDeclared(
        MaterializedViewDefinition $definition,
        ?IntrospectedMaterializedView $liveState,
    ): MaterializedViewComparison {
        $desiredHash = $this->hasher->hash($definition);

        if (null === $liveState) {
            return MaterializedViewComparison::create($definition, $desiredHash);
        }

        $storedHash = $liveState->hasComment() ? $this->extractHash($liveState->comment) : null;

        if ($storedHash === $desiredHash) {
            return MaterializedViewComparison::upToDate($definition, $liveState, $desiredHash);
        }

        return MaterializedViewComparison::rebuild($definition, $liveState, $desiredHash);
    }

    /**
     * @return array<string, IntrospectedMaterializedView>
     */
    private function introspectManagedSchemas(MaterializedViewRegistry $registry): array
    {
        $schemas = [];
        foreach ($registry->all() as $definition) {
            $schemas[$definition->name()->schema] = true;
        }

        $liveByName = [];
        foreach (array_keys($schemas) as $schema) {
            foreach ($this->introspector->introspectSchema($schema) as $liveState) {
                $liveByName[$liveState->name->qualifiedName()] = $liveState;
            }
        }

        return $liveByName;
    }

    private function extractHash(?string $comment): ?string
    {
        if (null === $comment || '' === $comment) {
            return null;
        }

        try {
            $decoded = json_decode($comment, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $marker = $decoded[ManagementMarker::MARKER_KEY] ?? null;

        if (!\is_array($marker)) {
            return null;
        }

        $hash = $marker['hash'] ?? null;

        return \is_string($hash) && '' !== $hash ? $hash : null;
    }
}
