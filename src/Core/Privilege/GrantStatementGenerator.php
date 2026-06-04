<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Privilege;

use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

final readonly class GrantStatementGenerator
{
    public function __construct(
        private IdentifierQuoter $quoter,
    ) {
    }

    /**
     * @return list<string>
     */
    public function forSnapshot(PrivilegeSnapshot $snapshot): array
    {
        $qualifiedView = $this->quoter->quoteQualifiedName($snapshot->view);
        $statements = [];

        foreach ($this->groupByGrantee($snapshot->all()) as $group) {
            $statements[] = $this->grantStatement($group, $qualifiedView);
        }

        return $statements;
    }

    /**
     * @param list<ObjectPrivilege> $privileges
     *
     * @return list<GrantStatementGroup>
     */
    private function groupByGrantee(array $privileges): array
    {
        /** @var array<string, GrantStatementGroup> $groups */
        $groups = [];

        foreach ($privileges as $privilege) {
            $key = $privilege->grantee."\0".($privilege->withGrantOption ? '1' : '0');

            $group = $groups[$key] ?? GrantStatementGroup::forGrantee($privilege->grantee, $privilege->withGrantOption);
            $groups[$key] = $group->withPrivilegeType($privilege->privilegeType);
        }

        $ordered = array_values($groups);

        usort(
            $ordered,
            static fn (GrantStatementGroup $left, GrantStatementGroup $right): int => $left->sortKey() <=> $right->sortKey(),
        );

        return $ordered;
    }

    private function grantStatement(GrantStatementGroup $group, string $qualifiedView): string
    {
        $statement = \sprintf(
            'GRANT %s ON TABLE %s TO %s',
            implode(', ', $group->sortedPrivilegeTypes()),
            $qualifiedView,
            $this->renderGrantee($group->grantee),
        );

        if ($group->withGrantOption) {
            $statement .= ' WITH GRANT OPTION';
        }

        return $statement;
    }

    private function renderGrantee(string $grantee): string
    {
        if (ObjectPrivilege::PUBLIC_GRANTEE === strtoupper($grantee)) {
            return ObjectPrivilege::PUBLIC_GRANTEE;
        }

        return $this->quoter->quoteIdentifier($grantee);
    }
}
