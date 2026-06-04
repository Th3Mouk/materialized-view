<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Dependency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Dependency\DependencyGraph;
use Th3Mouk\MaterializedView\Core\Exception\DependencyCycleDetected;

#[Group('dependency')]
final class DependencyGraphTest extends TestCase
{
    public function testOrdersReferencedViewsBeforeTheirDependents(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.a', 'public.b', 'public.c'],
            [
                ['dependent' => 'public.c', 'referenced' => 'public.b'],
                ['dependent' => 'public.b', 'referenced' => 'public.a'],
            ],
        );

        self::assertSame(['public.a', 'public.b', 'public.c'], $graph->topologicallySorted());
    }

    public function testReverseOrderDropsDependentsBeforeReferencedViews(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.a', 'public.b', 'public.c'],
            [
                ['dependent' => 'public.c', 'referenced' => 'public.b'],
                ['dependent' => 'public.b', 'referenced' => 'public.a'],
            ],
        );

        self::assertSame(['public.c', 'public.b', 'public.a'], $graph->reverseTopologicallySorted());
    }

    public function testIsolatedNodesAreKeptAndSortedDeterministically(): void
    {
        $graph = DependencyGraph::fromEdges(['public.z', 'public.a', 'public.m'], []);

        self::assertSame(['public.a', 'public.m', 'public.z'], $graph->topologicallySorted());
    }

    public function testOrderingIsDeterministicAcrossIndependentBranches(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.root', 'public.left', 'public.right'],
            [
                ['dependent' => 'public.right', 'referenced' => 'public.root'],
                ['dependent' => 'public.left', 'referenced' => 'public.root'],
            ],
        );

        self::assertSame(['public.root', 'public.left', 'public.right'], $graph->topologicallySorted());
    }

    public function testNodesAreInferredFromEdgesWhenNotDeclared(): void
    {
        $graph = DependencyGraph::fromEdges(
            [],
            [['dependent' => 'public.b', 'referenced' => 'public.a']],
        );

        self::assertSame(['public.a', 'public.b'], $graph->topologicallySorted());
    }

    public function testRejectsSelfLoopAsCycle(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.a'],
            [['dependent' => 'public.a', 'referenced' => 'public.a']],
        );

        $this->expectException(DependencyCycleDetected::class);
        $this->expectExceptionMessage('public.a -> public.a');

        $graph->topologicallySorted();
    }

    public function testRejectsTwoNodeCycle(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.a', 'public.b'],
            [
                ['dependent' => 'public.a', 'referenced' => 'public.b'],
                ['dependent' => 'public.b', 'referenced' => 'public.a'],
            ],
        );

        $this->expectException(DependencyCycleDetected::class);
        $this->expectExceptionMessage('public.a -> public.b');

        $graph->topologicallySorted();
    }

    public function testRejectsLongerCycleWhileKeepingAcyclicPrefix(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.a', 'public.b', 'public.c'],
            [
                ['dependent' => 'public.b', 'referenced' => 'public.a'],
                ['dependent' => 'public.c', 'referenced' => 'public.b'],
                ['dependent' => 'public.b', 'referenced' => 'public.c'],
            ],
        );

        $this->expectException(DependencyCycleDetected::class);

        $graph->topologicallySorted();
    }

    public function testDuplicateEdgesDoNotCorruptInDegreeCounting(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.a', 'public.b'],
            [
                ['dependent' => 'public.b', 'referenced' => 'public.a'],
                ['dependent' => 'public.b', 'referenced' => 'public.a'],
            ],
        );

        self::assertSame(['public.a', 'public.b'], $graph->topologicallySorted());
    }

    public function testDirectDependentsAreSorted(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.root', 'public.z', 'public.a'],
            [
                ['dependent' => 'public.z', 'referenced' => 'public.root'],
                ['dependent' => 'public.a', 'referenced' => 'public.root'],
            ],
        );

        self::assertSame(['public.a', 'public.z'], $graph->directDependentsOf('public.root'));
    }

    public function testTransitiveDependentsTraverseTheWholeClosure(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.root', 'public.mid', 'public.leaf'],
            [
                ['dependent' => 'public.mid', 'referenced' => 'public.root'],
                ['dependent' => 'public.leaf', 'referenced' => 'public.mid'],
            ],
        );

        self::assertSame(['public.leaf', 'public.mid'], $graph->transitiveDependentsOf('public.root'));
    }

    public function testTransitiveDependentsAreEmptyForLeafView(): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.root', 'public.leaf'],
            [['dependent' => 'public.leaf', 'referenced' => 'public.root']],
        );

        self::assertSame([], $graph->transitiveDependentsOf('public.leaf'));
    }

    #[DataProvider('membershipProvider')]
    public function testHasReportsNodeMembership(string $node, bool $expected): void
    {
        $graph = DependencyGraph::fromEdges(
            ['public.a'],
            [['dependent' => 'public.b', 'referenced' => 'public.a']],
        );

        self::assertSame($expected, $graph->has($node));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function membershipProvider(): iterable
    {
        yield 'declared node' => ['public.a', true];
        yield 'node inferred from edge' => ['public.b', true];
        yield 'absent node' => ['public.missing', false];
    }
}
