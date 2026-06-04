<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Definition;

use Th3Mouk\MaterializedView\Core\Exception\CannotReadSqlSource;

interface SqlSource
{
    /**
     * @throws CannotReadSqlSource
     */
    public function sql(): string;

    public function identifier(): string;
}
