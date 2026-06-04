<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

use Th3Mouk\MaterializedView\Core\Exception\DependencyCycleDetected;

final readonly class DependencyGraph
{
    /**
     * @param list<string>                $nodes
     * @param array<string, list<string>> $referencesByNode
     * @param array<string, list<string>> $dependentsByNode
     */
    private function __construct(
        private array $nodes,
        private array $referencesByNode,
        private array $dependentsByNode,
    ) {
    }

    /**
     * @param list<string>                                       $nodes
     * @param list<array{dependent: string, referenced: string}> $edges
     */
    public static function fromEdges(array $nodes, array $edges): self
    {
        $referencesByNode = [];
        $dependentsByNode = [];

        foreach ($nodes as $node) {
            $referencesByNode[$node] ??= [];
            $dependentsByNode[$node] ??= [];
        }

        foreach ($edges as $edge) {
            $dependent = $edge['dependent'];
            $referenced = $edge['referenced'];

            $referencesByNode[$dependent] ??= [];
            $dependentsByNode[$dependent] ??= [];
            $referencesByNode[$referenced] ??= [];
            $dependentsByNode[$referenced] ??= [];

            if (!\in_array($referenced, $referencesByNode[$dependent], true)) {
                $referencesByNode[$dependent][] = $referenced;
            }

            if (!\in_array($dependent, $dependentsByNode[$referenced], true)) {
                $dependentsByNode[$referenced][] = $dependent;
            }
        }

        return new self(
            array_keys($referencesByNode),
            $referencesByNode,
            $dependentsByNode,
        );
    }

    /**
     * @return list<string>
     */
    public function topologicallySorted(): array
    {
        $this->assertNoSelfLoop();

        $remainingReferences = array_map(
            \count(...),
            $this->referencesByNode,
        );

        $ready = [];
        foreach ($this->sortedNodes() as $node) {
            if (0 === $remainingReferences[$node]) {
                $ready[] = $node;
            }
        }

        $ordered = [];
        while ([] !== $ready) {
            $node = array_shift($ready);
            $ordered[] = $node;

            $unlocked = [];
            foreach ($this->dependentsByNode[$node] as $dependent) {
                --$remainingReferences[$dependent];

                if (0 === $remainingReferences[$dependent]) {
                    $unlocked[] = $dependent;
                }
            }

            sort($unlocked);
            foreach ($unlocked as $dependent) {
                $ready[] = $dependent;
            }
        }

        if (\count($ordered) !== \count($this->nodes)) {
            throw DependencyCycleDetected::amongViews($this->remainingCycle($remainingReferences));
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    public function reverseTopologicallySorted(): array
    {
        return array_reverse($this->topologicallySorted());
    }

    /**
     * @return list<string>
     */
    public function directDependentsOf(string $node): array
    {
        $dependents = $this->dependentsByNode[$node] ?? [];
        sort($dependents);

        return $dependents;
    }

    /**
     * @return list<string>
     */
    public function transitiveDependentsOf(string $node): array
    {
        $collected = [];
        $queue = $this->dependentsByNode[$node] ?? [];

        while ([] !== $queue) {
            $current = array_shift($queue);

            if (isset($collected[$current])) {
                continue;
            }

            $collected[$current] = true;

            foreach ($this->dependentsByNode[$current] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        $dependents = array_keys($collected);
        sort($dependents);

        return $dependents;
    }

    public function has(string $node): bool
    {
        return \array_key_exists($node, $this->referencesByNode);
    }

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        return $this->sortedNodes();
    }

    private function assertNoSelfLoop(): void
    {
        foreach ($this->referencesByNode as $node => $references) {
            if (\in_array($node, $references, true)) {
                throw DependencyCycleDetected::amongViews([$node, $node]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function sortedNodes(): array
    {
        $nodes = array_keys($this->referencesByNode);
        sort($nodes);

        return $nodes;
    }

    /**
     * @param array<string, int> $remainingReferences
     *
     * @return list<string>
     */
    private function remainingCycle(array $remainingReferences): array
    {
        $cycle = [];
        foreach ($this->sortedNodes() as $node) {
            if ($remainingReferences[$node] > 0) {
                $cycle[] = $node;
            }
        }

        return $cycle;
    }
}
