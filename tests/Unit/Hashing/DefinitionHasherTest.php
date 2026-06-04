<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Hashing;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewIndex;
use Th3Mouk\MaterializedView\Core\Definition\PopulationPolicy;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Definition\SqlSource;
use Th3Mouk\MaterializedView\Core\Exception\MissingSqlSource;
use Th3Mouk\MaterializedView\Core\Hashing\DefinitionHasher;

#[Group('hashing')]
final class DefinitionHasherTest extends TestCase
{
    private const string COMPACT_SQL = 'SELECT category, count(*) AS order_count, sum(amount) AS total_amount '
        .'FROM orders GROUP BY category';

    private const string REFORMATTED_SQL = <<<'SQL'
        SELECT
            category,   -- grouping key
            count(*) AS order_count,
            sum(amount) AS total_amount
        /* aggregate */
        FROM orders
        GROUP BY category;
        SQL;

    private DefinitionHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = DefinitionHasher::create();
    }

    public function testProducesStableHexDigest(): void
    {
        $hash = $this->hasher->hash($this->definition(self::COMPACT_SQL));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testIsDeterministicAcrossInvocations(): void
    {
        $definition = $this->definition(self::COMPACT_SQL);

        self::assertSame($this->hasher->hash($definition), $this->hasher->hash($definition));
    }

    public function testReformattingSqlDoesNotChangeHash(): void
    {
        self::assertSame(
            $this->hasher->hash($this->definition(self::COMPACT_SQL)),
            $this->hasher->hash($this->definition(self::REFORMATTED_SQL)),
        );
    }

    public function testAddingCommentsDoesNotChangeHash(): void
    {
        $withComments = "-- header\nSELECT 1 AS one /* inline */ FROM t -- trailing\n";

        self::assertSame(
            $this->hasher->hash($this->definition('SELECT 1 AS one FROM t')),
            $this->hasher->hash($this->definition($withComments)),
        );
    }

    public function testTrailingSemicolonDoesNotChangeHash(): void
    {
        self::assertSame(
            $this->hasher->hash($this->definition('SELECT 1 AS one')),
            $this->hasher->hash($this->definition('SELECT 1 AS one;')),
        );
    }

    public function testSqlSourcePathIsExcludedFromHash(): void
    {
        $inline = MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString(self::COMPACT_SQL));

        $file = MaterializedViewDefinition::create('public.report')
            ->fromSql($this->fileSourceReturning(self::COMPACT_SQL, '/db/matviews/report_v001.sql'));

        $otherPath = MaterializedViewDefinition::create('public.report')
            ->fromSql($this->fileSourceReturning(self::COMPACT_SQL, '/somewhere/else/report.sql'));

        $hash = $this->hasher->hash($inline);

        self::assertSame($hash, $this->hasher->hash($file));
        self::assertSame($hash, $this->hasher->hash($otherPath));
    }

    public function testSemanticSqlChangeChangesHash(): void
    {
        self::assertNotSame(
            $this->hasher->hash($this->definition('SELECT 1 AS one')),
            $this->hasher->hash($this->definition('SELECT 2 AS one')),
        );
    }

    public function testStringLiteralWhitespaceIsSignificant(): void
    {
        self::assertNotSame(
            $this->hasher->hash($this->definition("SELECT 'a b' AS label")),
            $this->hasher->hash($this->definition("SELECT 'a  b' AS label")),
        );
    }

    public function testQualifiedNameChangesHash(): void
    {
        $public = MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString(self::COMPACT_SQL));

        $analytics = MaterializedViewDefinition::create('analytics.report')
            ->fromSql(InlineSqlSource::fromString(self::COMPACT_SQL));

        self::assertNotSame($this->hasher->hash($public), $this->hasher->hash($analytics));
    }

    public function testRebuildStrategyChangesHash(): void
    {
        $dropCreate = $this->leafDefinition()->withRebuildStrategy(RebuildStrategy::DropCreate);
        $sideBySide = $this->leafDefinition()->withRebuildStrategy(RebuildStrategy::SideBySide);

        self::assertNotSame($this->hasher->hash($dropCreate), $this->hasher->hash($sideBySide));
    }

    public function testPopulationPolicyChangesHash(): void
    {
        $manual = $this->definition(self::COMPACT_SQL)->withPopulationPolicy(PopulationPolicy::Manual);
        $synchronous = $this->definition(self::COMPACT_SQL)->withPopulationPolicy(PopulationPolicy::Synchronous);

        self::assertNotSame($this->hasher->hash($manual), $this->hasher->hash($synchronous));
    }

    public function testWithDataOptionChangesHash(): void
    {
        $noData = $this->definition(self::COMPACT_SQL)->withNoData();
        $withData = $this->definition(self::COMPACT_SQL)->withData();

        self::assertNotSame($this->hasher->hash($noData), $this->hasher->hash($withData));
    }

    public function testDeclaredIndexChangesHash(): void
    {
        $withoutIndex = $this->definition(self::COMPACT_SQL);
        $withIndex = $this->definition(self::COMPACT_SQL)->withIndex(MaterializedViewIndex::unique(
            name: 'ux_report_identity',
            columns: ['category'],
        ));

        self::assertNotSame($this->hasher->hash($withoutIndex), $this->hasher->hash($withIndex));
    }

    public function testIndexOrderChangesHash(): void
    {
        $first = $this->definition(self::COMPACT_SQL)
            ->withIndex(MaterializedViewIndex::regular(name: 'idx_a', columns: ['a']))
            ->withIndex(MaterializedViewIndex::regular(name: 'idx_b', columns: ['b']));

        $second = $this->definition(self::COMPACT_SQL)
            ->withIndex(MaterializedViewIndex::regular(name: 'idx_b', columns: ['b']))
            ->withIndex(MaterializedViewIndex::regular(name: 'idx_a', columns: ['a']));

        self::assertNotSame($this->hasher->hash($first), $this->hasher->hash($second));
    }

    public function testIndexAttributeChangesHash(): void
    {
        $regular = $this->definition(self::COMPACT_SQL)
            ->withIndex(MaterializedViewIndex::regular(name: 'idx_one', columns: ['one']));

        $unique = $this->definition(self::COMPACT_SQL)
            ->withIndex(MaterializedViewIndex::unique(name: 'idx_one', columns: ['one']));

        self::assertNotSame($this->hasher->hash($regular), $this->hasher->hash($unique));
    }

    public function testManualDependenciesDoNotChangeHash(): void
    {
        $bare = $this->definition(self::COMPACT_SQL);
        $withDependency = $this->definition(self::COMPACT_SQL)->dependsOn('public.other_view');

        self::assertSame($this->hasher->hash($bare), $this->hasher->hash($withDependency));
    }

    public function testRequiresSqlSource(): void
    {
        $this->expectException(MissingSqlSource::class);

        $this->hasher->hash(MaterializedViewDefinition::create('public.report'));
    }

    private function definition(string $sql): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString($sql))
            ->withNoData()
            ->withPopulationPolicy(PopulationPolicy::Manual);
    }

    private function leafDefinition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.report')
            ->fromSql(InlineSqlSource::fromString(self::COMPACT_SQL))
            ->withNoData();
    }

    private function fileSourceReturning(string $sql, string $path): SqlSource
    {
        return new readonly class($sql, $path) implements SqlSource {
            public function __construct(
                private string $sql,
                private string $path,
            ) {
            }

            public function sql(): string
            {
                return $this->sql;
            }

            public function identifier(): string
            {
                return $this->path;
            }
        };
    }
}
