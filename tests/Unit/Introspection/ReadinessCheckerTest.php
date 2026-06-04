<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Introspection;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;

#[Group('introspection')]
final class ReadinessCheckerTest extends TestCase
{
    public function testReadsRelispopulatedFilteredOnMatviewRelkind(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::callback(static function (string $sql): bool {
                    self::assertStringContainsString('c.relispopulated', $sql);
                    self::assertStringContainsString("c.relkind = 'm'", $sql);
                    self::assertStringContainsString('n.nspname = :schema_name', $sql);
                    self::assertStringContainsString('c.relname = :view_name', $sql);

                    return true;
                }),
                ['schema_name' => 'public', 'view_name' => 'sales_by_category'],
            )
            ->willReturn(true);

        $checker = new ReadinessChecker($connection);

        self::assertTrue($checker->isReady(MaterializedViewName::fromString('public.sales_by_category')));
    }

    public function testMemoisesPopulationStatePerProcess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(true);

        $checker = new ReadinessChecker($connection);
        $name = MaterializedViewName::fromString('public.sales_by_category');

        self::assertTrue($checker->isReady($name));
        self::assertTrue($checker->isReady($name));
        self::assertTrue($checker->isReady($name));
    }

    public function testMemoisationIsScopedPerViewName(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturn(true);

        $checker = new ReadinessChecker($connection);

        $checker->isReady(MaterializedViewName::fromString('public.first'));
        $checker->isReady(MaterializedViewName::fromString('public.second'));
    }

    public function testForgetForcesAFreshCatalogReadForThatView(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturn(true);

        $checker = new ReadinessChecker($connection);
        $name = MaterializedViewName::fromString('public.sales_by_category');

        $checker->isReady($name);
        $checker->forget($name);
        $checker->isReady($name);
    }

    public function testForgetAllClearsTheWholeCache(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturn(true);

        $checker = new ReadinessChecker($connection);
        $name = MaterializedViewName::fromString('public.sales_by_category');

        $checker->isReady($name);
        $checker->forgetAll();
        $checker->isReady($name);
    }

    public function testEnsureReadablePassesWhenPopulated(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(true);

        $checker = new ReadinessChecker($connection);

        $checker->ensureReadable(MaterializedViewName::fromString('public.sales_by_category'));

        $this->expectNotToPerformAssertions();
    }

    public function testEnsureReadableThrowsWhenNotPopulated(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $checker = new ReadinessChecker($connection);

        $this->expectException(ViewNotPopulated::class);
        $this->expectExceptionMessage('public.sales_by_category');

        $checker->ensureReadable(MaterializedViewName::fromString('public.sales_by_category'));
    }

    #[DataProvider('populationFlagProvider')]
    public function testPopulationFlagCoercion(mixed $rawValue, bool $expected): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($rawValue);

        $checker = new ReadinessChecker($connection);

        self::assertSame($expected, $checker->isReady(MaterializedViewName::fromString('public.sales_by_category')));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function populationFlagProvider(): iterable
    {
        yield 'native bool true' => [true, true];
        yield 'native bool false' => [false, false];
        yield 'postgres char t' => ['t', true];
        yield 'postgres char f' => ['f', false];
        yield 'string one' => ['1', true];
        yield 'string zero' => ['0', false];
        yield 'absent row false' => [false, false];
        yield 'null' => [null, false];
    }
}
