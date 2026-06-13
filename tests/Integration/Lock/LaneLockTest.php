<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Integration\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Lock\LaneLock;
use Th3Mouk\MaterializedView\Dbal\DbalConnection;

#[Group('lock')]
#[Group('integration')]
final class LaneLockTest extends TestCase
{
    private const int LANE_NAMESPACE = 392818;

    private Connection $holder;

    private Connection $contender;

    protected function setUp(): void
    {
        $dsn = getenv('MATVIEW_TEST_DATABASE_URL');

        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('MATVIEW_TEST_DATABASE_URL is not configured.');
        }

        $parameters = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse($dsn);

        $this->holder = DriverManager::getConnection($parameters);
        $this->contender = DriverManager::getConnection($parameters);
    }

    /**
     * @throws Exception
     */
    protected function tearDown(): void
    {
        $this->holder->executeStatement('SELECT pg_advisory_unlock_all()');
        $this->contender->executeStatement('SELECT pg_advisory_unlock_all()');
        $this->holder->close();
        $this->contender->close();
    }

    /**
     * @throws Exception
     */
    public function testHeldLaneBlocksAnotherSession(): void
    {
        $heldLane = new LaneLock(new DbalConnection($this->holder), self::LANE_NAMESPACE);
        $contendingLane = new LaneLock(new DbalConnection($this->contender), self::LANE_NAMESPACE);

        $heldLane->acquire();

        self::assertFalse(
            $contendingLane->tryAcquire(),
            'A second session must not acquire a lane lock already held in the same database.',
        );

        self::assertTrue($heldLane->release());

        self::assertTrue(
            $contendingLane->tryAcquire(),
            'Once released, the lane lock becomes available to another session.',
        );
        self::assertTrue($contendingLane->release());
    }

    /**
     * @throws Exception
     */
    public function testReleaseReportsWhetherLockWasHeld(): void
    {
        $lane = new LaneLock(new DbalConnection($this->holder), self::LANE_NAMESPACE);

        self::assertFalse($lane->release(), 'Releasing a lane lock never taken returns false.');

        $lane->acquire();

        self::assertTrue($lane->release());
    }
}
