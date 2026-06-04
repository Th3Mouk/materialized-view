<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Definition;

enum PopulationPolicy: string
{
    case Manual = 'manual';
    case Async = 'async';
    case Synchronous = 'synchronous';
    case RequiredBeforeRead = 'required_before_read';

    public function refreshesDuringSync(): bool
    {
        return self::Synchronous === $this;
    }

    public function requiresReadinessBeforeRead(): bool
    {
        return self::RequiredBeforeRead === $this;
    }
}
