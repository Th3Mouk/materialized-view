<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Dependency;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\Dependency\ExternalDependencyGuard;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

#[Group('dependency')]
final class ExternalDependencyGuardTest extends TestCase
{
    public function testAllowsDropWhenNoDependentExists(): void
    {
        $guard = $this->guardReturning([]);

        $guard->assertSafeToDrop(
            MaterializedViewName::fromString('public.leaf'),
            MaterializedViewRegistry::fromDefinitions([
                MaterializedViewDefinition::create('public.leaf'),
            ]),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testAllowsDropWhenAllDependentsAreManaged(): void
    {
        $guard = $this->guardReturning([
            $this->row('public', 'dependent_mv', 'm', 'public', 'base', 'm'),
        ]);

        $guard->assertSafeToDrop(
            MaterializedViewName::fromString('public.base'),
            MaterializedViewRegistry::fromDefinitions([
                MaterializedViewDefinition::create('public.base'),
                MaterializedViewDefinition::create('public.dependent_mv'),
            ]),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testRefusesDropWhenAPlainViewDependsOnTheManagedView(): void
    {
        $guard = $this->guardReturning([
            $this->row('reporting', 'bi_view', 'v', 'public', 'base', 'm'),
        ]);

        $this->expectException(UnmanagedDependentFound::class);
        $this->expectExceptionMessage('Refusing to drop "public.base"');
        $this->expectExceptionMessage('reporting.bi_view');

        $guard->assertSafeToDrop(
            MaterializedViewName::fromString('public.base'),
            MaterializedViewRegistry::fromDefinitions([
                MaterializedViewDefinition::create('public.base'),
            ]),
        );
    }

    public function testRefusesDropWhenAnUnmanagedMaterializedViewDependsOnTheManagedView(): void
    {
        $guard = $this->guardReturning([
            $this->row('public', 'external_mv', 'm', 'public', 'base', 'm'),
        ]);

        $this->expectException(UnmanagedDependentFound::class);
        $this->expectExceptionMessage('public.external_mv');

        $guard->assertSafeToDrop(
            MaterializedViewName::fromString('public.base'),
            MaterializedViewRegistry::fromDefinitions([
                MaterializedViewDefinition::create('public.base'),
            ]),
        );
    }

    public function testRefusesRebuildWithDedicatedMessage(): void
    {
        $guard = $this->guardReturning([
            $this->row('reporting', 'bi_view', 'v', 'public', 'base', 'm'),
        ]);

        $this->expectException(UnmanagedDependentFound::class);
        $this->expectExceptionMessage('Refusing to rebuild "public.base"');

        $guard->assertSafeToRebuild(
            MaterializedViewName::fromString('public.base'),
            MaterializedViewRegistry::fromDefinitions([
                MaterializedViewDefinition::create('public.base'),
            ]),
        );
    }

    public function testCascadePolicyDoesNotBlockDropDespiteAnUnmanagedDependent(): void
    {
        $guard = $this->guardReturning([
            $this->row('reporting', 'bi_view', 'v', 'public', 'base', 'm'),
        ]);

        $guard->assertSafeToDrop(
            MaterializedViewName::fromString('public.base'),
            MaterializedViewRegistry::fromDefinitions([
                MaterializedViewDefinition::create('public.base'),
            ]),
            DropDependentPolicy::Cascade,
        );

        $this->expectNotToPerformAssertions();
    }

    public function testCascadePolicyDoesNotBlockRebuildDespiteAnUnmanagedDependent(): void
    {
        $guard = $this->guardReturning([
            $this->row('reporting', 'bi_view', 'v', 'public', 'base', 'm'),
        ]);

        $guard->assertSafeToRebuild(
            MaterializedViewName::fromString('public.base'),
            MaterializedViewRegistry::fromDefinitions([
                MaterializedViewDefinition::create('public.base'),
            ]),
            DropDependentPolicy::Cascade,
        );

        $this->expectNotToPerformAssertions();
    }

    public function testDetectsUnmanagedDependentBehindAManagedView(): void
    {
        $guard = $this->guardReturning([
            $this->row('public', 'managed_mid', 'm', 'public', 'base', 'm'),
            $this->row('reporting', 'bi_view', 'v', 'public', 'managed_mid', 'm'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.base'),
            MaterializedViewDefinition::create('public.managed_mid'),
        ]);

        self::assertSame(
            ['reporting.bi_view'],
            $guard->unmanagedDependentsOf(MaterializedViewName::fromString('public.base'), $registry),
        );
    }

    public function testStopsAtTheImmediatePlainViewDependentWithoutTraversingBeyondIt(): void
    {
        $guard = $this->guardReturning([
            $this->row('public', 'passthrough_view', 'v', 'public', 'base', 'm'),
            $this->row('reporting', 'bi_view', 'v', 'public', 'passthrough_view', 'v'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.base'),
        ]);

        self::assertSame(
            ['public.passthrough_view'],
            $guard->unmanagedDependentsOf(MaterializedViewName::fromString('public.base'), $registry),
        );
    }

    public function testReturnsUnmanagedDependentsSortedAndDeduplicated(): void
    {
        $guard = $this->guardReturning([
            $this->row('reporting', 'zeta_view', 'v', 'public', 'base', 'm'),
            $this->row('reporting', 'alpha_view', 'v', 'public', 'base', 'm'),
        ]);

        $registry = MaterializedViewRegistry::fromDefinitions([
            MaterializedViewDefinition::create('public.base'),
        ]);

        self::assertSame(
            ['reporting.alpha_view', 'reporting.zeta_view'],
            $guard->unmanagedDependentsOf(MaterializedViewName::fromString('public.base'), $registry),
        );
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function guardReturning(array $rows): ExternalDependencyGuard
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return new ExternalDependencyGuard(new CatalogDependencyResolver($connection));
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
