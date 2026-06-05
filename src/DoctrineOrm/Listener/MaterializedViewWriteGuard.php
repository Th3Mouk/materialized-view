<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Listener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\DoctrineOrm\Exception\CannotWriteMaterializedViewEntity;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;

final readonly class MaterializedViewWriteGuard
{
    private LoggerInterface $logger;

    public function __construct(
        private MaterializedViewMetadataReader $metadataReader,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
            $this->warnBlockedWrite($entity::class, 'insert');

            throw CannotWriteMaterializedViewEntity::onInsert($entity::class);
        }
    }

    private function rejectUpdate(object $entity): void
    {
        if ($this->metadataReader->isMaterializedViewEntity($entity)) {
            $this->warnBlockedWrite($entity::class, 'update');

            throw CannotWriteMaterializedViewEntity::onUpdate($entity::class);
        }
    }

    private function rejectDelete(object $entity): void
    {
        if ($this->metadataReader->isMaterializedViewEntity($entity)) {
            $this->warnBlockedWrite($entity::class, 'delete');

            throw CannotWriteMaterializedViewEntity::onDelete($entity::class);
        }
    }

    private function warnBlockedWrite(string $entityClass, string $operation): void
    {
        $this->logger->warning('Blocked {operation} on read-only materialized view entity "{entity}".', [
            'entity' => $entityClass,
            'operation' => $operation,
        ]);
    }
}
