<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\Exception\MissingUniqueIndexForConcurrentRefresh;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshOptions;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Tests\Unit\Support\CollectingLogger;
use Th3Mouk\MaterializedView\Tests\Unit\Support\FakeConnectionFactory;

#[Group('manager')]
final class MaterializedViewManagerTest extends TestCase
{
    public function testCreateEmitsDropCreateIndexCommentSequence(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create($this, [], [], $executed),
        );

        $manager->create($this->definition());

        self::assertContains('DROP MATERIALIZED VIEW IF EXISTS "public"."summary"', $executed);
        self::assertContains(
            'CREATE MATERIALIZED VIEW "public"."summary" AS SELECT 1 AS product_id, 2 AS score_id WITH NO DATA',
            $executed,
        );
        self::assertContains(
            'CREATE UNIQUE INDEX "ux_summary_identity" ON "public"."summary" ("product_id", "score_id")',
            $executed,
        );

        $commentStatements = array_values(array_filter(
            $executed,
            static fn (string $statement): bool => str_starts_with($statement, 'COMMENT ON MATERIALIZED VIEW'),
        ));
        self::assertCount(1, $commentStatements);
        self::assertStringContainsString('"public"."summary"', $commentStatements[0]);
    }

    public function testDropEmitsDropIfExists(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create($this, [], [], $executed),
        );

        $manager->drop(MaterializedViewName::fromString('public.summary'));

        self::assertSame(['DROP MATERIALIZED VIEW IF EXISTS "public"."summary"'], $executed);
    }

    public function testDropRefusesWhenAnUnmanagedDependentExists(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                executed: $executed,
                dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.report', 'public.summary')],
            ),
        );

        $this->expectException(UnmanagedDependentFound::class);

        try {
            $manager->drop(MaterializedViewName::fromString('public.summary'));
        } finally {
            foreach ($executed as $statement) {
                self::assertStringNotContainsString('DROP MATERIALIZED VIEW', $statement);
            }
        }
    }

    public function testRefuseDropPolicyEmitsNoCascadeWhenAnUnmanagedDependentExists(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                executed: $executed,
                dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.report', 'public.summary')],
            ),
        );

        $this->expectException(UnmanagedDependentFound::class);

        try {
            $manager->drop(MaterializedViewName::fromString('public.summary'), dropDependentPolicy: DropDependentPolicy::Refuse);
        } finally {
            foreach ($executed as $statement) {
                self::assertStringNotContainsString('CASCADE', $statement);
            }
        }
    }

    public function testCascadeDropPolicyEmitsDropCascadeAndDoesNotThrowDespiteAnUnmanagedDependent(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                executed: $executed,
                dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.report', 'public.summary')],
            ),
        );

        $manager->drop(MaterializedViewName::fromString('public.summary'), dropDependentPolicy: DropDependentPolicy::Cascade);

        self::assertSame(['DROP MATERIALIZED VIEW IF EXISTS "public"."summary" CASCADE'], $executed);
    }

    public function testCreateRefusesWhenAnUnmanagedDependentExists(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                executed: $executed,
                dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.report', 'public.summary')],
            ),
        );

        $this->expectException(UnmanagedDependentFound::class);

        try {
            $manager->create($this->definition());
        } finally {
            foreach ($executed as $statement) {
                self::assertStringNotContainsString('DROP MATERIALIZED VIEW', $statement);
            }
        }
    }

    public function testNonConcurrentRefreshLocksRefreshesThenAnalyzes(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create($this, [], [], $executed),
        );

        $manager->refresh($this->definition());

        self::assertSame(
            [
                'SELECT pg_advisory_lock(?, ?)',
                'REFRESH MATERIALIZED VIEW "public"."summary"',
                'ANALYZE "public"."summary"',
            ],
            $executed,
        );
    }

    public function testRefreshWithNoDataDoesNotAnalyzeTheUnpopulatedView(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create($this, [], [], $executed),
        );

        $manager->refresh($this->definition(), RefreshOptions::default()->withNoData());

        self::assertContains('REFRESH MATERIALIZED VIEW "public"."summary" WITH NO DATA', $executed);
        foreach ($executed as $statement) {
            self::assertStringNotContainsString('ANALYZE', $statement);
        }
    }

    public function testRefreshAppliesAndResetsTimeouts(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create($this, [], [], $executed),
        );

        $manager->refresh(
            $this->definition(),
            RefreshOptions::default()->withLockTimeout('10s')->withStatementTimeout('30s'),
        );

        self::assertContains("SET lock_timeout = '10s'", $executed);
        self::assertContains("SET statement_timeout = '30s'", $executed);
        self::assertContains('SET lock_timeout = DEFAULT', $executed);
        self::assertContains('SET statement_timeout = DEFAULT', $executed);
    }

    public function testConcurrentRefreshRefusesWhenViewIsAbsent(): void
    {
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create($this),
        );

        $this->expectException(ViewNotPopulated::class);

        $manager->refresh($this->concurrentlyRefreshableDefinition(), RefreshOptions::concurrent());
    }

    public function testConcurrentRefreshRefusesWhenViewExistsButIsNotPopulated(): void
    {
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                ['public' => [FakeConnectionFactory::matviewRow('public', 'summary', null, isPopulated: false)]],
                fetchOneReturns: false,
            ),
        );

        $this->expectException(ViewNotPopulated::class);

        $manager->refresh($this->concurrentlyRefreshableDefinition(), RefreshOptions::concurrent());
    }

    public function testConcurrentRefreshRefusesWhenNoFullUniqueIndexIsDeclared(): void
    {
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                ['public' => [FakeConnectionFactory::matviewRow('public', 'summary', null)]],
                fetchOneReturns: true,
            ),
        );

        $this->expectException(MissingUniqueIndexForConcurrentRefresh::class);

        $manager->refresh($this->definitionWithoutUniqueIndex(), RefreshOptions::concurrent());
    }

    public function testConcurrentRefreshEmitsConcurrentlyWhenPreconditionsHold(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                ['public' => [FakeConnectionFactory::matviewRow('public', 'summary', null)]],
                executed: $executed,
                fetchOneReturns: true,
            ),
        );

        $manager->refresh($this->concurrentlyRefreshableDefinition(), RefreshOptions::concurrent());

        self::assertContains('REFRESH MATERIALIZED VIEW CONCURRENTLY "public"."summary"', $executed);
    }

    public function testSyncDelegatesToTheSynchronizerAndCreatesAbsentView(): void
    {
        $executed = [];
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create($this, [], [], $executed),
        );

        $outcome = $manager->sync($this->definition());

        self::assertSame(['public.summary'], $outcome->created);
        self::assertContains('DROP MATERIALIZED VIEW IF EXISTS "public"."summary"', $executed);
    }

    public function testRefreshEmitsAnInfoWithDurationMilliseconds(): void
    {
        $executed = [];
        $logger = new CollectingLogger();
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create($this, [], [], $executed),
            $logger,
        );

        $manager->refresh($this->definition());

        $refreshed = array_values(array_filter(
            $logger->recordsAtLevel(LogLevel::INFO),
            static fn (array $record): bool => str_contains($record['message'], 'Refreshed'),
        ));

        self::assertCount(1, $refreshed);
        self::assertSame('public.summary', $refreshed[0]['context']['view'] ?? null);
        self::assertArrayHasKey('duration_ms', $refreshed[0]['context']);
    }

    public function testRefreshAllEmitsStartedAndCompletedRollups(): void
    {
        $executed = [];
        $logger = new CollectingLogger();
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                executed: $executed,
                dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.rollup', 'public.base')],
            ),
            $logger,
        );

        $manager->refreshAll(MaterializedViewRegistry::fromDefinitions([
            $this->baseDefinition(),
            $this->rollupDefinition(),
        ]));

        $infos = $logger->recordsAtLevel(LogLevel::INFO);

        $started = array_values(array_filter(
            $infos,
            static fn (array $record): bool => str_contains($record['message'], 'refresh-all started'),
        ));
        self::assertCount(1, $started);
        self::assertSame(2, $started[0]['context']['total'] ?? null);

        $completed = array_values(array_filter(
            $infos,
            static fn (array $record): bool => str_contains($record['message'], 'refresh-all completed'),
        ));
        self::assertCount(1, $completed);
        self::assertSame(2, $completed[0]['context']['refreshed'] ?? null);
        self::assertSame(2, $completed[0]['context']['total'] ?? null);
    }

    public function testRefreshAllLogsTheFailingViewAndPartialRollupBeforeRethrowing(): void
    {
        $executed = [];
        $logger = new CollectingLogger();
        $manager = MaterializedViewManager::forConnection(
            FakeConnectionFactory::create(
                $this,
                executed: $executed,
                dependencyEdges: [FakeConnectionFactory::dependencyEdge('public.rollup', 'public.base')],
                refreshFailureSqlStateByView: ['public.rollup' => '42P01'],
            ),
            $logger,
        );

        try {
            $manager->refreshAll(MaterializedViewRegistry::fromDefinitions([
                $this->baseDefinition(),
                $this->rollupDefinition(),
            ]));
            self::fail('Expected the refresh failure to propagate to the caller.');
        } catch (DriverException) {
            // expected: refresh-all aborts at the failing view and the error propagates.
        }

        $errors = $logger->recordsAtLevel(LogLevel::ERROR);
        self::assertCount(1, $errors);
        self::assertStringContainsString('aborted', $errors[0]['message']);
        self::assertSame('public.rollup', $errors[0]['context']['view'] ?? null);
        self::assertSame(1, $errors[0]['context']['refreshed'] ?? null);
        self::assertSame(0, $errors[0]['context']['remaining'] ?? null);

        $completed = array_values(array_filter(
            $logger->recordsAtLevel(LogLevel::INFO),
            static fn (array $record): bool => str_contains($record['message'], 'refresh-all completed'),
        ));
        self::assertCount(0, $completed);
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.summary')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS product_id, 2 AS score_id'))
            ->withNoData()
            ->withIndex(MaterializedViewIndex::unique(
                name: 'ux_summary_identity',
                columns: ['product_id', 'score_id'],
            ));
    }

    private function concurrentlyRefreshableDefinition(): MaterializedViewDefinition
    {
        return $this->definition();
    }

    private function definitionWithoutUniqueIndex(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.summary')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS product_id'))
            ->withIndex(MaterializedViewIndex::regular(
                name: 'idx_summary_product',
                columns: ['product_id'],
            ));
    }

    private function baseDefinition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.base')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS id'));
    }

    private function rollupDefinition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.rollup')
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM public.base'));
    }
}
