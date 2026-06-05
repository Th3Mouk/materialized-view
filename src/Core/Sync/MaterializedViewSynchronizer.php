<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\DependencyGraph;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\Hashing\DefinitionHasher;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Privilege\GrantStatementGenerator;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeSnapshot;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeSnapshotter;
use Th3Mouk\MaterializedView\Core\Rebuild\DependentView;
use Th3Mouk\MaterializedView\Core\Rebuild\DropCreateRebuilder;
use Th3Mouk\MaterializedView\Core\Rebuild\RebuildContext;
use Th3Mouk\MaterializedView\Core\Rebuild\Rebuilder;
use Th3Mouk\MaterializedView\Core\Rebuild\SideBySideRebuilder;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sql\MissingDependencySqlState;
use Th3Mouk\MaterializedView\Core\Sql\PostgreSqlMaterializedViewSqlGenerator;

final readonly class MaterializedViewSynchronizer
{
    private const string DEFAULT_SWAP_TOKEN_PREFIX = 'sync';

    private LoggerInterface $logger;

    public function __construct(
        private Connection $connection,
        private MaterializedViewComparator $comparator,
        private CatalogDependencyResolver $dependencyResolver,
        private ExternalDependencyGuard $externalDependencyGuard,
        private PrivilegeSnapshotter $privilegeSnapshotter,
        private GrantStatementGenerator $grantStatementGenerator,
        private PostgreSqlMaterializedViewSqlGenerator $sqlGenerator,
        private PostgreSqlMaterializedViewIntrospector $introspector,
        private DefinitionHasher $hasher,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function synchronize(MaterializedViewRegistry $registry, ?SyncOptions $options = null): SyncOutcome
    {
        $options ??= SyncOptions::default();

        $plan = $this->comparator->compare($registry);
        $graph = $this->dependencyResolver->graphForManagedViews($registry);

        $buildOrder = $this->resolveBuildOrder($plan, $graph);

        $this->logger->info('Materialized view synchronisation started.', [
            'managed' => $registry->count(),
            'to_create' => \count($plan->toCreate()),
            'to_rebuild' => \count($plan->toRebuild()),
            'up_to_date' => \count($plan->upToDate()),
            'orphans' => \count($plan->orphans()),
        ]);

        foreach ($buildOrder as $qualifiedName) {
            $this->externalDependencyGuard->assertSafeToRebuild(
                $registry->get($qualifiedName)->name(),
                $registry,
                $options->dropDependentPolicy,
            );
        }

        $privilegeSnapshots = $this->snapshotPrivilegesForBuildOrder($buildOrder, $registry, $options);

        $this->dropClosureBeforeRebuild($buildOrder, $graph, $options);

        $created = [];
        $rebuilt = [];
        $skipped = [];

        foreach ($buildOrder as $qualifiedName) {
            $definition = $registry->get($qualifiedName);

            $isCreate = SyncAction::Create === $plan->forName($qualifiedName)?->action;

            $this->logger->debug('Building materialized view "{view}".', [
                'view' => $qualifiedName,
                'action' => $isCreate ? 'create' : 'rebuild',
                'strategy' => $definition->rebuildStrategy()->value,
            ]);

            try {
                $this->buildView($definition, $registry, $graph, $privilegeSnapshots[$qualifiedName], $options);
                $this->applyPopulationAndAnalyze($definition, $options);
            } catch (DbalException $exception) {
                if (!$this->shouldSkipMissingDependency($exception, $options)) {
                    throw $exception;
                }

                $skipped[] = $qualifiedName;
                $this->logger->warning(
                    'Skipped materialized view "{view}": a referenced schema or table is missing.',
                    ['view' => $qualifiedName, 'sqlstate_reason' => $exception->getMessage()],
                );

                continue;
            }

            if ($isCreate) {
                $created[] = $qualifiedName;
                $this->logger->info('Created materialized view "{view}".', ['view' => $qualifiedName]);

                continue;
            }

            $rebuilt[] = $qualifiedName;
            $this->logger->notice('Recreated existing materialized view "{view}".', ['view' => $qualifiedName]);
        }

        [$pruned, $orphansKept] = $this->handleOrphans($plan, $registry, $options);

        $outcome = SyncOutcome::of(
            created: $created,
            rebuilt: $rebuilt,
            upToDate: $this->namesOf($plan->upToDate()),
            pruned: $pruned,
            orphansKept: $orphansKept,
            skipped: $skipped,
        );

        $this->logger->info('Materialized view synchronisation completed.', [
            'created' => \count($outcome->created),
            'updated' => \count($outcome->rebuilt),
            'up_to_date' => \count($outcome->upToDate),
            'skipped' => \count($outcome->skipped),
            'pruned' => \count($outcome->pruned),
            'orphans_kept' => \count($outcome->orphansKept),
        ]);

        return $outcome;
    }

    private function shouldSkipMissingDependency(DbalException $exception, SyncOptions $options): bool
    {
        return MissingDependencyPolicy::Skip === $options->missingDependencyPolicy
            && MissingDependencySqlState::isMissingDependency($exception);
    }

    /**
     * @return list<string>
     */
    private function resolveBuildOrder(
        MaterializedViewComparisonPlan $plan,
        DependencyGraph $graph,
    ): array {
        $toBuild = [];

        foreach ($plan->toCreate() as $comparison) {
            $toBuild[$comparison->name->qualifiedName()] = true;
        }

        foreach ($plan->toRebuild() as $comparison) {
            $name = $comparison->name->qualifiedName();
            $toBuild[$name] = true;

            foreach ($graph->transitiveDependentsOf($name) as $dependent) {
                $toBuild[$dependent] = true;
            }
        }

        return array_values(array_filter(
            $graph->topologicallySorted(),
            static fn (string $node): bool => isset($toBuild[$node]),
        ));
    }

    /**
     * @param list<string> $buildOrder
     */
    private function dropClosureBeforeRebuild(array $buildOrder, DependencyGraph $graph, SyncOptions $options): void
    {
        $inBuildSet = array_fill_keys($buildOrder, true);

        $closureMembers = [];
        foreach ($buildOrder as $qualifiedName) {
            foreach ($graph->directDependentsOf($qualifiedName) as $dependent) {
                if (isset($inBuildSet[$dependent])) {
                    $closureMembers[$qualifiedName] = true;
                    $closureMembers[$dependent] = true;
                }
            }
        }

        if ([] === $closureMembers) {
            return;
        }

        $cascade = DropDependentPolicy::Cascade === $options->dropDependentPolicy;

        $reverseOrder = array_reverse($buildOrder);
        foreach ($reverseOrder as $qualifiedName) {
            if (!isset($closureMembers[$qualifiedName])) {
                continue;
            }

            $this->logger->notice('Dropping materialized view "{view}" ahead of a dependent-closure rebuild.', [
                'view' => $qualifiedName,
                'cascade' => $cascade,
            ]);

            $this->connection->executeStatement(
                $this->sqlGenerator->drop(MaterializedViewName::fromString($qualifiedName), true, $cascade),
            );
        }
    }

    private function buildView(
        MaterializedViewDefinition $definition,
        MaterializedViewRegistry $registry,
        DependencyGraph $graph,
        PrivilegeSnapshot $snapshot,
        SyncOptions $options,
    ): void {
        $context = RebuildContext::create(
            managementComment: $this->managementComment($definition),
            grantStatements: $this->grantStatementGenerator->forSnapshot($snapshot),
            dependents: $this->dependentsOf($definition->name(), $registry, $graph, $options),
            dropCascade: DropDependentPolicy::Cascade === $options->dropDependentPolicy,
        );

        $this->rebuilderFor($definition, $options)->rebuild($definition, $context);
    }

    /**
     * @param list<string> $buildOrder
     *
     * @return array<string, PrivilegeSnapshot>
     */
    private function snapshotPrivilegesForBuildOrder(
        array $buildOrder,
        MaterializedViewRegistry $registry,
        SyncOptions $options,
    ): array {
        $snapshots = [];

        foreach ($buildOrder as $qualifiedName) {
            $snapshots[$qualifiedName] = $this->snapshotPrivileges(
                $registry->get($qualifiedName)->name(),
                $options,
            );
        }

        return $snapshots;
    }

    private function snapshotPrivileges(MaterializedViewName $name, SyncOptions $options): PrivilegeSnapshot
    {
        if (!$options->preserveExistingGrants) {
            return PrivilegeSnapshot::empty($name);
        }

        if (!$this->introspector->exists($name)) {
            return PrivilegeSnapshot::empty($name);
        }

        return $this->privilegeSnapshotter->capture($name);
    }

    /**
     * @return list<DependentView>
     */
    private function dependentsOf(
        MaterializedViewName $name,
        MaterializedViewRegistry $registry,
        DependencyGraph $graph,
        SyncOptions $options,
    ): array {
        $dependents = [];

        foreach ($graph->directDependentsOf($name->qualifiedName()) as $dependent) {
            $dependents[] = DependentView::managed(MaterializedViewName::fromString($dependent));
        }

        if (DropDependentPolicy::Cascade === $options->dropDependentPolicy) {
            return $dependents;
        }

        foreach ($this->externalDependencyGuard->unmanagedDependentsOf($name, $registry) as $dependent) {
            $dependents[] = DependentView::unmanaged(MaterializedViewName::fromString($dependent));
        }

        return $dependents;
    }

    private function rebuilderFor(MaterializedViewDefinition $definition, SyncOptions $options): Rebuilder
    {
        if (RebuildStrategy::SideBySide === $definition->rebuildStrategy()) {
            return new SideBySideRebuilder($this->connection, $this->swapToken($definition, $options), $this->logger);
        }

        return new DropCreateRebuilder($this->connection, $this->logger);
    }

    private function swapToken(MaterializedViewDefinition $definition, SyncOptions $options): string
    {
        if (null !== $options->sideBySideSwapToken) {
            return $options->sideBySideSwapToken;
        }

        return self::DEFAULT_SWAP_TOKEN_PREFIX.substr($this->hasher->hash($definition), 0, 12);
    }

    private function applyPopulationAndAnalyze(MaterializedViewDefinition $definition, SyncOptions $options): void
    {
        $populated = $this->forceInitialRefreshIfRequested($definition, $options);

        if ($options->analyzeAfterSync && $populated) {
            $this->connection->executeStatement($this->analyzeStatement($definition->name()));
        }
    }

    private function forceInitialRefreshIfRequested(
        MaterializedViewDefinition $definition,
        SyncOptions $options,
    ): bool {
        $populatedByRebuilder = $definition->populationPolicy()->refreshesDuringSync()
            || $definition->createWithData();

        if ($populatedByRebuilder) {
            return true;
        }

        if (!$options->refreshInitial) {
            return false;
        }

        $this->connection->executeStatement($this->refreshWithDataStatement($definition->name()));

        return true;
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function handleOrphans(
        MaterializedViewComparisonPlan $plan,
        MaterializedViewRegistry $registry,
        SyncOptions $options,
    ): array {
        $orphans = $plan->orphans();

        if ([] === $orphans) {
            return [[], []];
        }

        if (!$options->prune) {
            foreach ($orphans as $orphan) {
                $this->logger->warning(
                    'Managed-but-undeclared materialized view "{view}" kept; run prune to remove it.',
                    ['view' => $orphan->name->qualifiedName()],
                );
            }

            return [[], $this->namesOf($orphans)];
        }

        $orphanByName = [];
        foreach ($orphans as $orphan) {
            $orphanByName[$orphan->name->qualifiedName()] = $orphan->name;
        }

        $cascade = DropDependentPolicy::Cascade === $options->dropDependentPolicy;

        $pruned = [];
        foreach ($this->pruneOrder($orphanByName) as $qualifiedName) {
            $name = $orphanByName[$qualifiedName];

            $this->externalDependencyGuard->assertSafeToDrop($name, $registry, $options->dropDependentPolicy);
            $this->connection->executeStatement($this->sqlGenerator->drop($name, true, $cascade));

            $pruned[] = $qualifiedName;
            $this->logger->info('Pruned managed orphan materialized view "{view}".', ['view' => $qualifiedName]);
        }

        return [$pruned, []];
    }

    /**
     * @param array<string, MaterializedViewName> $orphanByName
     *
     * @return list<string>
     */
    private function pruneOrder(array $orphanByName): array
    {
        $edges = [];
        foreach ($this->dependencyResolver->edges() as $edge) {
            $dependent = $edge->dependent->qualifiedName();
            $referenced = $edge->referenced->qualifiedName();

            if (!isset($orphanByName[$dependent], $orphanByName[$referenced])) {
                continue;
            }

            $edges[] = ['dependent' => $dependent, 'referenced' => $referenced];
        }

        return DependencyGraph::fromEdges(array_keys($orphanByName), $edges)->reverseTopologicallySorted();
    }

    private function managementComment(MaterializedViewDefinition $definition): string
    {
        return $this->marker($definition)->toJson();
    }

    private function marker(MaterializedViewDefinition $definition): ManagementMarker
    {
        return ManagementMarker::create(
            hash: $this->hasher->hash($definition),
            version: DefinitionHasher::CANONICALIZATION_VERSION,
            source: $definition->hasSqlSource() ? $definition->sqlSource()->identifier() : null,
        );
    }

    private function analyzeStatement(MaterializedViewName $name): string
    {
        return \sprintf(
            'ANALYZE %s.%s',
            $this->connection->quoteSingleIdentifier($name->schema),
            $this->connection->quoteSingleIdentifier($name->name),
        );
    }

    private function refreshWithDataStatement(MaterializedViewName $name): string
    {
        return \sprintf(
            'REFRESH MATERIALIZED VIEW %s.%s WITH DATA',
            $this->connection->quoteSingleIdentifier($name->schema),
            $this->connection->quoteSingleIdentifier($name->name),
        );
    }

    /**
     * @param list<MaterializedViewComparison> $comparisons
     *
     * @return list<string>
     */
    private function namesOf(array $comparisons): array
    {
        return array_map(
            static fn (MaterializedViewComparison $comparison): string => $comparison->name->qualifiedName(),
            $comparisons,
        );
    }
}
