<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Integration\Introspection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedView\Dbal\DbalConnection;

#[Group('integration')]
#[Group('introspection')]
final class PostgreSqlIntrospectionTest extends TestCase
{
    private const string SCHEMA = 'public';
    private const string VIEW = 'matview_introspection_probe';

    private Connection $connection;

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

        $this->dropProbe();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropProbe();
        }
    }

    public function testIntrospectsAnUnpopulatedThenPopulatedMaterializedView(): void
    {
        $name = MaterializedViewName::create(self::SCHEMA, self::VIEW);
        $driver = new DbalConnection($this->connection);
        $introspector = new PostgreSqlMaterializedViewIntrospector($driver);
        $readiness = new ReadinessChecker($driver);

        $this->connection->executeStatement(\sprintf(
            'CREATE MATERIALIZED VIEW %s.%s AS SELECT 1 AS id, 42 AS score WITH NO DATA',
            self::SCHEMA,
            self::VIEW,
        ));
        $this->connection->executeStatement(\sprintf(
            "COMMENT ON MATERIALIZED VIEW %s.%s IS '{\"th3mouk_materialized_view\":{\"hash\":\"probe\"}}'",
            self::SCHEMA,
            self::VIEW,
        ));

        self::assertTrue($introspector->exists($name));

        $view = $introspector->find($name);
        self::assertNotNull($view);
        self::assertSame('public.matview_introspection_probe', $view->name->qualifiedName());
        self::assertFalse($view->isPopulated);
        self::assertTrue($view->hasComment());
        self::assertNotNull($view->comment);
        self::assertStringContainsString('th3mouk_materialized_view', $view->comment);

        self::assertFalse($readiness->isReady($name));

        $this->connection->executeStatement(\sprintf(
            'CREATE UNIQUE INDEX ux_matview_introspection_probe ON %s.%s (id)',
            self::SCHEMA,
            self::VIEW,
        ));
        $this->connection->executeStatement(\sprintf(
            'REFRESH MATERIALIZED VIEW %s.%s WITH DATA',
            self::SCHEMA,
            self::VIEW,
        ));

        $readiness->forget($name);

        self::assertTrue($readiness->isReady($name));

        $refreshed = $introspector->find($name);
        self::assertNotNull($refreshed);
        self::assertTrue($refreshed->isPopulated);

        $indexes = $introspector->introspectIndexes($name);
        $indexNames = array_map(static fn ($index): string => $index->name, $indexes);
        self::assertContains('ux_matview_introspection_probe', $indexNames);
    }

    public function testFindReturnsNullForAbsentView(): void
    {
        $introspector = new PostgreSqlMaterializedViewIntrospector(new DbalConnection($this->connection));

        self::assertNull($introspector->find(MaterializedViewName::create(self::SCHEMA, 'matview_absent_probe')));
        self::assertFalse($introspector->exists(MaterializedViewName::create(self::SCHEMA, 'matview_absent_probe')));
    }

    private function dropProbe(): void
    {
        $this->connection->executeStatement(\sprintf(
            'DROP MATERIALIZED VIEW IF EXISTS %s.%s',
            self::SCHEMA,
            self::VIEW,
        ));
    }
}
