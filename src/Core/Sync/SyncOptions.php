<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sync;

use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;

final readonly class SyncOptions
{
    private function __construct(
        public bool $prune,
        public bool $refreshInitial,
        public bool $analyzeAfterSync,
        public bool $preserveExistingGrants,
        public ?string $sideBySideSwapToken,
        public MissingDependencyPolicy $missingDependencyPolicy,
        public DropDependentPolicy $dropDependentPolicy,
    ) {
    }

    public static function default(): self
    {
        return new self(
            prune: false,
            refreshInitial: false,
            analyzeAfterSync: true,
            preserveExistingGrants: true,
            sideBySideSwapToken: null,
            missingDependencyPolicy: MissingDependencyPolicy::Fail,
            dropDependentPolicy: DropDependentPolicy::Refuse,
        );
    }

    public function withPrune(bool $prune = true): self
    {
        return new self(
            prune: $prune,
            refreshInitial: $this->refreshInitial,
            analyzeAfterSync: $this->analyzeAfterSync,
            preserveExistingGrants: $this->preserveExistingGrants,
            sideBySideSwapToken: $this->sideBySideSwapToken,
            missingDependencyPolicy: $this->missingDependencyPolicy,
            dropDependentPolicy: $this->dropDependentPolicy,
        );
    }

    public function withRefreshInitial(bool $refreshInitial = true): self
    {
        return new self(
            prune: $this->prune,
            refreshInitial: $refreshInitial,
            analyzeAfterSync: $this->analyzeAfterSync,
            preserveExistingGrants: $this->preserveExistingGrants,
            sideBySideSwapToken: $this->sideBySideSwapToken,
            missingDependencyPolicy: $this->missingDependencyPolicy,
            dropDependentPolicy: $this->dropDependentPolicy,
        );
    }

    public function withAnalyzeAfterSync(bool $analyzeAfterSync = true): self
    {
        return new self(
            prune: $this->prune,
            refreshInitial: $this->refreshInitial,
            analyzeAfterSync: $analyzeAfterSync,
            preserveExistingGrants: $this->preserveExistingGrants,
            sideBySideSwapToken: $this->sideBySideSwapToken,
            missingDependencyPolicy: $this->missingDependencyPolicy,
            dropDependentPolicy: $this->dropDependentPolicy,
        );
    }

    public function withPreserveExistingGrants(bool $preserveExistingGrants = true): self
    {
        return new self(
            prune: $this->prune,
            refreshInitial: $this->refreshInitial,
            analyzeAfterSync: $this->analyzeAfterSync,
            preserveExistingGrants: $preserveExistingGrants,
            sideBySideSwapToken: $this->sideBySideSwapToken,
            missingDependencyPolicy: $this->missingDependencyPolicy,
            dropDependentPolicy: $this->dropDependentPolicy,
        );
    }

    public function withSideBySideSwapToken(?string $sideBySideSwapToken): self
    {
        return new self(
            prune: $this->prune,
            refreshInitial: $this->refreshInitial,
            analyzeAfterSync: $this->analyzeAfterSync,
            preserveExistingGrants: $this->preserveExistingGrants,
            sideBySideSwapToken: $sideBySideSwapToken,
            missingDependencyPolicy: $this->missingDependencyPolicy,
            dropDependentPolicy: $this->dropDependentPolicy,
        );
    }

    public function withMissingDependencyPolicy(MissingDependencyPolicy $missingDependencyPolicy): self
    {
        return new self(
            prune: $this->prune,
            refreshInitial: $this->refreshInitial,
            analyzeAfterSync: $this->analyzeAfterSync,
            preserveExistingGrants: $this->preserveExistingGrants,
            sideBySideSwapToken: $this->sideBySideSwapToken,
            missingDependencyPolicy: $missingDependencyPolicy,
            dropDependentPolicy: $this->dropDependentPolicy,
        );
    }

    public function withDropDependentPolicy(DropDependentPolicy $dropDependentPolicy): self
    {
        return new self(
            prune: $this->prune,
            refreshInitial: $this->refreshInitial,
            analyzeAfterSync: $this->analyzeAfterSync,
            preserveExistingGrants: $this->preserveExistingGrants,
            sideBySideSwapToken: $this->sideBySideSwapToken,
            missingDependencyPolicy: $this->missingDependencyPolicy,
            dropDependentPolicy: $dropDependentPolicy,
        );
    }
}
