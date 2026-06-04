<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Privilege;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Privilege\ObjectPrivilege;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeSnapshot;

#[Group('privilege')]
final class PrivilegeSnapshotTest extends TestCase
{
    public function testEmptySnapshotReportsNoPrivileges(): void
    {
        $snapshot = PrivilegeSnapshot::empty(MaterializedViewName::create('public', 'sales_by_category'));

        self::assertTrue($snapshot->isEmpty());
        self::assertSame(0, $snapshot->count());
        self::assertSame([], $snapshot->all());
    }

    public function testRetainsViewAndPrivileges(): void
    {
        $view = MaterializedViewName::create('analytics', 'sales_by_category');

        $snapshot = PrivilegeSnapshot::forView($view, [
            ObjectPrivilege::granted('app', 'SELECT'),
            ObjectPrivilege::granted('app', 'INSERT'),
        ]);

        self::assertSame($view, $snapshot->view);
        self::assertFalse($snapshot->isEmpty());
        self::assertSame(2, $snapshot->count());
    }

    public function testDeduplicatesIdenticalGrants(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [
                ObjectPrivilege::granted('app', 'SELECT'),
                ObjectPrivilege::granted('app', 'SELECT'),
            ],
        );

        self::assertSame(1, $snapshot->count());
    }

    public function testKeepsGrantsThatDifferByGrantOption(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [
                ObjectPrivilege::granted('app', 'SELECT', withGrantOption: false),
                ObjectPrivilege::granted('app', 'SELECT', withGrantOption: true),
            ],
        );

        self::assertSame(2, $snapshot->count());
    }
}
