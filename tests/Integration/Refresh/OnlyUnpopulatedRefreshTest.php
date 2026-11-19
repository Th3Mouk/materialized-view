<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Integration\Refresh;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Refresh\RefreshOptions;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;

#[Group('integration')]
#[Group('refresh')]
final class OnlyUnpopulatedRefreshTest extends TestCase
{
    private Connection $connection;

    private MaterializedViewManager $manager;

    protected function setUp(): void
    {
        $dsn = getenv('MATVIEW_TEST_DATABASE_URL');

        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('MATVIEW_TEST_DATABASE_URL is not configured.');
        }

        try {
            $parser = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
            $this->connection = DriverManager::getConnection($parser->parse($dsn));
            $this->connection->executeStatement('SELECT 1');
        } catch (DbalException $exception) {
            self::markTestSkipped('PostgreSQL is not reachable: '.$exception->getMessage());
        }

        $this->dropViews();
        $this->connection->executeStatement('CREATE TABLE public.matview_only_unpopulated_source (id integer NOT NULL)');
        $this->manager = MaterializedViewManager::forConnection($this->connection);
        $this->manager->create($this->baseDefinition());
        $this->manager->create($this->rollupDefinition());
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropViews();
        }
    }

    public function testRefreshesBothUnpopulatedViewsInDependencyOrderThenSkipsThemOnTheNextRun(): void
    {
        $registry = MaterializedViewRegistry::fromDefinitions([$this->rollupDefinition(), $this->baseDefinition()]);
        $options = RefreshOptions::default()->withOnlyUnpopulated();

        $first = $this->manager->refreshAll($registry, $options);

        self::assertSame(['public.matview_only_unpopulated_base', 'public.matview_only_unpopulated_rollup'], $first->refreshed);
        self::assertTrue($this->isPopulated('matview_only_unpopulated_base'));
        self::assertTrue($this->isPopulated('matview_only_unpopulated_rollup'));

        $second = $this->manager->refreshAll($registry, $options);

        self::assertSame([], $second->refreshed);
        self::assertSame(['public.matview_only_unpopulated_base', 'public.matview_only_unpopulated_rollup'], $second->skippedPopulated);
    }

    public function testLeavesAPopulatedViewUntouchedAndRefreshesOnlyTheUnpopulatedOne(): void
    {
        $this->manager->refresh($this->baseDefinition());
        $this->connection->executeStatement('INSERT INTO matview_only_unpopulated_source (id) VALUES (1)');

        $outcome = $this->manager->refreshAll(
            MaterializedViewRegistry::fromDefinitions([$this->baseDefinition(), $this->rollupDefinition()]),
            RefreshOptions::default()->withOnlyUnpopulated(),
        );

        self::assertSame(['public.matview_only_unpopulated_rollup'], $outcome->refreshed);
        self::assertSame(['public.matview_only_unpopulated_base'], $outcome->skippedPopulated);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM matview_only_unpopulated_base'));
        self::assertTrue($this->isPopulated('matview_only_unpopulated_rollup'));
    }

    public function testASingleViewIsRefreshedOnlyWhenUnpopulated(): void
    {
        $options = RefreshOptions::default()->withOnlyUnpopulated();

        $first = $this->manager->refresh($this->baseDefinition(), $options);
        $second = $this->manager->refresh($this->baseDefinition(), $options);

        self::assertSame(['public.matview_only_unpopulated_base'], $first->refreshed);
        self::assertSame(['public.matview_only_unpopulated_base'], $second->skippedPopulated);
    }

    public function testAnAbsentViewIsSkippedWithTheOptionAndStillFailsWithoutIt(): void
    {
        $absent = MaterializedViewDefinition::create('public.matview_only_unpopulated_absent')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS id'));

        $outcome = $this->manager->refresh($absent, RefreshOptions::default()->withOnlyUnpopulated());

        self::assertSame(['public.matview_only_unpopulated_absent'], $outcome->skippedMissing);

        $this->expectException(DatabaseException::class);

        $this->manager->refresh($absent);
    }

    private function isPopulated(string $view): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT c.relispopulated FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = ?",
            [$view],
        );
    }

    private function baseDefinition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.matview_only_unpopulated_base')
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM matview_only_unpopulated_source'))
            ->withNoData();
    }

    private function rollupDefinition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.matview_only_unpopulated_rollup')
            ->fromSql(InlineSqlSource::fromString('SELECT id FROM public.matview_only_unpopulated_base'))
            ->withNoData();
    }

    private function dropViews(): void
    {
        $this->connection->executeStatement('DROP MATERIALIZED VIEW IF EXISTS public.matview_only_unpopulated_rollup');
        $this->connection->executeStatement('DROP MATERIALIZED VIEW IF EXISTS public.matview_only_unpopulated_base');
        $this->connection->executeStatement('DROP TABLE IF EXISTS public.matview_only_unpopulated_source');
    }
}
