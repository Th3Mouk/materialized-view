<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Readiness;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\DoctrineOrm\Mapping\MaterializedViewMetadataReader;

final readonly class MaterializedViewReadinessGuard
{
    private LoggerInterface $logger;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MaterializedViewMetadataReader $metadataReader,
        private ReadinessChecker $readinessChecker,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param class-string $entityClass
     */
    public function isReady(string $entityClass): bool
    {
        $name = $this->viewNameFor($entityClass);

        $this->logger->debug('Checking readiness of materialized view entity "{entity}".', [
            'entity' => $entityClass,
            'view' => $name->qualifiedName(),
        ]);

        return $this->readinessChecker->isReady($name);
    }

    /**
     * @param class-string $entityClass
     *
     * @throws ViewNotPopulated
     */
    public function ensureReadable(string $entityClass): void
    {
        $name = $this->viewNameFor($entityClass);

        $this->logger->debug('Ensuring materialized view entity "{entity}" is readable.', [
            'entity' => $entityClass,
            'view' => $name->qualifiedName(),
        ]);

        $this->readinessChecker->ensureReadable($name);
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
