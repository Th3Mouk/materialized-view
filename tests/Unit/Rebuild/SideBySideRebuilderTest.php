<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Rebuild;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Exception\SideBySideRequiresLeafView;
use Th3Mouk\MaterializedView\Core\Rebuild\DependentView;
use Th3Mouk\MaterializedView\Core\Rebuild\RebuildContext;
use Th3Mouk\MaterializedView\Core\Rebuild\SideBySideRebuilder;

#[Group('rebuild')]
final class SideBySideRebuilderTest extends TestCase
{
    private const string SWAP_TOKEN = 'abc123';

    public function testStrategyIsSideBySide(): void
    {
        $rebuilder = new SideBySideRebuilder(RebuildTestConnectionFactory::create($this), self::SWAP_TOKEN);

        self::assertSame(RebuildStrategy::SideBySide, $rebuilder->strategy());
    }

    public function testEmitsTheNineOrderedStepsForALeafView(): void
    {
        $rebuilder = new SideBySideRebuilder(RebuildTestConnectionFactory::create($this), self::SWAP_TOKEN);

        $plan = $rebuilder->planFor(
            $this->definition(),
            RebuildContext::create(
                managementComment: '{}',
                grantStatements: ['GRANT SELECT ON public.summary TO bi_reader'],
            ),
        );

        self::assertSame(RebuildStrategy::SideBySide, $plan->strategy);
        self::assertSame(
            [
                // Step 1: create the temporary view next to the live one.
                'CREATE MATERIALIZED VIEW "public"."summary__mv_tmp_abc123" AS SELECT 1 AS product_id, 2 AS score_id WITH NO DATA',
                // Step 2: temporary-named indexes (final names are still taken).
                'CREATE UNIQUE INDEX "ux_summary_identity__tmp_abc123" ON "public"."summary__mv_tmp_abc123" ("product_id", "score_id")',
                'CREATE INDEX "idx_summary_score__tmp_abc123" ON "public"."summary__mv_tmp_abc123" ("score_id")',
                // Step 3: populate the temporary view.
                'REFRESH MATERIALIZED VIEW "public"."summary__mv_tmp_abc123" WITH DATA',
                // Step 4: short lock before the swap.
                'LOCK TABLE "public"."summary" IN ACCESS EXCLUSIVE MODE',
                // Step 5: rename the live view aside.
                'ALTER MATERIALIZED VIEW "public"."summary" RENAME TO "summary__mv_old_abc123"',
                // Step 6: promote the temporary view to the final name.
                'ALTER MATERIALIZED VIEW "public"."summary__mv_tmp_abc123" RENAME TO "summary"',
                // Step 7: drop the old view, freeing the final index names.
                'DROP MATERIALIZED VIEW IF EXISTS "public"."summary__mv_old_abc123"',
                // Step 8: rename the temporary indexes to their final names.
                'ALTER INDEX "public"."ux_summary_identity__tmp_abc123" RENAME TO "ux_summary_identity"',
                'ALTER INDEX "public"."idx_summary_score__tmp_abc123" RENAME TO "idx_summary_score"',
                // Step 9: re-apply the management comment and grants.
                'COMMENT ON MATERIALIZED VIEW "public"."summary" IS \'{}\'',
                'GRANT SELECT ON public.summary TO bi_reader',
            ],
            $plan->statements(),
        );
    }

    public function testDropOfOldViewPrecedesTheTemporaryIndexRenames(): void
    {
        $rebuilder = new SideBySideRebuilder(RebuildTestConnectionFactory::create($this), self::SWAP_TOKEN);

        $statements = $rebuilder->planFor($this->definition(), RebuildContext::create(managementComment: '{}'))
            ->statements();

        $dropOldPosition = $this->positionOf($statements, 'DROP MATERIALIZED VIEW IF EXISTS "public"."summary__mv_old_abc123"');
        $firstIndexRenamePosition = $this->positionOf($statements, 'ALTER INDEX "public"."ux_summary_identity__tmp_abc123" RENAME TO "ux_summary_identity"');

        self::assertLessThan(
            $firstIndexRenamePosition,
            $dropOldPosition,
            'The old view must be dropped before the temporary index names can be renamed to the final names.',
        );
    }

    public function testRenameOfLivePrecedesPromotionOfTemporary(): void
    {
        $rebuilder = new SideBySideRebuilder(RebuildTestConnectionFactory::create($this), self::SWAP_TOKEN);

        $statements = $rebuilder->planFor($this->definition(), RebuildContext::create(managementComment: '{}'))
            ->statements();

        $renameLivePosition = $this->positionOf($statements, 'ALTER MATERIALIZED VIEW "public"."summary" RENAME TO "summary__mv_old_abc123"');
        $promoteTmpPosition = $this->positionOf($statements, 'ALTER MATERIALIZED VIEW "public"."summary__mv_tmp_abc123" RENAME TO "summary"');

        self::assertLessThan($promoteTmpPosition, $renameLivePosition);
    }

    public function testRefusesWhenTheViewHasAnyDependent(): void
    {
        $rebuilder = new SideBySideRebuilder(RebuildTestConnectionFactory::create($this), self::SWAP_TOKEN);

        $this->expectException(SideBySideRequiresLeafView::class);
        $this->expectExceptionMessage('public.summary');

        $rebuilder->planFor(
            $this->definition(),
            RebuildContext::create(
                managementComment: '{}',
                dependents: [
                    DependentView::managed(MaterializedViewName::fromString('public.summary_rollup')),
                ],
            ),
        );
    }

    public function testRefusesEvenWhenTheOnlyDependentIsManaged(): void
    {
        $rebuilder = new SideBySideRebuilder(RebuildTestConnectionFactory::create($this), self::SWAP_TOKEN);

        $this->expectException(SideBySideRequiresLeafView::class);

        $rebuilder->rebuild(
            $this->definition(),
            RebuildContext::create(
                managementComment: '{}',
                dependents: [
                    DependentView::managed(MaterializedViewName::fromString('public.summary_rollup')),
                ],
            ),
        );
    }

    public function testRebuildRunsBuildStepsThenSwapStepsInsideASingleTransaction(): void
    {
        $executed = [];
        $connection = RebuildTestConnectionFactory::recording($this, $executed);
        $rebuilder = new SideBySideRebuilder($connection, self::SWAP_TOKEN);

        $context = RebuildContext::create(managementComment: '{}');

        $rebuilder->rebuild($this->definition(), $context);

        self::assertSame($rebuilder->planFor($this->definition(), $context)->statements(), $executed);
    }

    /**
     * @param list<string> $statements
     */
    private function positionOf(array $statements, string $needle): int
    {
        foreach ($statements as $position => $statement) {
            if ($statement === $needle) {
                return $position;
            }
        }

        self::fail(\sprintf('Statement not found in plan: %s', $needle));
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.summary')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS product_id, 2 AS score_id'))
            ->withNoData()
            ->withRebuildStrategy(RebuildStrategy::SideBySide)
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
