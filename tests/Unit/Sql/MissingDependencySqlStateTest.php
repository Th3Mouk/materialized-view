<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sql;

use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Th3Mouk\MaterializedView\Core\Sql\MissingDependencySqlState;

#[Group('sync')]
final class MissingDependencySqlStateTest extends TestCase
{
    #[DataProvider('missingDependencySqlStateProvider')]
    public function testRecognisesMissingDependencySqlStates(string $sqlState): void
    {
        $exception = new DriverException(self::driverException($sqlState), null);

        self::assertTrue(MissingDependencySqlState::isMissingDependency($exception));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingDependencySqlStateProvider(): iterable
    {
        yield 'undefined table 42P01' => ['42P01'];
        yield 'invalid schema name 3F000' => ['3F000'];
    }

    public function testInspectsTheSqlStateThroughTheExceptionChainNotTheMessage(): void
    {
        $exception = new DriverException(self::driverException('42P01', 'totally unrelated wording'), null);

        self::assertTrue(MissingDependencySqlState::isMissingDependency($exception));
    }

    public function testDoesNotMatchOnMessageTextWhenSqlStateIsUnrelated(): void
    {
        $exception = new DriverException(
            self::driverException('42703', 'relation "x" does not exist undefined_table 42P01'),
            null,
        );

        self::assertFalse(MissingDependencySqlState::isMissingDependency($exception));
    }

    public function testFindsTheSqlStateEvenWhenWrappedDeepInTheChain(): void
    {
        $driver = self::driverException('3F000');
        $wrapped = new DriverException($driver, null);
        $outer = new RuntimeException('synchronizer failed', 0, $wrapped);

        self::assertTrue(MissingDependencySqlState::isMissingDependency($outer));
    }

    public function testIgnoresPlainThrowablesWithoutAnySqlState(): void
    {
        self::assertFalse(MissingDependencySqlState::isMissingDependency(new RuntimeException('boom')));
    }

    private static function driverException(string $sqlState, string $message = 'driver failure'): DriverAbstractException
    {
        return new class($message, $sqlState) extends DriverAbstractException {};
    }
}
