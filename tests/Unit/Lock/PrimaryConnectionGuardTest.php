<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Lock\PrimaryConnectionGuard;

#[Group('lock')]
final class PrimaryConnectionGuardTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testRoutesPrimaryReplicaConnectionToPrimary(): void
    {
        $connection = $this->createMock(PrimaryReadReplicaConnection::class);
        $connection->expects(self::once())->method('ensureConnectedToPrimary');

        $guard = new PrimaryConnectionGuard($connection);

        $guard->ensureConnectedToPrimary();
    }

    /**
     * @throws Exception
     */
    public function testLeavesPlainConnectionUntouched(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method(self::anything());

        $guard = new PrimaryConnectionGuard($connection);

        $guard->ensureConnectedToPrimary();
    }
}
