<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Dbal;

use Closure;
use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType as DbalParameterType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;
use Th3Mouk\MaterializedView\Core\Database\ParameterType;
use Th3Mouk\MaterializedView\Dbal\DbalConnection;

#[Group('dbal')]
final class DbalConnectionTest extends TestCase
{
    public function testExecuteStatementMapsParameterTypesAndCastsAffectedRows(): void
    {
        $inner = $this->createMock(DoctrineConnection::class);
        $inner->expects(self::once())
            ->method('executeStatement')
            ->with(
                'SELECT pg_advisory_lock(?, ?)',
                [10, 20],
                [DbalParameterType::INTEGER, DbalParameterType::INTEGER],
            )
            ->willReturn('3');

        $affected = new DbalConnection($inner)->executeStatement(
            'SELECT pg_advisory_lock(?, ?)',
            [10, 20],
            [ParameterType::Integer, ParameterType::Integer],
        );

        self::assertSame(3, $affected);
    }

    public function testFetchHelpersDelegateToTheConnection(): void
    {
        $inner = $this->createStub(DoctrineConnection::class);
        $inner->method('fetchOne')->willReturn('t');
        $inner->method('fetchAllAssociative')->willReturn([['id' => 1]]);
        $inner->method('fetchAssociative')->willReturn(['id' => 1]);

        $connection = new DbalConnection($inner);

        self::assertSame('t', $connection->fetchOne('SELECT 1'));
        self::assertSame([['id' => 1]], $connection->fetchAllAssociative('SELECT 1'));
        self::assertSame(['id' => 1], $connection->fetchAssociative('SELECT 1'));
    }

    public function testTransactionalRunsTheOperationWithTheAdapterItself(): void
    {
        $inner = $this->createStub(DoctrineConnection::class);
        $inner->method('transactional')->willReturnCallback(static fn (Closure $fn): mixed => $fn($inner));

        $connection = new DbalConnection($inner);

        $received = null;
        $result = $connection->transactional(function (Connection $passed) use (&$received): string {
            $received = $passed;

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame($connection, $received);
    }

    public function testWrapsDriverFailureAsDatabaseExceptionCarryingSqlState(): void
    {
        $inner = $this->createStub(DoctrineConnection::class);
        $inner->method('executeStatement')->willThrowException(
            new DriverException(
                new class('relation does not exist', '42P01') extends DriverAbstractException {},
                null,
            ),
        );

        try {
            new DbalConnection($inner)->executeStatement('REFRESH MATERIALIZED VIEW x');
            self::fail('Expected a DatabaseException.');
        } catch (DatabaseException $exception) {
            self::assertSame('42P01', $exception->sqlState());
        }
    }

    public function testEnsureConnectedToPrimaryRoutesPrimaryReplicaConnections(): void
    {
        $inner = $this->createMock(PrimaryReadReplicaConnection::class);
        $inner->expects(self::once())->method('ensureConnectedToPrimary');

        new DbalConnection($inner)->ensureConnectedToPrimary();
    }

    public function testEnsureConnectedToPrimaryIsANoOpForPlainConnections(): void
    {
        $inner = $this->createStub(DoctrineConnection::class);

        new DbalConnection($inner)->ensureConnectedToPrimary();

        $this->addToAssertionCount(1);
    }
}
