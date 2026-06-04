<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Privilege;

final readonly class GrantStatementGroup
{
    /**
     * @param array<string, string> $privilegeTypes
     */
    private function __construct(
        public string $grantee,
        public bool $withGrantOption,
        private array $privilegeTypes,
    ) {
    }

    public static function forGrantee(string $grantee, bool $withGrantOption): self
    {
        return new self($grantee, $withGrantOption, []);
    }

    public function withPrivilegeType(string $privilegeType): self
    {
        return new self(
            $this->grantee,
            $this->withGrantOption,
            [...$this->privilegeTypes, $privilegeType => $privilegeType],
        );
    }

    /**
     * @return list<string>
     */
    public function sortedPrivilegeTypes(): array
    {
        $privilegeTypes = array_values($this->privilegeTypes);
        sort($privilegeTypes);

        return $privilegeTypes;
    }

    /**
     * @return array{string, int}
     */
    public function sortKey(): array
    {
        return [$this->grantee, $this->withGrantOption ? 1 : 0];
    }
}
