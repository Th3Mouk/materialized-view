<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sync;

final readonly class MaterializedViewComparisonPlan
{
    /**
     * @param list<MaterializedViewComparison> $comparisons
     */
    private function __construct(
        public array $comparisons,
    ) {
    }

    /**
     * @param list<MaterializedViewComparison> $comparisons
     */
    public static function of(array $comparisons): self
    {
        return new self(array_values($comparisons));
    }

    /**
     * @return list<MaterializedViewComparison>
     */
    public function toCreate(): array
    {
        return $this->withAction(SyncAction::Create);
    }

    /**
     * @return list<MaterializedViewComparison>
     */
    public function toRebuild(): array
    {
        return $this->withAction(SyncAction::Rebuild);
    }

    /**
     * @return list<MaterializedViewComparison>
     */
    public function upToDate(): array
    {
        return $this->withAction(SyncAction::UpToDate);
    }

    /**
     * @return list<MaterializedViewComparison>
     */
    public function orphans(): array
    {
        return $this->withAction(SyncAction::Orphan);
    }

    public function hasPendingWrites(): bool
    {
        return array_any($this->comparisons, fn ($comparison) => $comparison->action->requiresWrite());
    }

    public function forName(string $qualifiedName): ?MaterializedViewComparison
    {
        foreach ($this->comparisons as $comparison) {
            if ($comparison->name->qualifiedName() === $qualifiedName) {
                return $comparison;
            }
        }

        return null;
    }

    /**
     * @return list<MaterializedViewComparison>
     */
    private function withAction(SyncAction $action): array
    {
        return array_values(array_filter(
            $this->comparisons,
            static fn (MaterializedViewComparison $comparison): bool => $comparison->action === $action,
        ));
    }
}
