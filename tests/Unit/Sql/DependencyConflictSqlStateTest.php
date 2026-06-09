<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sql;

use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Th3Mouk\MaterializedView\Core\Sql\DependencyConflictSqlState;

#[Group('sql')]
final class DependencyConflictSqlStateTest extends TestCase
{
    #[DataProvider('dependencyConflictSqlStateProvider')]
    public function testRecognisesDependencyConflictSqlStates(string $sqlState): void
    {
        $exception = new DriverException(self::driverException($sqlState), null);

        self::assertTrue(DependencyConflictSqlState::isDependencyConflict($exception));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dependencyConflictSqlStateProvider(): iterable
    {
        yield 'dependent objects still exist 2BP01' => ['2BP01'];
        yield 'feature not supported 0A000' => ['0A000'];
    }

    public function testInspectsTheSqlStateThroughTheExceptionChainNotTheMessage(): void
    {
        $exception = new DriverException(self::driverException('2BP01', 'totally unrelated wording'), null);

        self::assertTrue(DependencyConflictSqlState::isDependencyConflict($exception));
    }

    public function testDoesNotMatchOnMessageTextWhenSqlStateIsUnrelated(): void
    {
        $exception = new DriverException(
            self::driverException('42703', 'cannot drop column foo dependent_objects_still_exist 2BP01'),
            null,
        );

        self::assertFalse(DependencyConflictSqlState::isDependencyConflict($exception));
    }

    public function testFindsTheSqlStateEvenWhenWrappedDeepInTheChain(): void
    {
        $driver = self::driverException('0A000');
        $wrapped = new DriverException($driver, null);
        $outer = new RuntimeException('migration failed', 0, $wrapped);

        self::assertTrue(DependencyConflictSqlState::isDependencyConflict($outer));
    }

    public function testIgnoresPlainThrowablesWithoutAnySqlState(): void
    {
        self::assertFalse(DependencyConflictSqlState::isDependencyConflict(new RuntimeException('boom')));
    }

    public function testResolveSqlStateReturnsTheStateOrNull(): void
    {
        self::assertSame(
            '2BP01',
            DependencyConflictSqlState::resolveSqlState(new DriverException(self::driverException('2BP01'), null)),
        );
        self::assertNull(DependencyConflictSqlState::resolveSqlState(new RuntimeException('no sqlstate here')));
    }

    public function testIsDependencyConflictStateGatesOnTheTwoCodesOnly(): void
    {
        self::assertTrue(DependencyConflictSqlState::isDependencyConflictState('2BP01'));
        self::assertTrue(DependencyConflictSqlState::isDependencyConflictState('0A000'));
        self::assertFalse(DependencyConflictSqlState::isDependencyConflictState('42P01'));
        self::assertFalse(DependencyConflictSqlState::isDependencyConflictState(null));
    }

    private static function driverException(string $sqlState, string $message = 'driver failure'): DriverAbstractException
    {
        return new class($message, $sqlState) extends DriverAbstractException {};
    }
}
