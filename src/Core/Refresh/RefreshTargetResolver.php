<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Refresh;

use Doctrine\DBAL\Connection;
use Th3Mouk\MaterializedView\Core\Exception\CannotResolveRefreshTarget;

interface RefreshTargetResolver
{
    /**
     * @throws CannotResolveRefreshTarget
     */
    public function resolve(AsyncRefreshRequest $request): Connection;
}
