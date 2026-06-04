<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sync;

enum SyncAction: string
{
    case Create = 'create';
    case Rebuild = 'rebuild';
    case UpToDate = 'up_to_date';
    case Orphan = 'orphan';

    public function requiresWrite(): bool
    {
        return self::Create === $this || self::Rebuild === $this;
    }
}
