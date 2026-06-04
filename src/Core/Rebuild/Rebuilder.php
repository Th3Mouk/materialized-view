<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Exception\MaterializedViewError;

interface Rebuilder
{
    public function strategy(): RebuildStrategy;

    /**
     * @throws MaterializedViewError
     */
    public function planFor(MaterializedViewDefinition $definition, RebuildContext $context): RebuildPlan;

    /**
     * @throws MaterializedViewError
     */
    public function rebuild(MaterializedViewDefinition $definition, RebuildContext $context): void;
}
