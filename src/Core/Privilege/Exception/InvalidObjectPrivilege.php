<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Privilege\Exception;

use InvalidArgumentException;
use Th3Mouk\MaterializedView\Core\Exception\MaterializedViewError;

final class InvalidObjectPrivilege extends InvalidArgumentException implements MaterializedViewError
{
    public static function blankGrantee(): self
    {
        return new self('An object privilege requires a non-empty grantee role.');
    }

    public static function blankPrivilegeType(string $grantee): self
    {
        return new self(\sprintf(
            'The object privilege granted to "%s" has an empty privilege type.',
            $grantee,
        ));
    }

    public static function unsupportedPrivilegeType(string $grantee, string $privilegeType): self
    {
        return new self(\sprintf(
            'The privilege type "%s" granted to "%s" is not a recognised PostgreSQL privilege keyword.',
            $privilegeType,
            $grantee,
        ));
    }
}
