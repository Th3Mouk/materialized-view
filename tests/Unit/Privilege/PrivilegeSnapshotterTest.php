<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Privilege;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Privilege\ObjectPrivilege;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeSnapshotter;

#[Group('privilege')]
final class PrivilegeSnapshotterTest extends TestCase
{
    public function testCapturesObjectGrantsForTheView(): void
    {
        $view = MaterializedViewName::create('analytics', 'sales_by_category');

        $capturedQuery = null;
        $capturedParams = null;

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query, array $params) use (&$capturedQuery, &$capturedParams): array {
                $capturedQuery = $query;
                $capturedParams = $params;

                return [
                    ['grantee' => 'reporting_ro', 'privilege_type' => 'SELECT', 'is_grantable' => 'NO'],
                    ['grantee' => 'bi_admin', 'privilege_type' => 'SELECT', 'is_grantable' => 'YES'],
                ];
            });

        $snapshot = new PrivilegeSnapshotter($connection)->capture($view);

        self::assertIsString($capturedQuery);
        self::assertStringContainsString('information_schema.role_table_grants', $capturedQuery);
        self::assertSame(['schema' => 'analytics', 'name' => 'sales_by_category'], $capturedParams);

        self::assertSame($view, $snapshot->view);
        self::assertSame(2, $snapshot->count());

        $expected = [
            ObjectPrivilege::granted('reporting_ro', 'SELECT'),
            ObjectPrivilege::granted('bi_admin', 'SELECT', withGrantOption: true),
        ];

        foreach ($expected as $index => $privilege) {
            self::assertTrue($privilege->equals($snapshot->all()[$index]));
        }
    }

    public function testReturnsEmptySnapshotWhenNoGrantsExist(): void
    {
        $view = MaterializedViewName::create('public', 'sales_by_category');

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $snapshot = new PrivilegeSnapshotter($connection)->capture($view);

        self::assertTrue($snapshot->isEmpty());
        self::assertSame($view, $snapshot->view);
    }
}
