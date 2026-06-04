<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Exception;

use LogicException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final class MissingSqlSource extends LogicException implements MaterializedViewError
{
    public static function forView(MaterializedViewName $name): self
    {
        return new self(\sprintf(
            'The materialized view "%s" has no SQL source; call fromSql() before using the definition.',
            $name->qualifiedName(),
        ));
    }
}
