<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

enum DropDependentPolicy: string
{
    case Refuse = 'refuse';
    case Cascade = 'cascade';
}
