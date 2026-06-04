<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sync;

enum MissingDependencyPolicy: string
{
    case Fail = 'fail';
    case Skip = 'skip';
}
