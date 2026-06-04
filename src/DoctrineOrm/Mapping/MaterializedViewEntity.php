<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\DoctrineOrm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class MaterializedViewEntity
{
    /**
     * @param class-string $definition
     */
    public function __construct(
        public string $definition,
    ) {
    }
}
