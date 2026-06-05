<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Lock;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Lock\StableLockKeyGenerator;
use Th3Mouk\MaterializedView\Core\Lock\ViewRefreshLock;
use Th3Mouk\MaterializedView\Tests\Unit\Support\CollectingLogger;

#[Group('lock')]
final class ViewRefreshLockTest extends TestCase
{
    public function testTryAcquireEmitsAWarningOnContention(): void
    {
        $logger = new CollectingLogger();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $lock = new ViewRefreshLock($connection, new StableLockKeyGenerator(392817), $logger);

        self::assertFalse($lock->tryAcquire(MaterializedViewName::fromString('public.summary')));

        $warnings = $logger->recordsAtLevel(LogLevel::WARNING);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Could not acquire', $warnings[0]['message']);
        self::assertSame('public.summary', $warnings[0]['context']['view'] ?? null);
    }

    public function testTryAcquireEmitsDebugWhenLockIsGranted(): void
    {
        $logger = new CollectingLogger();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(1);

        $lock = new ViewRefreshLock($connection, new StableLockKeyGenerator(392817), $logger);

        self::assertTrue($lock->tryAcquire(MaterializedViewName::fromString('public.summary')));
        self::assertCount(0, $logger->recordsAtLevel(LogLevel::WARNING));
        self::assertNotEmpty($logger->recordsAtLevel(LogLevel::DEBUG));
    }
}
