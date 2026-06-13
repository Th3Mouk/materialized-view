<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Integration\Pdo;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;
use Th3Mouk\MaterializedView\Core\Database\ParameterType;
use Th3Mouk\MaterializedView\Pdo\PdoConnection;

#[Group('integration')]
#[Group('pdo')]
final class PdoConnectionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('MATVIEW_TEST_DATABASE_URL');

        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('MATVIEW_TEST_DATABASE_URL is not configured.');
        }

        try {
            $this->pdo = self::pdoFromUrl($dsn);
            $this->pdo->query('SELECT 1');
        } catch (PDOException $exception) {
            self::markTestSkipped('PostgreSQL is not reachable: '.$exception->getMessage());
        }
    }

    public function testRunsQueriesThroughTheBarePdoHandle(): void
    {
        $connection = new PdoConnection($this->pdo);

        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));

        $rows = $connection->fetchAllAssociative(
            'SELECT id, label FROM (VALUES (1, :label)) AS t(id, label)',
            ['label' => 'matview'],
        );

        self::assertCount(1, $rows);
        self::assertSame('matview', $rows[0]['label']);
        self::assertSame(1, (int) $rows[0]['id']);

        self::assertFalse($connection->fetchAssociative('SELECT 1 WHERE false'));
    }

    public function testTypedParametersAndTransactionalCommit(): void
    {
        $connection = new PdoConnection($this->pdo);

        $count = $connection->transactional(static function (Connection $tx): int {
            $tx->executeStatement('CREATE TEMP TABLE matview_pdo_probe (id int) ON COMMIT DROP');
            $tx->executeStatement('INSERT INTO matview_pdo_probe (id) VALUES (?)', [7], [ParameterType::Integer]);

            return (int) $tx->fetchOne('SELECT count(*) FROM matview_pdo_probe WHERE id = ?', [7], [ParameterType::Integer]);
        });

        self::assertSame(1, $count);
    }

    public function testRollsBackWhenTheOperationThrows(): void
    {
        $connection = new PdoConnection($this->pdo);

        try {
            $connection->transactional(static function (Connection $tx): void {
                $tx->executeStatement('CREATE TEMP TABLE matview_pdo_rollback (id int)');

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertFalse($this->pdo->inTransaction(), 'The failed transaction must have been rolled back.');
    }

    public function testWrapsFailuresAsDatabaseExceptionWithSqlState(): void
    {
        $connection = new PdoConnection($this->pdo);

        try {
            $connection->executeStatement('REFRESH MATERIALIZED VIEW matview_pdo_absent');
            self::fail('Expected a DatabaseException.');
        } catch (DatabaseException $exception) {
            self::assertSame('42P01', $exception->sqlState());
        }
    }

    public function testEnsureConnectedToPrimaryIsANoOp(): void
    {
        new PdoConnection($this->pdo)->ensureConnectedToPrimary();

        $this->addToAssertionCount(1);
    }

    private static function pdoFromUrl(string $url): PDO
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['host'])) {
            self::markTestSkipped('MATVIEW_TEST_DATABASE_URL could not be parsed into a PDO DSN.');
        }

        $dsn = \sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $parts['host'],
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/'),
        );

        return new PDO($dsn, $parts['user'] ?? null, isset($parts['pass']) ? rawurldecode($parts['pass']) : null);
    }
}
