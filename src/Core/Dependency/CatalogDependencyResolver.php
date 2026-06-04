<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

use Doctrine\DBAL\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

final readonly class CatalogDependencyResolver
{
    private const string REWRITE_DEPENDENCY_QUERY = <<<'SQL'
        SELECT DISTINCT
            dependent_ns.nspname    AS dependent_schema,
            dependent_class.relname AS dependent_view,
            dependent_class.relkind AS dependent_relkind,
            referenced_ns.nspname    AS referenced_schema,
            referenced_class.relname AS referenced_view,
            referenced_class.relkind AS referenced_relkind
        FROM pg_depend dep
        JOIN pg_rewrite rw ON rw.oid = dep.objid
        JOIN pg_class dependent_class ON dependent_class.oid = rw.ev_class
        JOIN pg_namespace dependent_ns ON dependent_ns.oid = dependent_class.relnamespace
        JOIN pg_class referenced_class ON referenced_class.oid = dep.refobjid
        JOIN pg_namespace referenced_ns ON referenced_ns.oid = referenced_class.relnamespace
        WHERE dep.classid    = 'pg_rewrite'::regclass
          AND dep.refclassid = 'pg_class'::regclass
          AND dep.deptype    = 'n'
          AND dependent_class.oid <> referenced_class.oid
          AND dependent_class.relkind  IN ('m', 'v')
          AND referenced_class.relkind IN ('m', 'v')
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<CatalogDependencyEdge>
     */
    public function edges(): array
    {
        $rows = $this->connection->fetchAllAssociative(self::REWRITE_DEPENDENCY_QUERY);

        $edges = [];
        foreach ($rows as $row) {
            $edges[] = CatalogDependencyEdge::create(
                MaterializedViewName::create((string) $row['dependent_schema'], (string) $row['dependent_view']),
                (string) $row['dependent_relkind'],
                MaterializedViewName::create((string) $row['referenced_schema'], (string) $row['referenced_view']),
                (string) $row['referenced_relkind'],
            );
        }

        return $edges;
    }

    public function graphForManagedViews(MaterializedViewRegistry $registry): DependencyGraph
    {
        $edges = $this->edges();

        $managedNodes = [];
        foreach ($registry->all() as $definition) {
            $managedNodes[] = $definition->name()->qualifiedName();
        }

        $collapsedEdges = [];
        foreach ($this->collapsedMaterializedViewEdges($edges) as $edge) {
            $dependent = $edge['dependent'];
            $referenced = $edge['referenced'];

            if (!\in_array($dependent, $managedNodes, true)) {
                continue;
            }

            if (!\in_array($referenced, $managedNodes, true)) {
                continue;
            }

            $collapsedEdges[] = $edge;
        }

        return DependencyGraph::fromEdges($managedNodes, $collapsedEdges);
    }

    /**
     * @return list<string>
     */
    public function orderedForRefresh(MaterializedViewRegistry $registry): array
    {
        return $this->graphForManagedViews($registry)->topologicallySorted();
    }

    /**
     * @return list<string>
     */
    public function orderedForDrop(MaterializedViewRegistry $registry): array
    {
        return $this->graphForManagedViews($registry)->reverseTopologicallySorted();
    }

    /**
     * @param list<CatalogDependencyEdge> $edges
     *
     * @return list<array{dependent: string, referenced: string}>
     */
    private function collapsedMaterializedViewEdges(array $edges): array
    {
        $materializedReferencesByPlainView = $this->materializedReferencesByPlainView($edges);

        $collapsed = [];
        $seen = [];

        foreach ($edges as $edge) {
            if (!$edge->dependentIsMaterializedView()) {
                continue;
            }

            $dependent = $edge->dependent->qualifiedName();

            $referencedMaterializedViews = $edge->referencedIsMaterializedView()
                ? [$edge->referenced->qualifiedName()]
                : $this->resolvePlainViewReferences($edge->referenced->qualifiedName(), $materializedReferencesByPlainView);

            foreach ($referencedMaterializedViews as $referenced) {
                if ($dependent === $referenced) {
                    continue;
                }

                $key = $dependent."\0".$referenced;
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $collapsed[] = ['dependent' => $dependent, 'referenced' => $referenced];
            }
        }

        return $collapsed;
    }

    /**
     * @param list<CatalogDependencyEdge> $edges
     *
     * @return array<string, list<string>>
     */
    private function materializedReferencesByPlainView(array $edges): array
    {
        $references = [];

        foreach ($edges as $edge) {
            if (!$edge->dependentIsPlainView()) {
                continue;
            }

            $plainView = $edge->dependent->qualifiedName();
            $references[$plainView] ??= [];
            $references[$plainView][] = $edge->referenced->qualifiedName();
        }

        return $references;
    }

    /**
     * @param array<string, list<string>> $materializedReferencesByPlainView
     *
     * @return list<string>
     */
    private function resolvePlainViewReferences(
        string $plainView,
        array $materializedReferencesByPlainView,
    ): array {
        $materializedViews = [];
        $visitedPlainViews = [];
        $queue = [$plainView];

        while ([] !== $queue) {
            $current = array_shift($queue);

            if (isset($visitedPlainViews[$current])) {
                continue;
            }

            $visitedPlainViews[$current] = true;

            foreach ($materializedReferencesByPlainView[$current] ?? [] as $referenced) {
                if (isset($materializedReferencesByPlainView[$referenced])) {
                    $queue[] = $referenced;

                    continue;
                }

                $materializedViews[$referenced] = true;
            }
        }

        return array_keys($materializedViews);
    }
}
