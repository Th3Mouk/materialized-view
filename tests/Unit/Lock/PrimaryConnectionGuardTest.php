<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Lock;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Lock\PrimaryConnectionGuard;

#[Group('lock')]
final class PrimaryConnectionGuardTest extends TestCase
{
    public function testDelegatesToTheConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('ensureConnectedToPrimary');

        new PrimaryConnectionGuard($connection)->ensureConnectedToPrimary();
    }
}
