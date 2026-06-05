<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\Hashing\DefinitionHasher;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Privilege\GrantStatementGenerator;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeSnapshotter;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sql\PostgreSqlMaterializedViewSqlGenerator;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewSynchronizer;
use Th3Mouk\MaterializedView\Core\Sync\MissingDependencyPolicy;
use Th3Mouk\MaterializedView\Core\Sync\SyncOptions;
use Th3Mouk\MaterializedView\Tests\Unit\Support\CollectingLogger;
use Th3Mouk\MaterializedView\Tests\Unit\Support\FakeConnectionFactory;

#[Group('sync')]
final class MaterializedViewSynchronizerTest extends TestCase
{
    private DefinitionHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = DefinitionHasher::create();
    }

    public function testCreatesAbsentViewWithIndexesThenComment(): void
    {
        $executed = [];
        $synchronizer = $this->synchronizer([], $executed);

        $outcome = $synchronizer->synchronize(
            MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]),
        );

        self::assertSame(['public.summary'], $outcome->created);
        self::assertSame([], $outcome->rebuilt);
        self::assertSame(
            [
                'DROP MATERIALIZED VIEW IF EXISTS "public"."summary"',
                'CREATE MATERIALIZED VIEW "public"."summary" AS SELECT 1 AS product_id, 2 AS score_id WITH NO DATA',
                'CREATE UNIQUE INDEX "ux_summary_identity" ON "public"."summary" ("product_id", "score_id")',
                'COMMENT ON MATERIALIZED VIEW "public"."summary" IS '.$this->quotedMarker($this->summaryDefinition()),
            ],
            $executed,
        );
    }

    public function testDoesNotAnalyzeWhenViewIsLeftUnpopulated(): void
    {
        $executed = [];
        $this->synchronizer([], $executed)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]),
        );

        foreach ($executed as $statement) {
            self::assertStringNotContainsString('ANALYZE', $statement);
        }
    }

    public function testSynchronousPolicyRefreshesAndThenAnalyzes(): void
    {
        $executed = [];
        $definition = $this->summaryDefinition()->withPopulationPolicy(PopulationPolicy::Synchronous);

        $this->synchronizer([], $executed)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$definition]),
        );

        self::assertSame(
            'REFRESH MATERIALIZED VIEW "public"."summary" WITH DATA',
            $executed[\count($executed) - 2],
        );
        self::assertSame('ANALYZE "public"."summary"', end($executed));
    }

    public function testRefreshInitialOptionForcesRefreshOnManualPolicy(): void
    {
        $executed = [];
        $this->synchronizer([], $executed)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]),
            SyncOptions::default()->withRefreshInitial(),
        );

        self::assertContains('REFRESH MATERIALIZED VIEW "public"."summary" WITH DATA', $executed);
        self::assertContains('ANALYZE "public"."summary"', $executed);
    }

    public function testRebuildsViewOnHashDrift(): void
    {
        $executed = [];
        $definition = $this->summaryDefinition();
        $staleComment = ManagementMarker::create('stale')->toJson();

        $outcome = $this->synchronizer(
            ['public' => [FakeConnectionFactory::matviewRow('public', 'summary', $staleComment)]],
            $executed,
        )->synchronize(MaterializedViewRegistry::fromDefinitions([$definition]));

        self::assertSame(['public.summary'], $outcome->rebuilt);
        self::assertSame([], $outcome->created);
        self::assertContains('DROP MATERIALIZED VIEW IF EXISTS "public"."summary"', $executed);
        self::assertContains(
            'COMMENT ON MATERIALIZED VIEW "public"."summary" IS '.$this->markerJson($definition),
            $executed,
        );
    }

    public function testLeavesUpToDateViewUntouched(): void
    {
        $executed = [];
        $definition = $this->summaryDefinition();
        $liveComment = ManagementMarker::create($this->hasher->hash($definition))->toJson();

        $outcome = $this->synchronizer(
            ['public' => [FakeConnectionFactory::matviewRow('public', 'summary', $liveComment)]],
            $executed,
        )->synchronize(MaterializedViewRegistry::fromDefinitions([$definition]));

        self::assertSame(['public.summary'], $outcome->upToDate);
        self::assertSame([], $executed);
    }

    public function testKeepsOrphanWhenPruneIsDisabled(): void
    {
        $executed = [];
        $orphanComment = ManagementMarker::create('orphan')->toJson();

        $outcome = $this->synchronizer(
            ['public' => [FakeConnectionFactory::matviewRow('public', 'legacy', $orphanComment)]],
            $executed,
        )->synchronize(MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]));

        self::assertSame(['public.legacy'], $outcome->orphansKept);
        self::assertSame([], $outcome->pruned);
        foreach ($executed as $statement) {
            self::assertStringNotContainsString('legacy', $statement);
        }
    }

    public function testPrunesOrphanWhenPruneIsEnabled(): void
    {
        $executed = [];
        $orphanComment = ManagementMarker::create('orphan')->toJson();

        $outcome = $this->synchronizer(
            ['public' => [FakeConnectionFactory::matviewRow('public', 'legacy', $orphanComment)]],
            $executed,
        )->synchronize(
            MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]),
            SyncOptions::default()->withPrune(),
        );

        self::assertSame(['public.legacy'], $outcome->pruned);
        self::assertSame([], $outcome->orphansKept);
        self::assertContains('DROP MATERIALIZED VIEW IF EXISTS "public"."legacy"', $executed);
    }

    public function testRebuildsExistingViewThatLostItsManagementComment(): void
    {
        $executed = [];
        $definition = $this->summaryDefinition();

        $outcome = $this->synchronizer(
            ['public' => [FakeConnectionFactory::matviewRow('public', 'summary', null)]],
            $executed,
        )->synchronize(MaterializedViewRegistry::fromDefinitions([$definition]));

        self::assertSame(['public.summary'], $outcome->rebuilt);
        self::assertSame([], $outcome->created);
        self::assertContains('DROP MATERIALIZED VIEW IF EXISTS "public"."summary"', $executed);
        self::assertContains(
            'COMMENT ON MATERIALIZED VIEW "public"."summary" IS '.$this->markerJson($definition),
            $executed,
        );
    }

    public function testRebuildsManagedDependentClosureDroppingInReverseOrderThenCreatingInOrder(): void
    {
        $base = MaterializedViewDefinition::create('public.base')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS id'));
        $rollup = MaterializedViewDefinition::create('public.rollup')
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM public.base'));

        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            ['public' => [
                FakeConnectionFactory::matviewRow('public', 'base', ManagementMarker::create('stale')->toJson()),
                FakeConnectionFactory::matviewRow('public', 'rollup', ManagementMarker::create($this->hasher->hash($rollup))->toJson()),
            ]],
            executed: $executed,
            dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.rollup', 'public.base')],
        );

        $outcome = $this->synchronizerFor($connection)
            ->synchronize(MaterializedViewRegistry::fromDefinitions([$base, $rollup]));

        self::assertSame(['public.base', 'public.rollup'], $outcome->rebuilt);

        $preDropRollup = array_search('DROP MATERIALIZED VIEW IF EXISTS "public"."rollup"', $executed, true);
        $preDropBase = array_search('DROP MATERIALIZED VIEW IF EXISTS "public"."base"', $executed, true);
        $createBase = array_search('CREATE MATERIALIZED VIEW "public"."base" AS SELECT 1 AS id WITH NO DATA', $executed, true);
        $createRollup = array_search('CREATE MATERIALIZED VIEW "public"."rollup" AS SELECT id FROM public.base WITH NO DATA', $executed, true);

        self::assertIsInt($preDropRollup);
        self::assertIsInt($preDropBase);
        self::assertIsInt($createBase);
        self::assertIsInt($createRollup);
        self::assertLessThan($preDropBase, $preDropRollup);
        self::assertLessThan($createBase, $preDropBase);
        self::assertLessThan($createRollup, $createBase);
    }

    public function testReplaysCapturedGrantsForEveryMemberOfADependentsClosureRebuild(): void
    {
        $base = MaterializedViewDefinition::create('public.base')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS id'));
        $rollup = MaterializedViewDefinition::create('public.rollup')
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM public.base'));

        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            ['public' => [
                FakeConnectionFactory::matviewRow('public', 'base', ManagementMarker::create('stale')->toJson()),
                FakeConnectionFactory::matviewRow('public', 'rollup', ManagementMarker::create($this->hasher->hash($rollup))->toJson()),
            ]],
            executed: $executed,
            dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.rollup', 'public.base')],
            grantRowsByView: [
                'public.base' => [FakeConnectionFactory::grantRow('reporting_ro', 'SELECT')],
                'public.rollup' => [
                    FakeConnectionFactory::grantRow('bi_admin', 'SELECT', isGrantable: true),
                    FakeConnectionFactory::grantRow('PUBLIC', 'SELECT'),
                ],
            ],
        );

        $outcome = $this->synchronizerFor($connection)
            ->synchronize(MaterializedViewRegistry::fromDefinitions([$base, $rollup]));

        self::assertSame(['public.base', 'public.rollup'], $outcome->rebuilt);
        self::assertContains('GRANT SELECT ON TABLE "public"."base" TO "reporting_ro"', $executed);
        self::assertContains('GRANT SELECT ON TABLE "public"."rollup" TO "bi_admin" WITH GRANT OPTION', $executed);
        self::assertContains('GRANT SELECT ON TABLE "public"."rollup" TO PUBLIC', $executed);

        $createBase = array_search('CREATE MATERIALIZED VIEW "public"."base" AS SELECT 1 AS id WITH NO DATA', $executed, true);
        $grantBase = array_search('GRANT SELECT ON TABLE "public"."base" TO "reporting_ro"', $executed, true);

        self::assertIsInt($createBase);
        self::assertIsInt($grantBase);
        self::assertLessThan($grantBase, $createBase);
    }

    public function testDoesNotReplayGrantsWhenPreserveExistingGrantsIsDisabled(): void
    {
        $base = MaterializedViewDefinition::create('public.base')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS id'));
        $rollup = MaterializedViewDefinition::create('public.rollup')
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM public.base'));

        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            ['public' => [
                FakeConnectionFactory::matviewRow('public', 'base', ManagementMarker::create('stale')->toJson()),
                FakeConnectionFactory::matviewRow('public', 'rollup', ManagementMarker::create($this->hasher->hash($rollup))->toJson()),
            ]],
            executed: $executed,
            dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.rollup', 'public.base')],
            grantRowsByView: [
                'public.base' => [FakeConnectionFactory::grantRow('reporting_ro', 'SELECT')],
            ],
        );

        $this->synchronizerFor($connection)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$base, $rollup]),
            SyncOptions::default()->withPreserveExistingGrants(false),
        );

        foreach ($executed as $statement) {
            self::assertStringNotContainsString('GRANT', $statement);
        }
    }

    public function testRebuildRefusesWhenAnUnmanagedDependentExistsUnderTheDefaultPolicy(): void
    {
        $definition = $this->summaryDefinition();
        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            ['public' => [FakeConnectionFactory::matviewRow('public', 'summary', ManagementMarker::create('stale')->toJson())]],
            executed: $executed,
            dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.bi_dashboard', 'public.summary')],
        );

        $this->expectException(UnmanagedDependentFound::class);

        $this->synchronizerFor($connection)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$definition]),
        );
    }

    public function testCascadeDropPolicyRebuildsThroughDropCascadeDespiteAnUnmanagedDependent(): void
    {
        $definition = $this->summaryDefinition();
        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            ['public' => [FakeConnectionFactory::matviewRow('public', 'summary', ManagementMarker::create('stale')->toJson())]],
            executed: $executed,
            dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.bi_dashboard', 'public.summary')],
        );

        $outcome = $this->synchronizerFor($connection)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$definition]),
            SyncOptions::default()->withDropDependentPolicy(DropDependentPolicy::Cascade),
        );

        self::assertSame(['public.summary'], $outcome->rebuilt);
        self::assertContains('DROP MATERIALIZED VIEW IF EXISTS "public"."summary" CASCADE', $executed);
    }

    public function testFailPolicyRethrowsWhenAReferencedTableIsMissing(): void
    {
        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            executed: $executed,
            createFailureSqlStateByView: ['public.summary' => '42P01'],
        );

        $this->expectException(DriverException::class);

        $this->synchronizerFor($connection)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]),
        );
    }

    public function testFailPolicyLogsTheFailingViewAndAnAggregateRollupBeforeRethrowing(): void
    {
        $executed = [];
        $logger = new CollectingLogger();
        $connection = FakeConnectionFactory::create(
            $this,
            executed: $executed,
            dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.broken', 'public.summary')],
            createFailureSqlStateByView: ['public.broken' => '42P01'],
        );

        $broken = MaterializedViewDefinition::create('public.broken')
            ->fromSql(InlineSqlSource::fromString('SELECT product_id AS id FROM public.summary'))
            ->withNoData();

        try {
            $this->synchronizerFor($connection, $logger)->synchronize(
                MaterializedViewRegistry::fromDefinitions([$broken, $this->summaryDefinition()]),
            );
            self::fail('Expected the missing dependency to propagate under the default fail policy.');
        } catch (DriverException) {
            // expected: under the fail policy the error propagates to the caller untouched.
        }

        $errors = $logger->recordsAtLevel(LogLevel::ERROR);
        self::assertCount(1, $errors);
        self::assertStringContainsString('aborted', $errors[0]['message']);
        self::assertSame('public.broken', $errors[0]['context']['view'] ?? null);
        self::assertSame(1, $errors[0]['context']['created'] ?? null);
        self::assertSame(0, $errors[0]['context']['remaining'] ?? null);
        self::assertArrayHasKey('sqlstate_reason', $errors[0]['context']);
    }

    public function testSkipPolicyLogsContinuesAndReportsSkippedWhileStillBuildingHealthyViews(): void
    {
        $executed = [];
        $logger = new CollectingLogger();
        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            executed: $executed,
            createFailureSqlStateByView: ['public.broken' => '42P01'],
        );

        $broken = MaterializedViewDefinition::create('public.broken')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS id FROM sure_schema.missing'))
            ->withNoData();

        $outcome = $this->synchronizerFor($connection, $logger)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$broken, $this->summaryDefinition()]),
            SyncOptions::default()->withMissingDependencyPolicy(MissingDependencyPolicy::Skip),
        );

        self::assertSame(['public.broken'], $outcome->skipped);
        self::assertSame(['public.summary'], $outcome->created);

        $warnings = $logger->recordsAtLevel(LogLevel::WARNING);
        self::assertCount(1, $warnings);
        self::assertSame('public.broken', $warnings[0]['context']['view'] ?? null);

        self::assertContains(
            'CREATE MATERIALIZED VIEW "public"."summary" AS SELECT 1 AS product_id, 2 AS score_id WITH NO DATA',
            $executed,
        );
    }

    public function testSkipPolicyAlsoSkipsWhenTheSchemaItselfIsMissing(): void
    {
        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            executed: $executed,
            createFailureSqlStateByView: ['public.summary' => '3F000'],
        );

        $outcome = $this->synchronizerFor($connection)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]),
            SyncOptions::default()->withMissingDependencyPolicy(MissingDependencyPolicy::Skip),
        );

        self::assertSame(['public.summary'], $outcome->skipped);
        self::assertSame([], $outcome->created);
    }

    public function testSkipPolicyStillRethrowsUnrelatedDatabaseErrors(): void
    {
        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            executed: $executed,
            createFailureSqlStateByView: ['public.summary' => '42703'],
        );

        $this->expectException(DriverException::class);

        $this->synchronizerFor($connection)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]),
            SyncOptions::default()->withMissingDependencyPolicy(MissingDependencyPolicy::Skip),
        );
    }

    public function testEmitsAnInfoSummaryWithOperationCountsOnCompletion(): void
    {
        $logger = new CollectingLogger();
        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            executed: $executed,
            createFailureSqlStateByView: ['public.broken' => '42P01'],
        );

        $broken = MaterializedViewDefinition::create('public.broken')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS id FROM sure_schema.missing'))
            ->withNoData();

        $this->synchronizerFor($connection, $logger)->synchronize(
            MaterializedViewRegistry::fromDefinitions([$broken, $this->summaryDefinition()]),
            SyncOptions::default()->withMissingDependencyPolicy(MissingDependencyPolicy::Skip),
        );

        $summaries = array_values(array_filter(
            $logger->recordsAtLevel(LogLevel::INFO),
            static fn (array $record): bool => str_contains($record['message'], 'completed'),
        ));

        self::assertCount(1, $summaries);
        self::assertSame(1, $summaries[0]['context']['created'] ?? null);
        self::assertSame(1, $summaries[0]['context']['skipped'] ?? null);
    }

    public function testEmitsANoticeWhenDroppingADependentClosureAheadOfRebuild(): void
    {
        $base = MaterializedViewDefinition::create('public.base')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS id'));
        $rollup = MaterializedViewDefinition::create('public.rollup')
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM public.base'));

        $logger = new CollectingLogger();
        $executed = [];
        $connection = FakeConnectionFactory::create(
            $this,
            ['public' => [
                FakeConnectionFactory::matviewRow('public', 'base', ManagementMarker::create('stale')->toJson()),
                FakeConnectionFactory::matviewRow('public', 'rollup', ManagementMarker::create($this->hasher->hash($rollup))->toJson()),
            ]],
            executed: $executed,
            dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.rollup', 'public.base')],
        );

        $this->synchronizerFor($connection, $logger)
            ->synchronize(MaterializedViewRegistry::fromDefinitions([$base, $rollup]));

        $notices = array_values(array_filter(
            $logger->recordsAtLevel(LogLevel::NOTICE),
            static fn (array $record): bool => str_contains($record['message'], 'dependent-closure rebuild'),
        ));

        self::assertNotEmpty($notices);
        self::assertContains('public.rollup', array_column(array_column($notices, 'context'), 'view'));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $matviewRowsBySchema
     * @param list<string>                              $executed
     */
    private function synchronizer(array $matviewRowsBySchema, array &$executed): MaterializedViewSynchronizer
    {
        return $this->synchronizerFor(
            FakeConnectionFactory::create($this, $matviewRowsBySchema, [], $executed),
        );
    }

    private function synchronizerFor(Connection&Stub $connection, ?LoggerInterface $logger = null): MaterializedViewSynchronizer
    {
        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);
        $resolver = new CatalogDependencyResolver($connection);

        return new MaterializedViewSynchronizer(
            connection: $connection,
            comparator: new MaterializedViewComparator($introspector, $this->hasher),
            dependencyResolver: $resolver,
            externalDependencyGuard: new ExternalDependencyGuard($resolver),
            privilegeSnapshotter: new PrivilegeSnapshotter($connection),
            grantStatementGenerator: new GrantStatementGenerator(IdentifierQuoter::forConnection($connection)),
            sqlGenerator: $this->sqlGenerator($connection),
            introspector: $introspector,
            hasher: $this->hasher,
            logger: $logger ?? new NullLogger(),
        );
    }

    private function sqlGenerator(Connection&Stub $connection): PostgreSqlMaterializedViewSqlGenerator
    {
        return new PostgreSqlMaterializedViewSqlGenerator(IdentifierQuoter::forConnection($connection));
    }

    private function markerJson(MaterializedViewDefinition $definition): string
    {
        return $this->quotedMarker($definition);
    }

    private function quotedMarker(MaterializedViewDefinition $definition): string
    {
        $marker = ManagementMarker::create(
            hash: $this->hasher->hash($definition),
            version: DefinitionHasher::CANONICALIZATION_VERSION,
            source: $definition->hasSqlSource() ? $definition->sqlSource()->identifier() : null,
        );

        return new PostgreSQLPlatform()->quoteStringLiteral($marker->toJson());
    }

    private function summaryDefinition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.summary')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS product_id, 2 AS score_id'))
            ->withNoData()
            ->withIndex(MaterializedViewIndex::unique(
                name: 'ux_summary_identity',
                columns: ['product_id', 'score_id'],
            ));
    }
}
