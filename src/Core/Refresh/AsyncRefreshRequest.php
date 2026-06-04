<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Refresh;

final readonly class AsyncRefreshRequest
{
    public function __construct(
        public string $connectionName,
        public string $databaseName,
        public string $viewName,
        public RefreshOptions $options,
    ) {
    }

    public static function for(
        string $connectionName,
        string $databaseName,
        string $viewName,
        ?RefreshOptions $options = null,
    ): self {
        return new self(
            connectionName: $connectionName,
            databaseName: $databaseName,
            viewName: $viewName,
            options: $options ?? RefreshOptions::default(),
        );
    }
}
