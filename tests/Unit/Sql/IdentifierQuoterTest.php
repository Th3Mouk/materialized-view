<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sql;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

#[Group('sql')]
final class IdentifierQuoterTest extends TestCase
{
    private IdentifierQuoter $quoter;

    protected function setUp(): void
    {
        $this->quoter = IdentifierQuoter::forPlatform(new PostgreSQLPlatform());
    }

    public function testQuotesSimpleIdentifier(): void
    {
        self::assertSame('"orders"', $this->quoter->quoteIdentifier('orders'));
    }

    public function testQuotesQualifiedNameInTwoParts(): void
    {
        $name = MaterializedViewName::create('analytics', 'sales_by_category');

        self::assertSame(
            '"analytics"."sales_by_category"',
            $this->quoter->quoteQualifiedName($name),
        );
    }

    public function testQuotesColumnList(): void
    {
        self::assertSame(
            '"product_id", "score_id"',
            $this->quoter->quoteColumnList(['product_id', 'score_id']),
        );
    }

    public function testQuotesStringLiteralEscapingSingleQuotes(): void
    {
        self::assertSame(
            "'{\"k\":\"O''Brien\"}'",
            $this->quoter->quoteStringLiteral('{"k":"O\'Brien"}'),
        );
    }

    public function testEscapesEmbeddedDoubleQuotesInsteadOfInterpolatingRaw(): void
    {
        self::assertSame('"we""ird"', $this->quoter->quoteIdentifier('we"ird'));
    }

    public function testNeutralizesQuoteInjectionInIdentifier(): void
    {
        self::assertSame(
            '"score""; DROP TABLE secrets; --"',
            $this->quoter->quoteIdentifier('score"; DROP TABLE secrets; --'),
        );
    }
}
