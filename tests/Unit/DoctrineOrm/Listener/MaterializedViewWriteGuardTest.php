<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\DoctrineOrm\Exception\CannotWriteMaterializedViewEntity;
use Th3Mouk\MaterializedView\DoctrineOrm\Listener\MaterializedViewWriteGuard;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\PlainEntity;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\SalesByCategory;

#[Group('doctrine-orm')]
final class MaterializedViewWriteGuardTest extends TestCase
{
    private MaterializedViewWriteGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new MaterializedViewWriteGuard(new MaterializedViewMetadataReader());
    }

    public function testRejectsInsertOfAMaterializedViewEntity(): void
    {
        $args = $this->onFlushWith(insertions: [new SalesByCategory()]);

        $this->expectException(CannotWriteMaterializedViewEntity::class);
        $this->expectExceptionMessage('cannot be inserted');

        $this->guard->onFlush($args);
    }

    public function testRejectsUpdateOfAMaterializedViewEntity(): void
    {
        $args = $this->onFlushWith(updates: [new SalesByCategory()]);

        $this->expectException(CannotWriteMaterializedViewEntity::class);
        $this->expectExceptionMessage('cannot be updated');

        $this->guard->onFlush($args);
    }

    public function testRejectsDeleteOfAMaterializedViewEntity(): void
    {
        $args = $this->onFlushWith(deletions: [new SalesByCategory()]);

        $this->expectException(CannotWriteMaterializedViewEntity::class);
        $this->expectExceptionMessage('cannot be deleted');

        $this->guard->onFlush($args);
    }

    public function testTheMessageNamesTheOffendingEntity(): void
    {
        $args = $this->onFlushWith(insertions: [new SalesByCategory()]);

        $this->expectExceptionMessage(SalesByCategory::class);

        $this->guard->onFlush($args);
    }

    public function testAllowsWritesToPlainEntities(): void
    {
        $args = $this->onFlushWith(
            insertions: [new PlainEntity()],
            updates: [new PlainEntity()],
            deletions: [new PlainEntity()],
        );

        $this->guard->onFlush($args);

        $this->expectNotToPerformAssertions();
    }

    public function testAllowsAnEmptyChangeSet(): void
    {
        $this->guard->onFlush($this->onFlushWith());

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param list<object> $insertions
     * @param list<object> $updates
     * @param list<object> $deletions
     */
    private function onFlushWith(
        array $insertions = [],
        array $updates = [],
        array $deletions = [],
    ): OnFlushEventArgs {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn($insertions);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn($updates);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn($deletions);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return new OnFlushEventArgs($entityManager);
    }
}
