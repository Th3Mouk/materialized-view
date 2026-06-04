<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Privilege;

use Th3Mouk\MaterializedView\Core\Privilege\Exception\InvalidObjectPrivilege;

final readonly class ObjectPrivilege
{
    public const string PUBLIC_GRANTEE = 'PUBLIC';

    private const string PRIVILEGE_TYPE_PATTERN = '/^[A-Z]+(?: [A-Z]+)*$/';

    private function __construct(
        public string $grantee,
        public string $privilegeType,
        public bool $withGrantOption,
    ) {
    }

    public static function granted(
        string $grantee,
        string $privilegeType,
        bool $withGrantOption = false,
    ): self {
        $normalizedGrantee = trim($grantee);

        if ('' === $normalizedGrantee) {
            throw InvalidObjectPrivilege::blankGrantee();
        }

        $normalizedPrivilege = strtoupper(trim($privilegeType));

        if ('' === $normalizedPrivilege) {
            throw InvalidObjectPrivilege::blankPrivilegeType($normalizedGrantee);
        }

        if (1 !== preg_match(self::PRIVILEGE_TYPE_PATTERN, $normalizedPrivilege)) {
            throw InvalidObjectPrivilege::unsupportedPrivilegeType($normalizedGrantee, $normalizedPrivilege);
        }

        return new self(
            grantee: $normalizedGrantee,
            privilegeType: $normalizedPrivilege,
            withGrantOption: $withGrantOption,
        );
    }

    public static function fromCatalogRow(
        string $grantee,
        string $privilegeType,
        string $isGrantable,
    ): self {
        return self::granted(
            grantee: $grantee,
            privilegeType: $privilegeType,
            withGrantOption: 'YES' === strtoupper(trim($isGrantable)),
        );
    }

    public function grantsToPublic(): bool
    {
        return self::PUBLIC_GRANTEE === strtoupper($this->grantee);
    }

    public function equals(self $other): bool
    {
        return $this->grantee === $other->grantee
            && $this->privilegeType === $other->privilegeType
            && $this->withGrantOption === $other->withGrantOption;
    }
}
