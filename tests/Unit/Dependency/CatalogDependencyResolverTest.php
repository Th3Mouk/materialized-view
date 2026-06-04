<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Dependency;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

#[Group('dependency')]
final class CatalogDependencyResolverTest extends TestCase
{
    public function testRefreshOrderPutsReferencedManagedViewsFirst(): void
    {
        $resolver = $this->resolverReturning([
            $this->row('public', 'summary', 'm', 'public', 'fact', 'm'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.summary'),
            MaterializedViewDefinition::create('public.fact'),
        ]);

        self::assertSame(['public.fact', 'public.summary'], $resolver->orderedForRefresh($registry));
    }

    public function testDropOrderReversesRefreshOrder(): void
    {
        $resolver = $this->resolverReturning([
            $this->row('public', 'summary', 'm', 'public', 'fact', 'm'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.summary'),
            MaterializedViewDefinition::create('public.fact'),
        ]);

        self::assertSame(['public.summary', 'public.fact'], $resolver->orderedForDrop($registry));
    }

    public function testTraversesPlainViewIntermediateBetweenTwoMaterializedViews(): void
    {
        $resolver = $this->resolverReturning([
            $this->row('public', 'top_mv', 'm', 'public', 'bridge_view', 'v'),
            $this->row('public', 'bridge_view', 'v', 'public', 'base_mv', 'm'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.top_mv'),
            MaterializedViewDefinition::create('public.base_mv'),
        ]);

        self::assertSame(['public.base_mv', 'public.top_mv'], $resolver->orderedForRefresh($registry));
    }

    public function testTraversesChainedPlainViewIntermediates(): void
    {
        $resolver = $this->resolverReturning([
            $this->row('public', 'top_mv', 'm', 'public', 'outer_view', 'v'),
            $this->row('public', 'outer_view', 'v', 'public', 'inner_view', 'v'),
            $this->row('public', 'inner_view', 'v', 'public', 'base_mv', 'm'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.top_mv'),
            MaterializedViewDefinition::create('public.base_mv'),
        ]);

        self::assertSame(['public.base_mv', 'public.top_mv'], $resolver->orderedForRefresh($registry));
    }

    public function testIgnoresEdgesTowardUnmanagedMaterializedViews(): void
    {
        $resolver = $this->resolverReturning([
            $this->row('public', 'managed', 'm', 'public', 'unmanaged', 'm'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.managed'),
        ]);

        self::assertSame(['public.managed'], $resolver->orderedForRefresh($registry));
    }

    public function testDoesNotCreateSelfEdgeWhenPlainViewLoopsBackToTheDependent(): void
    {
        $resolver = $this->resolverReturning([
            $this->row('public', 'mv', 'm', 'public', 'bridge_view', 'v'),
            $this->row('public', 'bridge_view', 'v', 'public', 'mv', 'm'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.mv'),
        ]);

        self::assertSame(['public.mv'], $resolver->orderedForRefresh($registry));
    }

    public function testReturnsEdgesCarryingRelkindMetadata(): void
    {
        $resolver = $this->resolverReturning([
            $this->row('public', 'summary', 'm', 'public', 'fact', 'm'),
        ]);

        $edges = $resolver->edges();

        self::assertCount(1, $edges);
        self::assertSame('public.summary', $edges[0]->dependent->qualifiedName());
        self::assertTrue($edges[0]->dependentIsMaterializedView());
        self::assertSame('public.fact', $edges[0]->referenced->qualifiedName());
        self::assertTrue($edges[0]->referencedIsMaterializedView());
    }

    public function testQueryAppliesMandatoryDependencyFilters(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                self::assertStringContainsString("dep.classid    = 'pg_rewrite'::regclass", $sql);
                self::assertStringContainsString("dep.refclassid = 'pg_class'::regclass", $sql);
                self::assertStringContainsString("dep.deptype    = 'n'", $sql);
                self::assertStringContainsString('dependent_class.oid <> referenced_class.oid', $sql);
                self::assertStringContainsString('JOIN pg_rewrite rw ON rw.oid = dep.objid', $sql);

                return [];
            });

        $resolver = new CatalogDependencyResolver($connection);

        self::assertSame([], $resolver->edges());
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function resolverReturning(array $rows): CatalogDependencyResolver
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return new CatalogDependencyResolver($connection);
    }

    /**
     * @return array<string, string>
     */
    private function row(
        string $dependentSchema,
        string $dependentView,
        string $dependentRelkind,
        string $referencedSchema,
        string $referencedView,
        string $referencedRelkind,
    ): array {
        return [
            'dependent_schema' => $dependentSchema,
            'dependent_view' => $dependentView,
            'dependent_relkind' => $dependentRelkind,
            'referenced_schema' => $referencedSchema,
            'referenced_view' => $referencedView,
            'referenced_relkind' => $referencedRelkind,
        ];
    }
}
