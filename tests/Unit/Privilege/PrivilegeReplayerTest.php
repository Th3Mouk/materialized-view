<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Privilege;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Privilege\GrantStatementGenerator;
use Th3Mouk\MaterializedView\Core\Privilege\ObjectPrivilege;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeReplayer;
use Th3Mouk\MaterializedView\Core\Privilege\PrivilegeSnapshot;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

#[Group('privilege')]
final class PrivilegeReplayerTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $executed = [];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->executed = [];

        $this->connection = $this->createStub(Connection::class);
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                $this->executed[] = $sql;

                return 1;
            });
    }

    public function testReplaysEveryGeneratedGrantStatement(): void
    {
        $snapshot = PrivilegeSnapshot::forView(
            MaterializedViewName::create('public', 'sales_by_category'),
            [
                ObjectPrivilege::granted('reporting_ro', 'SELECT'),
                ObjectPrivilege::granted('PUBLIC', 'SELECT'),
            ],
        );

        $replayer = new PrivilegeReplayer($this->connection, new GrantStatementGenerator(new IdentifierQuoter()));

        $count = $replayer->replay($snapshot);

        self::assertSame(2, $count);
        self::assertSame(
            [
                'GRANT SELECT ON TABLE "public"."sales_by_category" TO PUBLIC',
                'GRANT SELECT ON TABLE "public"."sales_by_category" TO "reporting_ro"',
            ],
            $this->executed,
        );
    }

    public function testEmptySnapshotExecutesNothing(): void
    {
        $snapshot = PrivilegeSnapshot::empty(MaterializedViewName::create('public', 'sales_by_category'));

        $replayer = new PrivilegeReplayer($this->connection, new GrantStatementGenerator(new IdentifierQuoter()));

        $count = $replayer->replay($snapshot);

        self::assertSame(0, $count);
        self::assertSame([], $this->executed);
    }
}
