<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Hashing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Hashing\SqlCanonicalizer;

#[Group('hashing')]
final class SqlCanonicalizerTest extends TestCase
{
    private SqlCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        $this->canonicalizer = new SqlCanonicalizer();
    }

    public function testTrimsLeadingAndTrailingWhitespace(): void
    {
        self::assertSame('SELECT 1', $this->canonicalizer->canonicalize("  \n\tSELECT 1\n  "));
    }

    public function testCollapsesConsecutiveWhitespaceOutsideStrings(): void
    {
        self::assertSame(
            'SELECT a, b FROM t',
            $this->canonicalizer->canonicalize("SELECT    a,\n\n  b\tFROM     t"),
        );
    }

    public function testNormalisesLineEndings(): void
    {
        $crlf = $this->canonicalizer->canonicalize("SELECT a\r\nFROM t");
        $lf = $this->canonicalizer->canonicalize("SELECT a\nFROM t");
        $cr = $this->canonicalizer->canonicalize("SELECT a\rFROM t");

        self::assertSame('SELECT a FROM t', $crlf);
        self::assertSame($lf, $crlf);
        self::assertSame($lf, $cr);
    }

    public function testDropsTrailingSemicolon(): void
    {
        self::assertSame('SELECT 1', $this->canonicalizer->canonicalize('SELECT 1;'));
    }

    public function testDropsTrailingSemicolonWithSurroundingWhitespace(): void
    {
        self::assertSame('SELECT 1', $this->canonicalizer->canonicalize("SELECT 1 ;  \n"));
    }

    public function testKeepsNonTrailingSemicolonsUntouched(): void
    {
        self::assertSame(
            "SELECT ';' AS sep",
            $this->canonicalizer->canonicalize("SELECT ';' AS sep"),
        );
    }

    #[DataProvider('commentProvider')]
    public function testStripsComments(string $sql): void
    {
        self::assertSame('SELECT a FROM t', $this->canonicalizer->canonicalize($sql));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commentProvider(): iterable
    {
        yield 'trailing line comment' => ["SELECT a FROM t -- the projection\n"];
        yield 'leading line comment' => ["-- header\nSELECT a FROM t"];
        yield 'interleaved line comments' => ["SELECT a -- col\nFROM t -- table\n"];
        yield 'block comment inline' => ['SELECT a /* col */ FROM t'];
        yield 'block comment spanning lines' => ["SELECT a\n/*\n multi\n line\n*/\nFROM t"];
        yield 'nested block comment' => ['SELECT a /* outer /* inner */ still outer */ FROM t'];
        yield 'block then line comment' => ["SELECT a /* x */ FROM t -- trailing\n"];
    }

    public function testPreservesWhitespaceInsideSingleQuotedStrings(): void
    {
        self::assertSame(
            "SELECT 'two  spaces' AS label",
            $this->canonicalizer->canonicalize("SELECT 'two  spaces'   AS   label"),
        );
    }

    public function testPreservesNewlinesInsideSingleQuotedStrings(): void
    {
        self::assertSame(
            "SELECT 'line1\nline2' AS label",
            $this->canonicalizer->canonicalize("SELECT 'line1\nline2'  AS  label"),
        );
    }

    public function testDoesNotStripCommentTokensInsideStrings(): void
    {
        self::assertSame(
            "SELECT '-- not a comment' AS literal",
            $this->canonicalizer->canonicalize("SELECT '-- not a comment'   AS   literal"),
        );
    }

    public function testDoesNotStripBlockCommentTokensInsideStrings(): void
    {
        self::assertSame(
            "SELECT '/* not a comment */' AS literal",
            $this->canonicalizer->canonicalize("SELECT '/* not a comment */'    AS    literal"),
        );
    }

    public function testHandlesEscapedSingleQuoteInsideString(): void
    {
        self::assertSame(
            "SELECT 'it''s fine' AS label",
            $this->canonicalizer->canonicalize("SELECT 'it''s fine'   AS   label"),
        );
    }

    public function testPreservesWhitespaceInsideQuotedIdentifiers(): void
    {
        self::assertSame(
            'SELECT "weird  column" FROM t',
            $this->canonicalizer->canonicalize('SELECT   "weird  column"   FROM t'),
        );
    }

    public function testHandlesEscapedDoubleQuoteInsideIdentifier(): void
    {
        self::assertSame(
            'SELECT "weird""name" FROM t',
            $this->canonicalizer->canonicalize('SELECT   "weird""name"   FROM t'),
        );
    }

    public function testPreservesDollarQuotedBodyVerbatim(): void
    {
        self::assertSame(
            "SELECT \$\$a  b\n-- not a comment\$\$ AS body",
            $this->canonicalizer->canonicalize("SELECT   \$\$a  b\n-- not a comment\$\$   AS   body"),
        );
    }

    public function testPreservesTaggedDollarQuotedBodyVerbatim(): void
    {
        self::assertSame(
            'SELECT $tag$ inner $$ nested $tag$ AS body',
            $this->canonicalizer->canonicalize('SELECT   $tag$ inner $$ nested $tag$   AS   body'),
        );
    }

    public function testReformattingDoesNotChangeOutput(): void
    {
        $compact = 'SELECT category, count(*) AS order_count, sum(amount) AS total_amount '
            .'FROM orders WHERE amount IS NOT NULL GROUP BY category';

        $reformatted = <<<'SQL'
            SELECT
                category,   -- the grouping key
                count(*) AS order_count,
                sum(amount) AS total_amount
            /* aggregate over the orders table */
            FROM orders
            WHERE amount IS NOT NULL
            GROUP BY category;
            SQL;

        self::assertSame(
            $this->canonicalizer->canonicalize($compact),
            $this->canonicalizer->canonicalize($reformatted),
        );
    }

    public function testIsIdempotent(): void
    {
        $once = $this->canonicalizer->canonicalize("SELECT a, -- c\n  b FROM t;");

        self::assertSame($once, $this->canonicalizer->canonicalize($once));
    }
}
