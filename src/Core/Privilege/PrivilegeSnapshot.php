<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Privilege;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class PrivilegeSnapshot
{
    /**
     * @param list<ObjectPrivilege> $privileges
     */
    private function __construct(
        public MaterializedViewName $view,
        public array $privileges,
    ) {
    }

    /**
     * @param iterable<ObjectPrivilege> $privileges
     */
    public static function forView(MaterializedViewName $view, iterable $privileges): self
    {
        $deduplicated = [];

        foreach ($privileges as $privilege) {
            $deduplicated[self::keyFor($privilege)] = $privilege;
        }

        return new self($view, array_values($deduplicated));
    }

    public static function empty(MaterializedViewName $view): self
    {
        return new self($view, []);
    }

    public function isEmpty(): bool
    {
        return [] === $this->privileges;
    }

    public function count(): int
    {
        return \count($this->privileges);
    }

    /**
     * @return list<ObjectPrivilege>
     */
    public function all(): array
    {
        return $this->privileges;
    }

    private static function keyFor(ObjectPrivilege $privilege): string
    {
        return $privilege->grantee
            ."\0".$privilege->privilegeType
            ."\0".($privilege->withGrantOption ? '1' : '0');
    }
}
