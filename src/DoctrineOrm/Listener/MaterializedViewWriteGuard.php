<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Listener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Th3Mouk\MaterializedView\DoctrineOrm\Exception\CannotWriteMaterializedViewEntity;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;

final readonly class MaterializedViewWriteGuard
{
    public function __construct(
        private MaterializedViewMetadataReader $metadataReader,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->rejectInsert($entity);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $this->rejectUpdate($entity);
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $this->rejectDelete($entity);
        }
    }

    private function rejectInsert(object $entity): void
    {
        if ($this->metadataReader->isMaterializedViewEntity($entity)) {
            throw CannotWriteMaterializedViewEntity::onInsert($entity::class);
        }
    }

    private function rejectUpdate(object $entity): void
    {
        if ($this->metadataReader->isMaterializedViewEntity($entity)) {
            throw CannotWriteMaterializedViewEntity::onUpdate($entity::class);
        }
    }

    private function rejectDelete(object $entity): void
    {
        if ($this->metadataReader->isMaterializedViewEntity($entity)) {
            throw CannotWriteMaterializedViewEntity::onDelete($entity::class);
        }
    }
}
