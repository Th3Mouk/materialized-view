<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Dependency;

use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Dependency\ParsedDependentKind;
use Th3Mouk\MaterializedView\Core\Dependency\PostgresDependencyConflict;

#[Group('sync')]
final class PostgresDependencyConflictTest extends TestCase
{
    public function testReturnsNullForANonGatedSqlState(): void
    {
        self::assertNull(PostgresDependencyConflict::fromRawError('42P01', 'ERROR:  relation "x" does not exist'));
    }

    public function testParsesADropTableConflict(): void
    {
        $conflict = PostgresDependencyConflict::fromRawError('2BP01', <<<'TXT'
            ERROR:  cannot drop table orders because other objects depend on it
            DETAIL:  materialized view order_totals depends on table orders
            HINT:  Use DROP ... CASCADE to drop the dependent objects too.
            TXT);

        self::assertNotNull($conflict);
        self::assertSame('2BP01', $conflict->sqlState());
        self::assertNotNull($conflict->blockedRelation());
        self::assertSame('orders', $conflict->blockedRelation()->name);
        self::assertNull($conflict->blockedRelation()->schema);
        self::assertTrue($conflict->isParsed());
        self::assertFalse($conflict->wasTruncated());
        self::assertCount(1, $conflict->dependents());
        self::assertSame(ParsedDependentKind::MaterializedView, $conflict->dependents()[0]->kind);
        self::assertSame('order_totals', $conflict->dependents()[0]->name->name);
    }

    public function testParsesADropColumnConflictAndIsNotMisledByAColumnNamedLikeTheMarker(): void
    {
        $conflict = PostgresDependencyConflict::fromRawError('2BP01', <<<'TXT'
            ERROR:  cannot drop column "x of table y" of table orders because other objects depend on it
            DETAIL:  materialized view order_totals depends on column "x of table y" of table orders
            TXT);

        self::assertNotNull($conflict);
        self::assertNotNull($conflict->blockedRelation());
        self::assertSame('orders', $conflict->blockedRelation()->name, 'The blocked relation must be the table, not the decoy column name.');
    }

    public function testParsesASchemaQualifiedDoubleQuotedDependent(): void
    {
        $conflict = PostgresDependencyConflict::fromRawError('2BP01', <<<'TXT'
            ERROR:  cannot drop table "Sales"."Orders" because other objects depend on it
            DETAIL:  materialized view "Reporting"."Order Totals" depends on table "Sales"."Orders"
            TXT);

        self::assertNotNull($conflict);
        self::assertNotNull($conflict->blockedRelation());
        self::assertSame('Sales', $conflict->blockedRelation()->schema);
        self::assertSame('Orders', $conflict->blockedRelation()->name);
        self::assertCount(1, $conflict->dependents());
        self::assertSame('Reporting', $conflict->dependents()[0]->name->schema);
        self::assertSame('Order Totals', $conflict->dependents()[0]->name->name);
    }

    public function testParsesAnAlterColumnTypeRuleConflict(): void
    {
        $conflict = PostgresDependencyConflict::fromRawError('0A000', <<<'TXT'
            ERROR:  cannot alter type of a column used by a view or rule
            DETAIL:  rule _RETURN on materialized view order_totals depends on column "total"
            TXT);

        self::assertNotNull($conflict);
        self::assertSame('0A000', $conflict->sqlState());
        self::assertNull($conflict->blockedRelation(), '0A000 names neither table nor column on the ERROR line.');
        self::assertCount(1, $conflict->dependents());
        self::assertSame(ParsedDependentKind::MaterializedView, $conflict->dependents()[0]->kind);
        self::assertSame('order_totals', $conflict->dependents()[0]->name->name);
    }

    public function testFlagsTruncationWhenPostgresCapsThePrintedDependentList(): void
    {
        $conflict = PostgresDependencyConflict::fromRawError('2BP01', <<<'TXT'
            ERROR:  cannot drop table base because other objects depend on it
            DETAIL:  materialized view a depends on table base
            materialized view b depends on table base
            and 3 other objects (see server log for list)
            TXT);

        self::assertNotNull($conflict);
        self::assertTrue($conflict->wasTruncated());
        self::assertCount(2, $conflict->dependents());
    }

    public function testDistinguishesAPlainViewDependentFromAMaterializedView(): void
    {
        $conflict = PostgresDependencyConflict::fromRawError('2BP01', <<<'TXT'
            ERROR:  cannot drop table orders because other objects depend on it
            DETAIL:  view orders_v depends on table orders
            TXT);

        self::assertNotNull($conflict);
        self::assertCount(1, $conflict->dependents());
        self::assertSame(ParsedDependentKind::View, $conflict->dependents()[0]->kind);
    }

    public function testIsParsedFalseWhenTheTextIsAbsentOrNonEnglish(): void
    {
        $conflict = PostgresDependencyConflict::fromRawError('2BP01', <<<'TXT'
            FEHLER:  Tabelle „orders" kann nicht gelöscht werden, weil andere Objekte davon abhängen
            DETAIL:  materialisierte Sicht order_totals hängt von Tabelle orders ab
            TXT);

        self::assertNotNull($conflict, 'A gated SQLSTATE always yields an object, even when the text is unparsable.');
        self::assertNull($conflict->blockedRelation());
        self::assertFalse($conflict->isParsed());
        self::assertSame([], $conflict->dependents());
    }

    public function testBuildsFromADriverExceptionUsingItsSqlStateAndMessage(): void
    {
        $message = "ERROR:  cannot drop table orders because other objects depend on it\nDETAIL:  materialized view order_totals depends on table orders";
        $exception = new DriverException(
            new class($message, '2BP01') extends DriverAbstractException {},
            null,
        );

        $conflict = PostgresDependencyConflict::fromDriverException($exception);

        self::assertNotNull($conflict);
        self::assertSame('2BP01', $conflict->sqlState());
        self::assertNotNull($conflict->blockedRelation());
        self::assertSame('orders', $conflict->blockedRelation()->name);
    }

    public function testReturnsNullFromADriverExceptionWithAnUnrelatedSqlState(): void
    {
        $exception = new DriverException(
            new class('ERROR:  deadlock detected', '40P01') extends DriverAbstractException {},
            null,
        );

        self::assertNull(PostgresDependencyConflict::fromDriverException($exception));
    }
}
