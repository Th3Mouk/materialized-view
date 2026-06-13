<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Introspection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;

#[Group('introspection')]
final class PostgreSqlMaterializedViewIntrospectorTest extends TestCase
{
    public function testIntrospectSchemaQueriesPgClassFilteredOnMatviewRelkind(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static function (string $sql): bool {
                    self::assertStringContainsString("c.relkind = 'm'", $sql);
                    self::assertStringContainsString('JOIN pg_namespace n ON n.oid = c.relnamespace', $sql);
                    self::assertStringContainsString('pg_get_viewdef(c.oid, true)', $sql);
                    self::assertStringContainsString('c.relispopulated', $sql);
                    self::assertStringContainsString("obj_description(c.oid, 'pg_class')", $sql);
                    self::assertStringContainsString('n.nspname = :schema_name', $sql);

                    return true;
                }),
                ['schema_name' => 'analytics'],
            )
            ->willReturn([]);

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);

        self::assertSame([], $introspector->introspectSchema('analytics'));
    }

    public function testIntrospectSchemaDefaultsToPublicSchema(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::isString(), ['schema_name' => 'public'])
            ->willReturn([]);

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);

        $introspector->introspectSchema();
    }

    public function testIntrospectSchemaHydratesRowsIntoValueObjects(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'pg_indexes')) {
                    return [];
                }

                return [
                    [
                        'schema_name' => 'public',
                        'view_name' => 'sales_by_category',
                        'definition' => ' SELECT 1;',
                        'is_populated' => true,
                        'comment' => '{"th3mouk_materialized_view":{"hash":"abc"}}',
                    ],
                ];
            });

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);
        $views = $introspector->introspectSchema();

        self::assertCount(1, $views);

        $view = $views[0];
        self::assertSame('public.sales_by_category', $view->name->qualifiedName());
        self::assertSame(' SELECT 1;', $view->definition);
        self::assertTrue($view->isPopulated);
        self::assertSame('{"th3mouk_materialized_view":{"hash":"abc"}}', $view->comment);
        self::assertTrue($view->hasComment());
        self::assertSame([], $view->indexes);
    }

    public function testFindBindsSchemaAndViewNameSeparately(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(static function (string $sql): bool {
                    self::assertStringContainsString('n.nspname = :schema_name', $sql);
                    self::assertStringContainsString('c.relname = :view_name', $sql);
                    self::assertStringContainsString("c.relkind = 'm'", $sql);

                    return true;
                }),
                ['schema_name' => 'analytics', 'view_name' => 'sales_by_category'],
            )
            ->willReturn(false);

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);

        self::assertNull($introspector->find(MaterializedViewName::create('analytics', 'sales_by_category')));
    }

    public function testFindReturnsNullWhenViewIsAbsent(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);

        self::assertNull($introspector->find(MaterializedViewName::fromString('public.missing')));
        self::assertFalse($introspector->exists(MaterializedViewName::fromString('public.missing')));
    }

    public function testFindReturnsViewWhenPresent(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'schema_name' => 'public',
            'view_name' => 'sales_by_category',
            'definition' => 'SELECT 1',
            'is_populated' => false,
            'comment' => null,
        ]);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);
        $view = $introspector->find(MaterializedViewName::fromString('public.sales_by_category'));

        self::assertNotNull($view);
        self::assertSame('public.sales_by_category', $view->name->qualifiedName());
        self::assertFalse($view->isPopulated);
        self::assertNull($view->comment);
        self::assertFalse($view->hasComment());
    }

    public function testIntrospectIndexesQueriesPgIndexesByTableName(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static function (string $sql): bool {
                    self::assertStringContainsString('FROM pg_indexes', $sql);
                    self::assertStringContainsString('schemaname = :schema_name', $sql);
                    self::assertStringContainsString('tablename = :view_name', $sql);

                    return true;
                }),
                ['schema_name' => 'public', 'view_name' => 'sales_by_category'],
            )
            ->willReturn([
                ['indexname' => 'ux_identity', 'indexdef' => 'CREATE UNIQUE INDEX ux_identity ON public.sales_by_category (id)'],
                ['indexname' => 'idx_score', 'indexdef' => 'CREATE INDEX idx_score ON public.sales_by_category (score)'],
            ]);

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);
        $indexes = $introspector->introspectIndexes(MaterializedViewName::fromString('public.sales_by_category'));

        self::assertCount(2, $indexes);
        self::assertSame('ux_identity', $indexes[0]->name);
        self::assertSame('CREATE UNIQUE INDEX ux_identity ON public.sales_by_category (id)', $indexes[0]->definition);
        self::assertSame('idx_score', $indexes[1]->name);
    }

    public function testIntrospectedViewCarriesItsIndexes(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'schema_name' => 'public',
            'view_name' => 'sales_by_category',
            'definition' => 'SELECT 1',
            'is_populated' => true,
            'comment' => null,
        ]);
        $connection->method('fetchAllAssociative')->willReturn([
            ['indexname' => 'ux_identity', 'indexdef' => 'CREATE UNIQUE INDEX ux_identity ON public.sales_by_category (id)'],
        ]);

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);
        $view = $introspector->find(MaterializedViewName::fromString('public.sales_by_category'));

        self::assertNotNull($view);
        self::assertCount(1, $view->indexes);
        self::assertSame('ux_identity', $view->indexes[0]->name);
    }

    #[DataProvider('populationFlagProvider')]
    public function testPopulationFlagCoercion(mixed $rawValue, bool $expected): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'schema_name' => 'public',
            'view_name' => 'sales_by_category',
            'definition' => 'SELECT 1',
            'is_populated' => $rawValue,
            'comment' => null,
        ]);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $introspector = new PostgreSqlMaterializedViewIntrospector($connection);
        $view = $introspector->find(MaterializedViewName::fromString('public.sales_by_category'));

        self::assertNotNull($view);
        self::assertSame($expected, $view->isPopulated);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function populationFlagProvider(): iterable
    {
        yield 'native bool true' => [true, true];
        yield 'native bool false' => [false, false];
        yield 'postgres char t' => ['t', true];
        yield 'postgres char f' => ['f', false];
        yield 'string one' => ['1', true];
        yield 'string zero' => ['0', false];
        yield 'null' => [null, false];
    }
}
