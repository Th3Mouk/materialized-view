<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\ConcurrentRefreshUnsupported;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshOptions;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sql\PostgreSqlMaterializedViewSqlGenerator;

#[Group('sql')]
final class PostgreSqlMaterializedViewSqlGeneratorTest extends TestCase
{
    private const string SELECT_BODY = 'SELECT category, count(*) AS order_count, sum(amount) AS total_amount'
        .' FROM orders GROUP BY category';

    private PostgreSqlMaterializedViewSqlGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new PostgreSqlMaterializedViewSqlGenerator(new IdentifierQuoter());
    }

    public function testCreateWithNoData(): void
    {
        self::assertSame(
            'CREATE MATERIALIZED VIEW "public"."sales_by_category" AS '
            .self::SELECT_BODY
            .' WITH NO DATA',
            $this->generator->create($this->definition()),
        );
    }

    public function testCreateWithData(): void
    {
        self::assertSame(
            'CREATE MATERIALIZED VIEW "public"."sales_by_category" AS '
            .self::SELECT_BODY
            .' WITH DATA',
            $this->generator->create($this->definition()->withData()),
        );
    }

    public function testCreateStripsTrailingSemicolonAndSurroundingWhitespace(): void
    {
        $definition = MaterializedViewDefinition::create('public.sales_by_category')
            ->fromSql(InlineSqlSource::fromString("\n  SELECT 1 AS n ;\n"));

        self::assertSame(
            'CREATE MATERIALIZED VIEW "public"."sales_by_category" AS SELECT 1 AS n WITH NO DATA',
            $this->generator->create($definition),
        );
    }

    public function testDropWithoutIfExistsHasNoImplicitCascade(): void
    {
        $sql = $this->generator->drop(MaterializedViewName::fromString('public.sales_by_category'));

        self::assertSame('DROP MATERIALIZED VIEW "public"."sales_by_category"', $sql);
        self::assertStringNotContainsStringIgnoringCase('CASCADE', $sql);
    }

    public function testDropWithIfExists(): void
    {
        self::assertSame(
            'DROP MATERIALIZED VIEW IF EXISTS "public"."sales_by_category"',
            $this->generator->drop(MaterializedViewName::fromString('public.sales_by_category'), true),
        );
    }

    public function testDropWithCascadeAppendsCascadeClause(): void
    {
        self::assertSame(
            'DROP MATERIALIZED VIEW IF EXISTS "public"."sales_by_category" CASCADE',
            $this->generator->drop(MaterializedViewName::fromString('public.sales_by_category'), true, true),
        );
    }

    public function testDropWithCascadeWithoutIfExists(): void
    {
        self::assertSame(
            'DROP MATERIALIZED VIEW "public"."sales_by_category" CASCADE',
            $this->generator->drop(MaterializedViewName::fromString('public.sales_by_category'), false, true),
        );
    }

    public function testRefreshWithDataOmitsExplicitWithDataClause(): void
    {
        self::assertSame(
            'REFRESH MATERIALIZED VIEW "public"."sales_by_category"',
            $this->generator->refresh($this->viewName(), RefreshOptions::default()),
        );
    }

    public function testRefreshConcurrently(): void
    {
        self::assertSame(
            'REFRESH MATERIALIZED VIEW CONCURRENTLY "public"."sales_by_category"',
            $this->generator->refresh($this->viewName(), RefreshOptions::concurrent()),
        );
    }

    public function testRefreshWithNoData(): void
    {
        self::assertSame(
            'REFRESH MATERIALIZED VIEW "public"."sales_by_category" WITH NO DATA',
            $this->generator->refresh($this->viewName(), RefreshOptions::default()->withNoData()),
        );
    }

    public function testRefreshConcurrentlyWithNoDataIsRejected(): void
    {
        $this->expectException(ConcurrentRefreshUnsupported::class);
        $this->expectExceptionMessage('CONCURRENTLY and WITH NO DATA cannot be combined');

        $this->generator->refresh($this->viewName(), RefreshOptions::concurrent()->withNoData());
    }

    public function testCreateUniqueIndex(): void
    {
        $index = MaterializedViewIndex::unique(
            name: 'ux_sales_by_category_category',
            columns: ['category'],
        );

        self::assertSame(
            'CREATE UNIQUE INDEX "ux_sales_by_category_category"'
            .' ON "public"."sales_by_category" ("category")',
            $this->generator->createIndex($this->viewName(), $index),
        );
    }

    public function testCreateRegularIndex(): void
    {
        $index = MaterializedViewIndex::regular(
            name: 'idx_sales_by_category_total_amount',
            columns: ['total_amount'],
        );

        self::assertSame(
            'CREATE INDEX "idx_sales_by_category_total_amount"'
            .' ON "public"."sales_by_category" ("total_amount")',
            $this->generator->createIndex($this->viewName(), $index),
        );
    }

    public function testCreateIndexWithMethodIncludeWhereAndConcurrently(): void
    {
        $index = MaterializedViewIndex::regular(
            name: 'idx_partial',
            columns: ['total_amount'],
            method: 'btree',
            include: ['order_count'],
            where: 'total_amount > 0',
            concurrently: true,
        );

        self::assertSame(
            'CREATE INDEX CONCURRENTLY "idx_partial"'
            .' ON "public"."sales_by_category" USING "btree" ("total_amount")'
            .' INCLUDE ("order_count") WHERE total_amount > 0',
            $this->generator->createIndex($this->viewName(), $index),
        );
    }

    public function testCommentCarriesJsonManagementMarker(): void
    {
        $marker = ManagementMarker::create(
            hash: 'abc123',
            version: 1,
            source: 'db/matviews/sales_by_category_v001.sql',
        );

        self::assertSame(
            'COMMENT ON MATERIALIZED VIEW "public"."sales_by_category" IS '
            .'\'{"th3mouk_materialized_view":{"hash":"abc123","version":1,'
            .'"source":"db/matviews/sales_by_category_v001.sql"}}\'',
            $this->generator->comment($this->viewName(), $marker),
        );
    }

    public function testCommentWithoutSourceOmitsSourceKey(): void
    {
        self::assertSame(
            'COMMENT ON MATERIALIZED VIEW "public"."sales_by_category" IS '
            .'\'{"th3mouk_materialized_view":{"hash":"deadbeef","version":2}}\'',
            $this->generator->comment($this->viewName(), ManagementMarker::create('deadbeef', 2)),
        );
    }

    public function testRename(): void
    {
        self::assertSame(
            'ALTER MATERIALIZED VIEW "public"."sales_by_category" RENAME TO "sales_by_category__mv_old"',
            $this->generator->rename($this->viewName(), 'sales_by_category__mv_old'),
        );
    }

    private function viewName(): MaterializedViewName
    {
        return MaterializedViewName::fromString('public.sales_by_category');
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.sales_by_category')
            ->fromSql(InlineSqlSource::fromString(self::SELECT_BODY))
            ->withNoData();
    }
}
