<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\DoctrineOrm\Listener\MaterializedViewPostLoadListener;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\PlainEntity;
use Th3Mouk\MaterializedView\Tests\Unit\DoctrineOrm\Fixtures\SalesByCategory;

#[Group('doctrine-orm')]
final class MaterializedViewPostLoadListenerTest extends TestCase
{
    private MaterializedViewPostLoadListener $listener;

    protected function setUp(): void
    {
        $this->listener = new MaterializedViewPostLoadListener(new MaterializedViewMetadataReader());
    }

    public function testMarksAMaterializedViewEntityAsReadOnly(): void
    {
        $entity = new SalesByCategory();
        $marked = [];

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('markReadOnly')->willReturnCallback(
            static function (object $object) use (&$marked): void {
                $marked[] = $object;
            },
        );

        $this->listener->postLoad($this->postLoadArgs($entity, $unitOfWork));

        self::assertSame([$entity], $marked);
    }

    public function testDoesNotTouchPlainEntities(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects(self::never())->method('markReadOnly');

        $this->listener->postLoad($this->postLoadArgs(new PlainEntity(), $unitOfWork));
    }

    private function postLoadArgs(object $entity, UnitOfWork $unitOfWork): PostLoadEventArgs
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return new PostLoadEventArgs($entity, $entityManager);
    }
}
