<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

/**
 * The kind of object PostgreSQL named as a dependent in a DDL conflict DETAIL line.
 * Only materialized views can be managed by this library; a plain view dependent is
 * always external and blocks the reactive drop.
 */
enum ParsedDependentKind
{
    case MaterializedView;
    case View;
}
