<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Integration\Dependency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Dependency\CatalogDependencyResolver;
use Th3Mouk\MaterializedView\Core\Dependency\ParsedDependentKind;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sql\QualifiedName;

#[Group('dependency')]
#[Group('integration')]
final class ConflictClosureIntegrationTest extends TestCase
{
    private const string SCHEMA = 'mv_conflict_it';

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
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
            $this->connection->close();
        }
    }

    public function testParsesAndSurgicallyClearsADropColumnConflict(): void
    {
        $this->connection->executeStatement('CREATE TABLE orders (id int PRIMARY KEY, total numeric)');
        $this->createManagedMatview('order_totals', 'SELECT id, total FROM orders');

        $conflict = $this->provoke('ALTER TABLE orders DROP COLUMN total');

        self::assertSame('2BP01', $conflict->sqlState());
        self::assertTrue($conflict->isParsed());
        self::assertSame('orders', $conflict->blockedRelation()?->name);
        self::assertCount(1, $conflict->dependents());
        self::assertSame(ParsedDependentKind::MaterializedView, $conflict->dependents()[0]->kind);
        self::assertSame('order_totals', $conflict->dependents()[0]->name->name);

        $dropped = MaterializedViewManager::forConnection($this->connection)->dropConflictClosure($conflict);

        self::assertCount(1, $dropped);
        self::assertSame('mv_conflict_it.order_totals', $dropped[0]->qualifiedName());
        self::assertNull($this->relationOid('order_totals'), 'The conflicting managed matview must be gone.');

        $this->connection->executeStatement('ALTER TABLE orders DROP COLUMN total');
        self::assertNull($this->columnType('orders', 'total'), 'The previously blocked column drop now succeeds.');
    }

    public function testResolvesTheManagedDropClosureFromTheBlockedTable(): void
    {
        $this->connection->executeStatement('CREATE TABLE orders (id int PRIMARY KEY, total numeric)');
        $this->createManagedMatview('order_totals', 'SELECT id, total FROM orders');
        $this->createManagedMatview('order_totals_rollup', 'SELECT id FROM order_totals');

        $closure = new CatalogDependencyResolver($this->connection)
            ->resolveConflictClosure(new QualifiedName(self::SCHEMA, 'orders'));

        self::assertFalse($closure->hasUnmanagedDependents());

        $order = array_map(
            static fn ($name): string => $name->name,
            $closure->managedDropOrder,
        );
        self::assertSame(['order_totals_rollup', 'order_totals'], $order, 'A dependent matview must be dropped before the one it depends on.');
    }

    public function testRefusesWhenAnUnmanagedDependentIsInTheClosure(): void
    {
        $this->connection->executeStatement('CREATE TABLE orders (id int PRIMARY KEY, total numeric)');
        $this->createManagedMatview('order_totals', 'SELECT id, total FROM orders');
        $this->connection->executeStatement('CREATE VIEW report_v AS SELECT id FROM order_totals');

        $conflict = $this->provoke('ALTER TABLE orders DROP COLUMN total');

        try {
            MaterializedViewManager::forConnection($this->connection)->dropConflictClosure($conflict);
            self::fail('Expected the unmanaged dependent to block the reactive drop.');
        } catch (UnmanagedDependentFound $exception) {
            self::assertStringContainsString('report_v', $exception->getMessage());
        }

        self::assertNotNull($this->relationOid('order_totals'), 'The managed matview must survive a refused drop.');
        self::assertNotNull($this->relationOid('report_v'), 'The unmanaged view must survive a refused drop.');
    }

    public function testParsesAndClearsAnAlterColumnTypeRuleConflict(): void
    {
        $this->connection->executeStatement('CREATE TABLE orders (id int PRIMARY KEY, total numeric)');
        $this->createManagedMatview('order_totals', 'SELECT id, total FROM orders');

        $conflict = $this->provoke('ALTER TABLE orders ALTER COLUMN total TYPE text USING total::text');

        self::assertSame('0A000', $conflict->sqlState());
        self::assertNull($conflict->blockedRelation(), '0A000 names the dependent only, via its rewrite rule.');
        self::assertTrue($conflict->isParsed());
        self::assertSame('order_totals', $conflict->dependents()[0]->name->name);

        $dropped = MaterializedViewManager::forConnection($this->connection)->dropConflictClosure($conflict);

        self::assertCount(1, $dropped);
        self::assertNull($this->relationOid('order_totals'));

        $this->connection->executeStatement('ALTER TABLE orders ALTER COLUMN total TYPE text USING total::text');
        self::assertSame('text', $this->columnType('orders', 'total'), 'The previously blocked ALTER TYPE now succeeds.');
    }

    private function createManagedMatview(string $name, string $select): void
    {
        $this->connection->executeStatement(\sprintf('CREATE MATERIALIZED VIEW %s AS %s', $name, $select));

        $marker = ManagementMarker::create('testhash-'.$name)->toJson();
        $this->connection->executeStatement(\sprintf(
            'COMMENT ON MATERIALIZED VIEW %s IS %s',
            $name,
            $this->connection->getDatabasePlatform()->quoteStringLiteral($marker),
        ));
    }

    private function provoke(string $statement): PostgresDependencyConflict
    {
        try {
            $this->connection->executeStatement($statement);
        } catch (DriverException $exception) {
            $conflict = PostgresDependencyConflict::fromDriverException($exception);
            self::assertNotNull($conflict, 'Expected a gated dependency-conflict SQLSTATE.');

            return $conflict;
        }

        self::fail('Expected the DDL statement to be blocked by a dependent object.');
    }

    private function columnType(string $table, string $column): ?string
    {
        $type = $this->connection->fetchOne(
            'SELECT data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table AND column_name = :column',
            ['schema' => self::SCHEMA, 'table' => $table, 'column' => $column],
        );

        return false === $type ? null : (string) $type;
    }

    private function relationOid(string $relation): ?int
    {
        $oid = $this->connection->fetchOne(
            'SELECT to_regclass(:relation)::oid',
            ['relation' => self::SCHEMA.'.'.$relation],
        );

        return (null === $oid || false === $oid) ? null : (int) $oid;
    }
}
