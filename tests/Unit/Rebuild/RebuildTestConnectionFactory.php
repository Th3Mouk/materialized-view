<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Rebuild;

use Closure;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Database\Connection;

final readonly class RebuildTestConnectionFactory
{
    public static function create(TestCase $testCase): Connection&Stub
    {
        return self::mock($testCase);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    public static function withIndexRows(TestCase $testCase, array $rows): Connection&Stub
    {
        $connection = self::create($testCase);

        $connection
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        return $connection;
    }

    /**
     * @param list<string> $sink
     */
    public static function recording(TestCase $testCase, array &$sink): Connection&Stub
    {
        $connection = self::create($testCase);

        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$sink): int {
                $sink[] = $sql;

                return 0;
            });

        $connection
            ->method('transactional')
            ->willReturnCallback(static fn (Closure $func): mixed => $func($connection));

        return $connection;
    }

    private static function mock(TestCase $testCase): Connection&Stub
    {
        $build = Closure::bind(
            static fn (TestCase $case): Connection&Stub => $case->getStubBuilder(Connection::class)->getStub(),
            null,
            TestCase::class,
        );

        return $build($testCase);
    }
}
