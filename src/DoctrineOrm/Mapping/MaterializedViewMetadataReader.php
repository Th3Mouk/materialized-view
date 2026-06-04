<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\DoctrineOrm\Exception\NotAMaterializedViewEntity;

final class MaterializedViewMetadataReader
{
    /**
     * @var array<class-string, MaterializedViewEntity|null>
     */
    private array $attributesByClass = [];

    /**
     * @param object|class-string $entityOrClass
     */
    public function isMaterializedViewEntity(object|string $entityOrClass): bool
    {
        return null !== $this->attributeFor($this->classOf($entityOrClass));
    }

    /**
     * @param object|class-string $entityOrClass
     */
    public function read(object|string $entityOrClass): MaterializedViewEntity
    {
        $class = $this->classOf($entityOrClass);

        return $this->attributeFor($class)
            ?? throw NotAMaterializedViewEntity::missingAttribute($class);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function viewName(ClassMetadata $metadata): MaterializedViewName
    {
        return MaterializedViewName::create(
            $metadata->getSchemaName() ?? MaterializedViewName::DEFAULT_SCHEMA,
            $metadata->getTableName(),
        );
    }

    /**
     * @param class-string $class
     */
    private function attributeFor(string $class): ?MaterializedViewEntity
    {
        if (\array_key_exists($class, $this->attributesByClass)) {
            return $this->attributesByClass[$class];
        }

        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(MaterializedViewEntity::class);

        return $this->attributesByClass[$class] = [] === $attributes
            ? null
            : $attributes[0]->newInstance();
    }

    /**
     * @param object|class-string $entityOrClass
     *
     * @return class-string
     */
    private function classOf(object|string $entityOrClass): string
    {
        return \is_object($entityOrClass) ? $entityOrClass::class : $entityOrClass;
    }
}
