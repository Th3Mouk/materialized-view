<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Exception\MissingSqlSource;
use Th3Mouk\MaterializedView\Core\Migration\MaterializedViewMigrationSql;

#[Group('materialized-view')]
final class MaterializedViewMigrationSqlTest extends TestCase
{
    private const string SUMMARY_SQL = <<<'SQL'
        SELECT
            category,
            count(*) AS order_count,
            sum(amount) AS total_amount
        FROM orders
        GROUP BY category
        SQL;

    public function testCreateEmitsDropThenCreateThenIndexes(): void
    {
        $definition = MaterializedViewDefinition::create('public.sales_by_category')
            ->fromSql(InlineSqlSource::fromString(self::SUMMARY_SQL))
            ->withNoData()
            ->withIndex(MaterializedViewIndex::unique(
                name: 'ux_sales_by_category_category',
                columns: ['category'],
            ))
            ->withIndex(MaterializedViewIndex::regular(
                name: 'idx_sales_by_category_total_amount',
                columns: ['total_amount'],
            ));

        $statements = self::toArray(MaterializedViewMigrationSql::create($definition));

        self::assertSame(
            [
                'DROP MATERIALIZED VIEW IF EXISTS "public"."sales_by_category"',
                'CREATE MATERIALIZED VIEW "public"."sales_by_category" AS '.self::SUMMARY_SQL.' WITH NO DATA',
                'CREATE UNIQUE INDEX "ux_sales_by_category_category" ON "public"."sales_by_category" ("category")',
                'CREATE INDEX "idx_sales_by_category_total_amount" ON "public"."sales_by_category" ("total_amount")',
            ],
            $statements,
        );
    }

    public function testCreateWithDataEmitsWithDataClause(): void
    {
        $definition = MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS one'))
            ->withData();

        $statements = self::toArray(MaterializedViewMigrationSql::create($definition));

        self::assertSame(
            [
                'DROP MATERIALIZED VIEW IF EXISTS "public"."report"',
                'CREATE MATERIALIZED VIEW "public"."report" AS SELECT 1 AS one WITH DATA',
            ],
            $statements,
        );
    }

    public function testCreateStripsTrailingSemicolonAndWhitespaceFromBody(): void
    {
        $definition = MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString("  SELECT 1 AS one ;  \n"))
            ->withNoData();

        $statements = self::toArray(MaterializedViewMigrationSql::create($definition));

        self::assertSame(
            'CREATE MATERIALIZED VIEW "public"."report" AS SELECT 1 AS one WITH NO DATA',
            $statements[1],
        );
    }

    public function testCreateQuotesNonDefaultSchema(): void
    {
        $definition = MaterializedViewDefinition::create('analytics.totals')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS one'))
            ->withNoData();

        $statements = self::toArray(MaterializedViewMigrationSql::create($definition));

        self::assertSame('DROP MATERIALIZED VIEW IF EXISTS "analytics"."totals"', $statements[0]);
        self::assertStringStartsWith('CREATE MATERIALIZED VIEW "analytics"."totals" AS', $statements[1]);
    }

    public function testCreateRendersIndexMethodIncludeAndWhere(): void
    {
        $definition = MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS one, 2 AS two, 3 AS three'))
            ->withNoData()
            ->withIndex(MaterializedViewIndex::regular(
                name: 'idx_report_one',
                columns: ['one'],
                method: 'btree',
                include: ['two', 'three'],
                where: 'one IS NOT NULL',
            ));

        $statements = self::toArray(MaterializedViewMigrationSql::create($definition));

        self::assertSame(
            'CREATE INDEX "idx_report_one" ON "public"."report" USING "btree" ("one") INCLUDE ("two", "three") WHERE one IS NOT NULL',
            $statements[2],
        );
    }

    public function testCreateRendersConcurrentlyIndex(): void
    {
        $definition = MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS one'))
            ->withNoData()
            ->withIndex(MaterializedViewIndex::unique(
                name: 'ux_report_one',
                columns: ['one'],
                concurrently: true,
            ));

        $statements = self::toArray(MaterializedViewMigrationSql::create($definition));

        self::assertSame(
            'CREATE UNIQUE INDEX CONCURRENTLY "ux_report_one" ON "public"."report" ("one")',
            $statements[2],
        );
    }

    public function testCreateQuotesIndexIdentifiersDefensively(): void
    {
        $definition = MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS "weird name"'))
            ->withNoData()
            ->withIndex(MaterializedViewIndex::regular(
                name: 'order',
                columns: ['weird name'],
            ));

        $statements = self::toArray(MaterializedViewMigrationSql::create($definition));

        self::assertSame(
            'CREATE INDEX "order" ON "public"."report" ("weird name")',
            $statements[2],
        );
    }

    public function testDropEmitsSingleIdempotentStatement(): void
    {
        $definition = MaterializedViewDefinition::create('public.sales_by_category')
            ->fromSql(InlineSqlSource::fromString(self::SUMMARY_SQL));

        $statements = self::toArray(MaterializedViewMigrationSql::drop($definition));

        self::assertSame(
            ['DROP MATERIALIZED VIEW IF EXISTS "public"."sales_by_category"'],
            $statements,
        );
    }

    public function testDropDoesNotRequireSqlSource(): void
    {
        $definition = MaterializedViewDefinition::create('public.report');

        $statements = self::toArray(MaterializedViewMigrationSql::drop($definition));

        self::assertSame(['DROP MATERIALIZED VIEW IF EXISTS "public"."report"'], $statements);
    }

    public function testCreateRequiresSqlSource(): void
    {
        $definition = MaterializedViewDefinition::create('public.report');

        $this->expectException(MissingSqlSource::class);

        self::toArray(MaterializedViewMigrationSql::create($definition));
    }

    /**
     * @param iterable<string> $statements
     *
     * @return list<string>
     */
    private static function toArray(iterable $statements): array
    {
        return iterator_to_array($statements, false);
    }
}
