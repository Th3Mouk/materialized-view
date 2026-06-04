<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Definition;

enum RebuildStrategy: string
{
    case DropCreate = 'drop_create';
    case SideBySide = 'side_by_side';

    public function requiresLeafView(): bool
    {
        return self::SideBySide === $this;
    }
}
