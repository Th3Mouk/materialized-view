<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Privilege;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Privilege\GrantStatementGenerator;
use Th3Mouk\MaterializedView\Core\Privilege\ObjectPrivilege;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeSnapshot;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

#[Group('privilege')]
final class GrantStatementGeneratorTest extends TestCase
{
    private GrantStatementGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new GrantStatementGenerator(new IdentifierQuoter());
    }

    public function testGeneratesNoStatementsForEmptySnapshot(): void
    {
        $snapshot = PrivilegeSnapshot::empty(MaterializedViewName::create('public', 'sales_by_category'));

        self::assertSame([], $this->generator->forSnapshot($snapshot));
    }

    public function testGeneratesGrantWithQuotedQualifiedNameAndRole(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('analytics', 'sales_by_category'),
            [ObjectPrivilege::granted('reporting_ro', 'SELECT')],
        );

        self::assertSame(
            ['GRANT SELECT ON TABLE "analytics"."sales_by_category" TO "reporting_ro"'],
            $this->generator->forSnapshot($snapshot),
        );
    }

    public function testGroupsPrivilegesOfSameGranteeIntoSingleStatementSortedAlphabetically(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [
                ObjectPrivilege::granted('app', 'SELECT'),
                ObjectPrivilege::granted('app', 'INSERT'),
                ObjectPrivilege::granted('app', 'DELETE'),
            ],
        );

        self::assertSame(
            ['GRANT DELETE, INSERT, SELECT ON TABLE "public"."sales_by_category" TO "app"'],
            $this->generator->forSnapshot($snapshot),
        );
    }

    public function testEmitsPublicGranteeUnquoted(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [ObjectPrivilege::granted('PUBLIC', 'SELECT')],
        );

        self::assertSame(
            ['GRANT SELECT ON TABLE "public"."sales_by_category" TO PUBLIC'],
            $this->generator->forSnapshot($snapshot),
        );
    }

    public function testAppendsWithGrantOptionWhenGrantable(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [ObjectPrivilege::granted('bi_admin', 'SELECT', withGrantOption: true)],
        );

        self::assertSame(
            ['GRANT SELECT ON TABLE "public"."sales_by_category" TO "bi_admin" WITH GRANT OPTION'],
            $this->generator->forSnapshot($snapshot),
        );
    }

    public function testSeparatesGrantableFromNonGrantablePrivilegesOfSameGrantee(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [
                ObjectPrivilege::granted('app', 'SELECT', withGrantOption: false),
                ObjectPrivilege::granted('app', 'UPDATE', withGrantOption: true),
            ],
        );

        self::assertSame(
            [
                'GRANT SELECT ON TABLE "public"."sales_by_category" TO "app"',
                'GRANT UPDATE ON TABLE "public"."sales_by_category" TO "app" WITH GRANT OPTION',
            ],
            $this->generator->forSnapshot($snapshot),
        );
    }

    public function testOrdersStatementsDeterministicallyByGrantee(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [
                ObjectPrivilege::granted('zeta', 'SELECT'),
                ObjectPrivilege::granted('alpha', 'SELECT'),
                ObjectPrivilege::granted('PUBLIC', 'SELECT'),
            ],
        );

        self::assertSame(
            [
                'GRANT SELECT ON TABLE "public"."sales_by_category" TO PUBLIC',
                'GRANT SELECT ON TABLE "public"."sales_by_category" TO "alpha"',
                'GRANT SELECT ON TABLE "public"."sales_by_category" TO "zeta"',
            ],
            $this->generator->forSnapshot($snapshot),
        );
    }

    public function testQuotesIdentifiersContainingDoubleQuotes(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [ObjectPrivilege::granted('we"ird', 'SELECT')],
        );

        self::assertSame(
            ['GRANT SELECT ON TABLE "public"."sales_by_category" TO "we""ird"'],
            $this->generator->forSnapshot($snapshot),
        );
    }
}
