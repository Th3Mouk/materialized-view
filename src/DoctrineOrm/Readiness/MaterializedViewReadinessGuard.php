<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Readiness;

use Doctrine\ORM\EntityManagerInterface;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;

final readonly class MaterializedViewReadinessGuard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MaterializedViewMetadataReader $metadataReader,
        private ReadinessChecker $readinessChecker,
    ) {
    }

    /**
     * @param class-string $entityClass
     */
    public function isReady(string $entityClass): bool
    {
        return $this->readinessChecker->isReady($this->viewNameFor($entityClass));
    }

    /**
     * @param class-string $entityClass
     *
     * @throws ViewNotPopulated
     */
    public function ensureReadable(string $entityClass): void
    {
        $this->readinessChecker->ensureReadable($this->viewNameFor($entityClass));
    }

    /**
     * @param class-string $entityClass
     */
    private function viewNameFor(string $entityClass): MaterializedViewName
    {
        $this->metadataReader->read($entityClass);

        return $this->metadataReader->viewName($this->entityManager->getClassMetadata($entityClass));
    }
}
