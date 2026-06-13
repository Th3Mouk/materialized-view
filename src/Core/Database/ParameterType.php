<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Database;

/**
 * Backend-agnostic binding type for a query parameter.
 *
 * Each {@see Connection} adapter maps these onto its native types
 * (Doctrine DBAL `ParameterType`, PDO `PARAM_*`, …).
 */
enum ParameterType
{
    case String;
    case Integer;
    case Boolean;
}
