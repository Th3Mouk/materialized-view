<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use RuntimeException;
use Th3Mouk\MaterializedView\Core\Refresh\AsyncRefreshRequest;

final class CannotResolveRefreshTarget extends RuntimeException implements MaterializedViewError
{
    public static function forRequest(AsyncRefreshRequest $request): self
    {
        return new self(\sprintf(
            'Cannot resolve a DBAL connection for the async refresh of "%s" on database "%s" (connection "%s").',
            $request->viewName,
            $request->databaseName,
            $request->connectionName,
        ));
    }
}
