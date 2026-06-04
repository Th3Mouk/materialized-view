<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\DoctrineOrm\Exception\NotAMaterializedViewEntity;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\PlainEntity;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\SalesByCategory;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\SalesByCategoryView;

#[Group('doctrine-orm')]
final class MaterializedViewMetadataReaderTest extends TestCase
{
    private MaterializedViewMetadataReader $reader;

    protected function setUp(): void
    {
        $this->reader = new MaterializedViewMetadataReader();
    }

    public function testRecognisesAMaterializedViewEntityFromItsClassName(): void
    {
        self::assertTrue($this->reader->isMaterializedViewEntity(SalesByCategory::class));
    }

    public function testRecognisesAMaterializedViewEntityFromAnInstance(): void
    {
        self::assertTrue($this->reader->isMaterializedViewEntity(new SalesByCategory()));
    }

    public function testPlainEntityIsNotAMaterializedViewEntity(): void
    {
        self::assertFalse($this->reader->isMaterializedViewEntity(PlainEntity::class));
        self::assertFalse($this->reader->isMaterializedViewEntity(new PlainEntity()));
    }

    public function testReadExposesTheDefinitionProviderClass(): void
    {
        $attribute = $this->reader->read(SalesByCategory::class);

        self::assertSame(SalesByCategoryView::class, $attribute->definition);
    }

    public function testReadThrowsForANonMaterializedViewEntity(): void
    {
        $this->expectException(NotAMaterializedViewEntity::class);
        $this->expectExceptionMessage(PlainEntity::class);

        $this->reader->read(PlainEntity::class);
    }

    public function testViewNameDefaultsToPublicSchemaWhenNoneIsMapped(): void
    {
        $metadata = new ClassMetadata(SalesByCategory::class);
        $metadata->table = ['name' => 'sales_by_category'];

        $name = $this->reader->viewName($metadata);

        self::assertSame('public.sales_by_category', $name->qualifiedName());
    }

    public function testViewNameUsesTheMappedSchema(): void
    {
        $metadata = new ClassMetadata(SalesByCategory::class);
        $metadata->table = ['name' => 'summary', 'schema' => 'analytics'];

        $name = $this->reader->viewName($metadata);

        self::assertSame('analytics.summary', $name->qualifiedName());
    }

    public function testAttributeLookupIsMemoisedPerClass(): void
    {
        self::assertTrue($this->reader->isMaterializedViewEntity(SalesByCategory::class));
        self::assertSame(
            SalesByCategoryView::class,
            $this->reader->read(SalesByCategory::class)->definition,
        );
    }
}
