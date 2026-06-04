<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Integration\Lock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Lock\LaneLock;
use Th3Mouk\MaterializedView\Core\Lock\StableLockKeyGenerator;
use Th3Mouk\MaterializedView\Core\Lock\ViewRefreshLock;

#[Group('lock')]
#[Group('integration')]
final class ViewRefreshLockTest extends TestCase
{
    private const int REFRESH_NAMESPACE = 392817;

    private const int LANE_NAMESPACE = 392818;

    private Connection $holder;

    private Connection $contender;

    private StableLockKeyGenerator $keyGenerator;

    protected function setUp(): void
    {
        $dsn = getenv('MATVIEW_TEST_DATABASE_URL');

        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('MATVIEW_TEST_DATABASE_URL is not configured.');
        }

        $parameters = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse($dsn);

        $this->holder = DriverManager::getConnection($parameters);
        $this->contender = DriverManager::getConnection($parameters);
        $this->keyGenerator = new StableLockKeyGenerator(self::REFRESH_NAMESPACE);
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
    public function testSameViewSerializesAcrossSessions(): void
    {
        $view = MaterializedViewName::fromString('public.sales_by_category');

        $heldLock = new ViewRefreshLock($this->holder, $this->keyGenerator);
        $contendingLock = new ViewRefreshLock($this->contender, $this->keyGenerator);

        $heldLock->acquire($view);

        self::assertFalse(
            $contendingLock->tryAcquire($view),
            'Two sessions must not hold the refresh lock of the same view simultaneously.',
        );

        self::assertTrue($heldLock->release($view));
        self::assertTrue($contendingLock->tryAcquire($view));
        self::assertTrue($contendingLock->release($view));
    }

    /**
     * @throws Exception
     */
    public function testDistinctViewsDoNotBlockEachOther(): void
    {
        $first = MaterializedViewName::fromString('public.sales_by_category');
        $second = MaterializedViewName::fromString('public.orders');

        $firstLock = new ViewRefreshLock($this->holder, $this->keyGenerator);
        $secondLock = new ViewRefreshLock($this->contender, $this->keyGenerator);

        $firstLock->acquire($first);

        self::assertTrue(
            $secondLock->tryAcquire($second),
            'Independent views use distinct keys and must not over-serialize.',
        );

        self::assertTrue($firstLock->release($first));
        self::assertTrue($secondLock->release($second));
    }

    /**
     * @throws Exception
     */
    public function testRefreshAndLaneKeySpacesDoNotCollide(): void
    {
        $view = MaterializedViewName::fromString('public.sales_by_category');
        $refreshLock = new ViewRefreshLock($this->holder, $this->keyGenerator);
        $laneLock = new LaneLock($this->contender, self::LANE_NAMESPACE);

        $refreshLock->acquire($view);

        self::assertTrue(
            $laneLock->tryAcquire(),
            'The two-key refresh space and the single-key lane space are independent in PostgreSQL.',
        );

        self::assertTrue($refreshLock->release($view));
        self::assertTrue($laneLock->release());
    }
}
