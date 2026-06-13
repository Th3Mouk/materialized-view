<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sql\QualifiedName;

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

    private const string DIRECT_DEPENDENTS_BY_OID_SQL = <<<'SQL'
        SELECT DISTINCT
            dependent.oid        AS oid,
            dependent_ns.nspname AS schema_name,
            dependent.relname    AS object_name,
            dependent.relkind    AS relkind,
            COALESCE(obj_description(dependent.oid, 'pg_class'), '') AS comment
        FROM pg_depend dep
        JOIN pg_rewrite rule ON rule.oid = dep.objid AND dep.classid = 'pg_rewrite'::regclass
        JOIN pg_class dependent ON dependent.oid = rule.ev_class
        JOIN pg_namespace dependent_ns ON dependent_ns.oid = dependent.relnamespace
        WHERE dep.refclassid = 'pg_class'::regclass
          AND dep.refobjid = :oid
          AND dep.deptype = 'n'
          AND dependent.oid <> dep.refobjid
          AND dependent.relkind IN ('m', 'v')
        ORDER BY dependent_ns.nspname, dependent.relname
        SQL;

    private const string DESCRIBE_RELATION_SQL = <<<'SQL'
        SELECT
            relation_ns.nspname AS schema_name,
            relation.relname    AS object_name,
            relation.relkind    AS relkind,
            COALESCE(obj_description(relation.oid, 'pg_class'), '') AS comment
        FROM pg_class relation
        JOIN pg_namespace relation_ns ON relation_ns.oid = relation.relnamespace
        WHERE relation.oid = :oid
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
     * Resolve, from the catalog, the transitive managed materialized-view dependents of an
     * arbitrary relation (typically a plain table named in a 2BP01 DDL conflict — which is
     * NOT a node in {@see graphForManagedViews}), in safe drop order (a view appears before
     * the views it depends on), together with any unmanaged dependents found in the closure.
     *
     * The walk is by OID: the seed is resolved once via to_regclass(), then pg_depend is
     * followed hop by hop. Plain-view intermediates are traversed so a matview → view →
     * matview chain is not missed; a managed matview reachable only beyond an unmanaged
     * object is still listed, and the unmanaged object is reported — the caller is the
     * single chokepoint that refuses the whole drop when any unmanaged dependent is present.
     *
     * When $includeSeed is true the seed itself is classified and added to the closure: this
     * is the 0A000 case, where the blocked object is the view named in the rewrite rule and
     * must itself be dropped (it is ordered last, since its own dependents depend on it).
     */
    public function resolveConflictClosure(QualifiedName $seed, bool $includeSeed = false): ConflictDropClosure
    {
        $seedOid = $this->resolveRelationOid($seed->render());

        if (null === $seedOid) {
            return ConflictDropClosure::empty();
        }

        /** @var array<int, true> $visited */
        $visited = [$seedOid => true];
        /** @var array<string, MaterializedViewName> $managed */
        $managed = [];
        /** @var array<string, true> $unmanaged */
        $unmanaged = [];
        /** @var list<array{dependent: string, referenced: string}> $edges */
        $edges = [];
        /** @var array<int, string|null> $managedNodeByOid */
        $managedNodeByOid = [$seedOid => null];

        if ($includeSeed) {
            $seedRelation = $this->describeRelation($seedOid);

            if (null !== $seedRelation) {
                $managedNodeByOid[$seedOid] = $this->record($seedRelation, $managed, $unmanaged);
            }
        }

        $queue = [$seedOid];

        while ([] !== $queue) {
            $currentOid = array_shift($queue);
            $parentManagedNode = $managedNodeByOid[$currentOid] ?? null;

            foreach ($this->directDependents($currentOid) as $dependent) {
                $node = $this->record($dependent, $managed, $unmanaged);

                if (null !== $node && null !== $parentManagedNode) {
                    $edges[] = ['dependent' => $node, 'referenced' => $parentManagedNode];
                }

                if (isset($visited[$dependent['oid']])) {
                    continue;
                }

                $visited[$dependent['oid']] = true;
                $managedNodeByOid[$dependent['oid']] = $node;
                $queue[] = $dependent['oid'];
            }
        }

        return ConflictDropClosure::of(
            $this->managedDropOrder($managed, $edges),
            $this->sortedNames($unmanaged),
        );
    }

    /**
     * Classify a catalog row and add it to the managed or unmanaged set. Returns the
     * managed node key (qualified name) when the row is a managed materialized view, or
     * null otherwise. Idempotent: keyed by qualified name.
     *
     * @param array{oid: int, schema: string, name: string, relkind: string, comment: string} $relation
     * @param array<string, MaterializedViewName>                                             $managed
     * @param array<string, true>                                                             $unmanaged
     */
    private function record(array $relation, array &$managed, array &$unmanaged): ?string
    {
        $qualifiedName = $relation['schema'].'.'.$relation['name'];

        if ('m' === $relation['relkind'] && ManagementMarker::isManagedComment($relation['comment'])) {
            $managed[$qualifiedName] ??= MaterializedViewName::create($relation['schema'], $relation['name']);

            return $qualifiedName;
        }

        $unmanaged[$qualifiedName] = true;

        return null;
    }

    /**
     * @return list<array{oid: int, schema: string, name: string, relkind: string, comment: string}>
     */
    private function directDependents(int $oid): array
    {
        $rows = $this->connection->fetchAllAssociative(self::DIRECT_DEPENDENTS_BY_OID_SQL, ['oid' => $oid]);

        $dependents = [];
        foreach ($rows as $row) {
            $dependents[] = [
                'oid' => (int) $row['oid'],
                'schema' => (string) $row['schema_name'],
                'name' => (string) $row['object_name'],
                'relkind' => (string) $row['relkind'],
                'comment' => (string) $row['comment'],
            ];
        }

        return $dependents;
    }

    /**
     * @return array{oid: int, schema: string, name: string, relkind: string, comment: string}|null
     */
    private function describeRelation(int $oid): ?array
    {
        $row = $this->connection->fetchAssociative(self::DESCRIBE_RELATION_SQL, ['oid' => $oid]);

        if (false === $row) {
            return null;
        }

        return [
            'oid' => $oid,
            'schema' => (string) $row['schema_name'],
            'name' => (string) $row['object_name'],
            'relkind' => (string) $row['relkind'],
            'comment' => (string) $row['comment'],
        ];
    }

    private function resolveRelationOid(string $relation): ?int
    {
        $oid = $this->connection->fetchOne('SELECT to_regclass(:relation)::oid', ['relation' => $relation]);

        if (null === $oid || false === $oid) {
            return null;
        }

        return (int) $oid;
    }

    /**
     * @param array<string, MaterializedViewName>                $managed
     * @param list<array{dependent: string, referenced: string}> $edges
     *
     * @return list<MaterializedViewName>
     */
    private function managedDropOrder(array $managed, array $edges): array
    {
        if ([] === $managed) {
            return [];
        }

        $managedEdges = [];
        foreach ($edges as $edge) {
            if (isset($managed[$edge['dependent']], $managed[$edge['referenced']])) {
                $managedEdges[] = $edge;
            }
        }

        $order = [];
        foreach (DependencyGraph::fromEdges(array_keys($managed), $managedEdges)->reverseTopologicallySorted() as $qualifiedName) {
            $order[] = $managed[$qualifiedName];
        }

        return $order;
    }

    /**
     * @param array<string, true> $unmanaged
     *
     * @return list<string>
     */
    private function sortedNames(array $unmanaged): array
    {
        $names = array_keys($unmanaged);
        sort($names);

        return $names;
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
