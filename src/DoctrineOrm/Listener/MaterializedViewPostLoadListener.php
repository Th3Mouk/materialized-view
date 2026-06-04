<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Listener;

use Doctrine\ORM\Event\PostLoadEventArgs;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;

final readonly class MaterializedViewPostLoadListener
{
    public function __construct(
        private MaterializedViewMetadataReader $metadataReader,
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->metadataReader->isMaterializedViewEntity($entity)) {
            return;
        }

        $args->getObjectManager()->getUnitOfWork()->markReadOnly($entity);
    }
}
