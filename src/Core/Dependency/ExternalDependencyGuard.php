<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

final readonly class ExternalDependencyGuard
{
    private LoggerInterface $logger;

    public function __construct(
        private CatalogDependencyResolver $resolver,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws UnmanagedDependentFound
     */
    public function assertSafeToDrop(
        MaterializedViewName $name,
        MaterializedViewRegistry $registry,
        DropDependentPolicy $policy = DropDependentPolicy::Refuse,
    ): void {
        if (DropDependentPolicy::Cascade === $policy) {
            $this->noticeCascadeOverride($name, $registry, 'drop');

            return;
        }

        $unmanagedDependents = $this->unmanagedDependentsOf($name, $registry);

        if ([] !== $unmanagedDependents) {
            throw UnmanagedDependentFound::blockingDrop($name, $unmanagedDependents);
        }
    }

    /**
     * @throws UnmanagedDependentFound
     */
    public function assertSafeToRebuild(
        MaterializedViewName $name,
        MaterializedViewRegistry $registry,
        DropDependentPolicy $policy = DropDependentPolicy::Refuse,
    ): void {
        if (DropDependentPolicy::Cascade === $policy) {
            $this->noticeCascadeOverride($name, $registry, 'rebuild');

            return;
        }

        $unmanagedDependents = $this->unmanagedDependentsOf($name, $registry);

        if ([] !== $unmanagedDependents) {
            throw UnmanagedDependentFound::blockingRebuild($name, $unmanagedDependents);
        }
    }

    /**
     * @return list<string>
     */
    public function unmanagedDependentsOf(MaterializedViewName $name, MaterializedViewRegistry $registry): array
    {
        $dependentsByReferenced = $this->dependentsByReferenced();

        $unmanaged = [];
        $visited = [];
        $queue = [$name->qualifiedName()];

        while ([] !== $queue) {
            $current = array_shift($queue);

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($dependentsByReferenced[$current] ?? [] as $dependent => $dependentIsManagedCandidate) {
                if ($dependentIsManagedCandidate && $registry->has($dependent)) {
                    $queue[] = $dependent;

                    continue;
                }

                $unmanaged[$dependent] = true;
            }
        }

        $names = array_keys($unmanaged);
        sort($names);

        return $names;
    }

    private function noticeCascadeOverride(
        MaterializedViewName $name,
        MaterializedViewRegistry $registry,
        string $operation,
    ): void {
        $unmanagedDependents = $this->unmanagedDependentsOf($name, $registry);

        if ([] === $unmanagedDependents) {
            return;
        }

        $this->logger->notice(
            'CASCADE policy overrides unmanaged dependents while preparing to {operation} materialized view "{view}".',
            [
                'view' => $name->qualifiedName(),
                'operation' => $operation,
                'dependents' => $unmanagedDependents,
            ],
        );
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function dependentsByReferenced(): array
    {
        $dependentsByReferenced = [];

        foreach ($this->resolver->edges() as $edge) {
            $referenced = $edge->referenced->qualifiedName();
            $dependent = $edge->dependent->qualifiedName();

            $dependentsByReferenced[$referenced] ??= [];
            $dependentsByReferenced[$referenced][$dependent] = $edge->dependentIsMaterializedView();
        }

        return $dependentsByReferenced;
    }
}
