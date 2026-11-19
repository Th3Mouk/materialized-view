<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Integration\Dependency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Dependency\DependencySafeStatement;
use Th3Mouk\MaterializedView\Core\Sql\DependencyConflictSqlState;

#[Group('dependency')]
#[Group('integration')]
final class DependencySafeStatementIntegrationTest extends TestCase
{
    private const string SCHEMA = 'mv_safe_stmt_it';

    private Connection $connection;

    protected function setUp(): void
    {
        $dsn = getenv('MATVIEW_TEST_DATABASE_URL');

        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('MATVIEW_TEST_DATABASE_URL is not configured.');
        }

        $parameters = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse($dsn);
        $this->connection = DriverManager::getConnection($parameters);

        $this->connection->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA '.self::SCHEMA);
        $this->connection->executeStatement('SET search_path TO '.self::SCHEMA);
        $this->connection->executeStatement('CREATE TABLE customers (id int PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE orders (id int PRIMARY KEY, customer_id int, total numeric)');
        $this->connection->executeStatement('ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id) NOT VALID');
        $this->connection->executeStatement('CREATE MATERIALIZED VIEW order_totals AS SELECT id, customer_id, total FROM orders');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
            $this->connection->close();
        }
    }

    public function testClassifiedStatementsRunWhileAMaterializedViewDependsOnTheTable(): void
    {
        $statements = [
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uniq_orders_customer ON orders (id, customer_id)',
            'DROP INDEX CONCURRENTLY IF EXISTS uniq_orders_customer',
            'CREATE INDEX CONCURRENTLY idx_orders_total ON orders (total)',
            'REINDEX INDEX CONCURRENTLY idx_orders_total',
            'ALTER TABLE ONLY orders VALIDATE CONSTRAINT fk_orders_customer',
            'ANALYZE orders',
            'VACUUM orders',
        ];

        foreach ($statements as $statement) {
            self::assertTrue(DependencySafeStatement::isSafe($statement), $statement);

            $this->connection->executeStatement($statement);
        }

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM pg_indexes WHERE schemaname = 'mv_safe_stmt_it' AND indexname = 'idx_orders_total'"));
    }

    public function testAColumnTypeChangeIsNotClassifiedSafeAndDoesRaiseADependencyConflict(): void
    {
        $statement = 'ALTER TABLE orders ALTER COLUMN total TYPE bigint';

        self::assertFalse(DependencySafeStatement::isSafe($statement));

        try {
            $this->connection->executeStatement($statement);
            self::fail('Expected the dependent materialized view to block the column type change.');
        } catch (DriverException $exception) {
            self::assertTrue(DependencyConflictSqlState::isDependencyConflict($exception));
        }
    }
}
