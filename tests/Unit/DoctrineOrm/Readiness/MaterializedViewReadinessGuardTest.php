<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Readiness;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\DoctrineOrm\Exception\NotAMaterializedViewEntity;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;
use Th3Mouk\MaterializedView\DoctrineOrm\Readiness\MaterializedViewReadinessGuard;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\PlainEntity;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\SalesByCategory;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\SchemaQualifiedSummary;

#[Group('doctrine-orm')]
final class MaterializedViewReadinessGuardTest extends TestCase
{
    public function testIsReadyReturnsTrueForAPopulatedView(): void
    {
        $guard = $this->guard(
            SalesByCategory::class,
            ['name' => 'sales_by_category'],
            populatedForParams: ['schema_name' => 'public', 'view_name' => 'sales_by_category'],
        );

        self::assertTrue($guard->isReady(SalesByCategory::class));
    }

    public function testIsReadyReturnsFalseForAnUnpopulatedView(): void
    {
        $guard = $this->guard(
            SalesByCategory::class,
            ['name' => 'sales_by_category'],
            populatedForParams: null,
        );

        self::assertFalse($guard->isReady(SalesByCategory::class));
    }

    public function testResolvesTheSchemaQualifiedViewName(): void
    {
        $guard = $this->guard(
            SchemaQualifiedSummary::class,
            ['name' => 'summary', 'schema' => 'analytics'],
            populatedForParams: ['schema_name' => 'analytics', 'view_name' => 'summary'],
        );

        self::assertTrue($guard->isReady(SchemaQualifiedSummary::class));
    }

    public function testEnsureReadablePassesWhenPopulated(): void
    {
        $guard = $this->guard(
            SalesByCategory::class,
            ['name' => 'sales_by_category'],
            populatedForParams: ['schema_name' => 'public', 'view_name' => 'sales_by_category'],
        );

        $guard->ensureReadable(SalesByCategory::class);

        $this->expectNotToPerformAssertions();
    }

    public function testEnsureReadableThrowsWhenNotPopulated(): void
    {
        $guard = $this->guard(
            SalesByCategory::class,
            ['name' => 'sales_by_category'],
            populatedForParams: null,
        );

        $this->expectException(ViewNotPopulated::class);
        $this->expectExceptionMessage('public.sales_by_category');

        $guard->ensureReadable(SalesByCategory::class);
    }

    public function testRejectsANonMaterializedViewEntity(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getClassMetadata');

        $connection = $this->createStub(Connection::class);

        $guard = new MaterializedViewReadinessGuard(
            $entityManager,
            new MaterializedViewMetadataReader(),
            new ReadinessChecker($connection),
        );

        $this->expectException(NotAMaterializedViewEntity::class);
        $this->expectExceptionMessage(PlainEntity::class);

        $guard->isReady(PlainEntity::class);
    }

    /**
     * @param class-string                                       $entityClass
     * @param array{name: string, schema?: string}               $table
     * @param array{schema_name: string, view_name: string}|null $populatedForParams
     */
    private function guard(
        string $entityClass,
        array $table,
        ?array $populatedForParams,
    ): MaterializedViewReadinessGuard {
        $metadata = new ClassMetadata($entityClass);
        $metadata->table = $table;

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params = []) use ($populatedForParams): bool {
                if (null === $populatedForParams) {
                    return false;
                }

                return $params['schema_name'] === $populatedForParams['schema_name']
                    && $params['view_name'] === $populatedForParams['view_name'];
            },
        );

        return new MaterializedViewReadinessGuard(
            $entityManager,
            new MaterializedViewMetadataReader(),
            new ReadinessChecker($connection),
        );
    }
}
