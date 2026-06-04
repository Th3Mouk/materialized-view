<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class DependentView
{
    private function __construct(
        public MaterializedViewName $name,
        public bool $managed,
    ) {
    }

    public static function managed(MaterializedViewName $name): self
    {
        return new self($name, true);
    }

    public static function unmanaged(MaterializedViewName $name): self
    {
        return new self($name, false);
    }
}
