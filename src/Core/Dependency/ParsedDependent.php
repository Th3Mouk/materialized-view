<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

use Th3Mouk\MaterializedView\Core\Sql\QualifiedName;

/**
 * A single dependent object extracted (best-effort) from a PostgreSQL DDL-conflict
 * DETAIL line. The name is a raw parsed identifier ({@see QualifiedName}); it is a seed
 * for the authoritative catalog walk, never the final source of truth on its own.
 */
final readonly class ParsedDependent
{
    public function __construct(
        public ParsedDependentKind $kind,
        public QualifiedName $name,
    ) {
    }
}
