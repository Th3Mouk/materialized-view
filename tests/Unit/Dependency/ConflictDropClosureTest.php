<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Dependency;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Dependency\ConflictDropClosure;

#[Group('sync')]
final class ConflictDropClosureTest extends TestCase
{
    public function testEmptyClosureIsEmptyAndHasNoUnmanagedDependents(): void
    {
        $closure = ConflictDropClosure::empty();

        self::assertTrue($closure->isEmpty());
        self::assertFalse($closure->hasUnmanagedDependents());
        self::assertSame([], $closure->managedDropOrder);
        self::assertSame([], $closure->unmanagedDependents);
    }

    public function testReportsUnmanagedDependents(): void
    {
        $closure = ConflictDropClosure::of([], ['public.legacy_view']);

        self::assertFalse($closure->isEmpty());
        self::assertTrue($closure->hasUnmanagedDependents());
    }

    public function testMergeConcatenatesManagedOrderDeduplicatingByQualifiedName(): void
    {
        $a = MaterializedViewName::create('public', 'a');
        $b = MaterializedViewName::create('public', 'b');
        $bAgain = MaterializedViewName::create('public', 'b');
        $c = MaterializedViewName::create('public', 'c');

        $merged = ConflictDropClosure::of([$a, $b], [])
            ->merge(ConflictDropClosure::of([$bAgain, $c], []));

        $names = array_map(static fn (MaterializedViewName $name): string => $name->qualifiedName(), $merged->managedDropOrder);

        self::assertSame(['public.a', 'public.b', 'public.c'], $names);
    }

    public function testMergeUnionsAndSortsUnmanagedDependents(): void
    {
        $merged = ConflictDropClosure::of([], ['public.z_view', 'public.a_view'])
            ->merge(ConflictDropClosure::of([], ['public.a_view', 'public.m_view']));

        self::assertSame(['public.a_view', 'public.m_view', 'public.z_view'], $merged->unmanagedDependents);
    }
}
