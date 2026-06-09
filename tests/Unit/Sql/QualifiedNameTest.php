<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Sql\QualifiedName;

#[Group('sql')]
final class QualifiedNameTest extends TestCase
{
    /**
     * @param array{schema: ?string, name: string, offsetAfter: int} $expected
     */
    #[DataProvider('scanProvider')]
    public function testScanReadsOneQualifiedIdentifier(string $input, int $offset, array $expected): void
    {
        $offsetAfter = -1;
        $scanned = QualifiedName::scan($input, $offset, $offsetAfter);

        self::assertNotNull($scanned);
        self::assertSame($expected['schema'], $scanned->schema);
        self::assertSame($expected['name'], $scanned->name);
        self::assertSame($expected['offsetAfter'], $offsetAfter);
    }

    /**
     * @return iterable<string, array{string, int, array{schema: ?string, name: string, offsetAfter: int}}>
     */
    public static function scanProvider(): iterable
    {
        yield 'unqualified, space-terminated' => [
            'orders because other objects', 0, ['schema' => null, 'name' => 'orders', 'offsetAfter' => 6],
        ];
        yield 'schema-qualified unquoted' => [
            'public.orders ', 0, ['schema' => 'public', 'name' => 'orders', 'offsetAfter' => 13],
        ];
        yield 'double-quoted with embedded dot' => [
            '"weird.name" rest', 0, ['schema' => null, 'name' => 'weird.name', 'offsetAfter' => 12],
        ];
        yield 'double-quoted MixedCase, schema-qualified' => [
            '"My Schema"."My View" depends', 0, ['schema' => 'My Schema', 'name' => 'My View', 'offsetAfter' => 21],
        ];
        yield 'double-quoted with escaped quote' => [
            '"a""b" tail', 0, ['schema' => null, 'name' => 'a"b', 'offsetAfter' => 6],
        ];
        yield 'scan from a non-zero offset' => [
            'view foo depends', 5, ['schema' => null, 'name' => 'foo', 'offsetAfter' => 8],
        ];
    }

    #[DataProvider('unscannableProvider')]
    public function testScanReturnsNullWhenNoCompleteIdentifierCanBeRead(string $input, int $offset): void
    {
        $offsetAfter = -1;

        self::assertNull(QualifiedName::scan($input, $offset, $offsetAfter));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unscannableProvider(): iterable
    {
        yield 'end of input' => ['view ', 5];
        yield 'offset past end' => ['abc', 10];
        yield 'unterminated quote' => ['"never closed', 0];
        yield 'empty unquoted token (leading space)' => [' leading', 0];
    }

    #[DataProvider('renderProvider')]
    public function testRenderProducesSqlSafeReQuotedForm(?string $schema, string $name, string $expected): void
    {
        self::assertSame($expected, new QualifiedName($schema, $name)->render());
    }

    /**
     * @return iterable<string, array{?string, string, string}>
     */
    public static function renderProvider(): iterable
    {
        yield 'simple unqualified' => [null, 'orders', 'orders'];
        yield 'simple qualified' => ['public', 'orders', 'public.orders'];
        yield 'mixed case needs quoting' => [null, 'MyView', '"MyView"'];
        yield 'embedded dot needs quoting' => [null, 'weird.name', '"weird.name"'];
        yield 'embedded quote is doubled' => [null, 'a"b', '"a""b"'];
        yield 'qualified with quoting on both parts' => ['My Schema', 'My View', '"My Schema"."My View"'];
    }

    public function testEqualsIsLogicalAndCaseSensitive(): void
    {
        self::assertTrue(new QualifiedName('public', 'orders')->equals(new QualifiedName('public', 'orders')));
        self::assertFalse(new QualifiedName('public', 'orders')->equals(new QualifiedName('public', 'Orders')));
        self::assertFalse(new QualifiedName(null, 'orders')->equals(new QualifiedName('public', 'orders')));
    }
}
