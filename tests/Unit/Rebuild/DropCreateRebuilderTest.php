<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Rebuild;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\Rebuild\DependentView;
use Th3Mouk\MaterializedView\Core\Rebuild\DropCreateRebuilder;
use Th3Mouk\MaterializedView\Core\Rebuild\RebuildContext;

#[Group('rebuild')]
final class DropCreateRebuilderTest extends TestCase
{
    public function testStrategyIsDropCreate(): void
    {
        $rebuilder = new DropCreateRebuilder(RebuildTestConnectionFactory::create($this));

        self::assertSame(RebuildStrategy::DropCreate, $rebuilder->strategy());
    }

    public function testEmitsDropCreateIndexesCommentThenGrantsInOrder(): void
    {
        $rebuilder = new DropCreateRebuilder(RebuildTestConnectionFactory::create($this));

        $plan = $rebuilder->planFor(
            $this->definition(),
            RebuildContext::create(
                managementComment: '{"th3mouk_materialized_view":{"hash":"abc123"}}',
                grantStatements: [
                    'GRANT SELECT ON public.summary TO bi_reader',
                    'GRANT SELECT ON public.summary TO app_ro',
                ],
            ),
        );

        self::assertSame(RebuildStrategy::DropCreate, $plan->strategy);
        self::assertSame(
            [
                'DROP MATERIALIZED VIEW IF EXISTS "public"."summary"',
                'CREATE MATERIALIZED VIEW "public"."summary" AS SELECT 1 AS product_id, 2 AS score_id, 3 AS value WITH NO DATA',
                'CREATE UNIQUE INDEX "ux_summary_identity" ON "public"."summary" ("product_id", "score_id")',
                'CREATE INDEX "idx_summary_score" ON "public"."summary" ("score_id")',
                'COMMENT ON MATERIALIZED VIEW "public"."summary" IS \'{"th3mouk_materialized_view":{"hash":"abc123"}}\'',
                'GRANT SELECT ON public.summary TO bi_reader',
                'GRANT SELECT ON public.summary TO app_ro',
            ],
            $plan->statements(),
        );
    }

    public function testAppendsRefreshWhenSynchronousAndCreatedWithNoData(): void
    {
        $rebuilder = new DropCreateRebuilder(RebuildTestConnectionFactory::create($this));

        $plan = $rebuilder->planFor(
            $this->definition()->withPopulationPolicy(PopulationPolicy::Synchronous),
            RebuildContext::create(managementComment: '{}'),
        );

        $statements = $plan->statements();

        self::assertSame(
            'REFRESH MATERIALIZED VIEW "public"."summary" WITH DATA',
            end($statements),
        );
    }

    public function testDoesNotAppendRefreshWhenSynchronousButCreatedWithData(): void
    {
        $rebuilder = new DropCreateRebuilder(RebuildTestConnectionFactory::create($this));

        $plan = $rebuilder->planFor(
            $this->definition()->withData()->withPopulationPolicy(PopulationPolicy::Synchronous),
            RebuildContext::create(managementComment: '{}'),
        );

        foreach ($plan->statements() as $statement) {
            self::assertStringNotContainsString('REFRESH MATERIALIZED VIEW', $statement);
        }
    }

    public function testDoesNotAppendRefreshForManualPolicy(): void
    {
        $rebuilder = new DropCreateRebuilder(RebuildTestConnectionFactory::create($this));

        $plan = $rebuilder->planFor(
            $this->definition(),
            RebuildContext::create(managementComment: '{}'),
        );

        foreach ($plan->statements() as $statement) {
            self::assertStringNotContainsString('REFRESH MATERIALIZED VIEW', $statement);
        }
    }

    public function testDropCascadeContextEmitsDropMaterializedViewCascade(): void
    {
        $rebuilder = new DropCreateRebuilder(RebuildTestConnectionFactory::create($this));

        $plan = $rebuilder->planFor(
            $this->definition(),
            RebuildContext::create(managementComment: '{}', dropCascade: true),
        );

        self::assertSame(
            'DROP MATERIALIZED VIEW IF EXISTS "public"."summary" CASCADE',
            $plan->statements()[0],
        );
    }

    public function testRefusesRebuildWhenAnUnmanagedDependentExists(): void
    {
        $rebuilder = new DropCreateRebuilder(RebuildTestConnectionFactory::create($this));

        $this->expectException(UnmanagedDependentFound::class);
        $this->expectExceptionMessage('public.summary');

        $rebuilder->planFor(
            $this->definition(),
            RebuildContext::create(
                managementComment: '{}',
                dependents: [
                    DependentView::unmanaged(MaterializedViewName::fromString('public.bi_dashboard')),
                ],
            ),
        );
    }

    public function testAllowsRebuildWhenAllDependentsAreManaged(): void
    {
        $rebuilder = new DropCreateRebuilder(RebuildTestConnectionFactory::create($this));

        $plan = $rebuilder->planFor(
            $this->definition(),
            RebuildContext::create(
                managementComment: '{}',
                dependents: [
                    DependentView::managed(MaterializedViewName::fromString('public.summary_rollup')),
                ],
            ),
        );

        self::assertSame('DROP MATERIALIZED VIEW IF EXISTS "public"."summary"', $plan->statements()[0]);
    }

    public function testRebuildExecutesEveryPlannedStatementInOrder(): void
    {
        $executed = [];
        $connection = RebuildTestConnectionFactory::recording($this, $executed);
        $rebuilder = new DropCreateRebuilder($connection);

        $context = RebuildContext::create(
            managementComment: '{}',
            grantStatements: ['GRANT SELECT ON public.summary TO app_ro'],
        );

        $rebuilder->rebuild($this->definition(), $context);

        self::assertSame($rebuilder->planFor($this->definition(), $context)->statements(), $executed);
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.summary')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS product_id, 2 AS score_id, 3 AS value'))
            ->withNoData()
            ->withRebuildStrategy(RebuildStrategy::DropCreate)
            ->withIndex(MaterializedViewIndex::unique(
                name: 'ux_summary_identity',
                columns: ['product_id', 'score_id'],
            ))
            ->withIndex(MaterializedViewIndex::regular(
                name: 'idx_summary_score',
                columns: ['score_id'],
            ));
    }
}
