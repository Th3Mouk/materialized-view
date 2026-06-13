<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;
use Th3Mouk\MaterializedView\Core\Sql\MissingDependencySqlState;

#[Group('sync')]
final class MissingDependencySqlStateTest extends TestCase
{
    #[DataProvider('missingDependencySqlStateProvider')]
    public function testRecognisesMissingDependencySqlStates(string $sqlState): void
    {
        self::assertTrue(MissingDependencySqlState::isMissingDependency(
            new DatabaseException('driver failure', $sqlState),
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingDependencySqlStateProvider(): iterable
    {
        yield 'undefined table 42P01' => ['42P01'];
        yield 'invalid schema name 3F000' => ['3F000'];
    }

    public function testInspectsTheSqlStateThroughTheException(): void
    {
        self::assertTrue(MissingDependencySqlState::isMissingDependency(
            new DatabaseException('totally unrelated wording', '42P01'),
        ));
    }

    public function testDoesNotMatchOnMessageTextWhenSqlStateIsUnrelated(): void
    {
        self::assertFalse(MissingDependencySqlState::isMissingDependency(
            new DatabaseException('relation "x" does not exist undefined_table 42P01', '42703'),
        ));
    }

    public function testFindsTheSqlStateEvenWhenWrappedDeepInTheChain(): void
    {
        $inner = new DatabaseException('driver failure', '3F000');
        $outer = new RuntimeException('synchronizer failed', 0, $inner);

        self::assertTrue(MissingDependencySqlState::isMissingDependency($outer));
    }

    public function testIgnoresThrowablesWithoutASqlState(): void
    {
        self::assertFalse(MissingDependencySqlState::isMissingDependency(new RuntimeException('boom')));
        self::assertFalse(MissingDependencySqlState::isMissingDependency(new DatabaseException('boom')));
    }
}
