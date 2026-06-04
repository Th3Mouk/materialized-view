<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

final readonly class RebuildContext
{
    /**
     * @param list<string>        $grantStatements
     * @param list<DependentView> $dependents
     */
    private function __construct(
        public string $managementComment,
        public array $grantStatements,
        public array $dependents,
        public bool $dropCascade,
    ) {
    }

    /**
     * @param list<string>        $grantStatements
     * @param list<DependentView> $dependents
     */
    public static function create(
        string $managementComment,
        array $grantStatements = [],
        array $dependents = [],
        bool $dropCascade = false,
    ): self {
        return new self(
            $managementComment,
            array_values($grantStatements),
            array_values($dependents),
            $dropCascade,
        );
    }

    public function hasDependents(): bool
    {
        return [] !== $this->dependents;
    }

    /**
     * @return list<DependentView>
     */
    public function unmanagedDependents(): array
    {
        return array_values(array_filter(
            $this->dependents,
            static fn (DependentView $dependent): bool => !$dependent->managed,
        ));
    }

    /**
     * @return list<string>
     */
    public function dependentNames(): array
    {
        return array_map(
            static fn (DependentView $dependent): string => $dependent->name->qualifiedName(),
            $this->dependents,
        );
    }

    /**
     * @return list<string>
     */
    public function unmanagedDependentNames(): array
    {
        return array_map(
            static fn (DependentView $dependent): string => $dependent->name->qualifiedName(),
            $this->unmanagedDependents(),
        );
    }
}
