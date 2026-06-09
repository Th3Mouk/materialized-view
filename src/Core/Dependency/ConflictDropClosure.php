<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

/**
 * The catalog-resolved closure of a dependency conflict: the managed materialized views
 * that must be dropped (in safe, dependents-first order) to clear the conflict, together
 * with any unmanaged dependents found in the same closure.
 *
 * The presence of any unmanaged dependent means the closure cannot be cleared safely:
 * the caller refuses the drop (the library never drops objects it does not manage, and
 * CASCADE is never implicit).
 */
final readonly class ConflictDropClosure
{
    /**
     * @param list<MaterializedViewName> $managedDropOrder
     * @param list<string>               $unmanagedDependents
     */
    private function __construct(
        public array $managedDropOrder,
        public array $unmanagedDependents,
    ) {
    }

    /**
     * @param list<MaterializedViewName> $managedDropOrder
     * @param list<string>               $unmanagedDependents
     */
    public static function of(array $managedDropOrder, array $unmanagedDependents): self
    {
        return new self($managedDropOrder, $unmanagedDependents);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function hasUnmanagedDependents(): bool
    {
        return [] !== $this->unmanagedDependents;
    }

    public function isEmpty(): bool
    {
        return [] === $this->managedDropOrder && [] === $this->unmanagedDependents;
    }

    /**
     * Concatenate another closure after this one, de-duplicating managed views by
     * qualified name (first occurrence wins, preserving each seed's dependents-first
     * order) and unioning the unmanaged dependents.
     */
    public function merge(self $other): self
    {
        $managed = $this->managedDropOrder;
        $seen = [];
        foreach ($managed as $name) {
            $seen[$name->qualifiedName()] = true;
        }

        foreach ($other->managedDropOrder as $name) {
            if (isset($seen[$name->qualifiedName()])) {
                continue;
            }

            $seen[$name->qualifiedName()] = true;
            $managed[] = $name;
        }

        $unmanaged = $this->unmanagedDependents;
        foreach ($other->unmanagedDependents as $dependent) {
            if (!\in_array($dependent, $unmanaged, true)) {
                $unmanaged[] = $dependent;
            }
        }

        sort($unmanaged);

        return new self($managed, $unmanaged);
    }
}
