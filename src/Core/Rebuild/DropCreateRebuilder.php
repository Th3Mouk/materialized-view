<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Doctrine\DBAL\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

final readonly class DropCreateRebuilder implements Rebuilder
{
    private RebuildStatementFactory $statements;

    public function __construct(
        private Connection $connection,
    ) {
        $this->statements = new RebuildStatementFactory(IdentifierQuoter::forConnection($connection));
    }

    public function strategy(): RebuildStrategy
    {
        return RebuildStrategy::DropCreate;
    }

    public function planFor(MaterializedViewDefinition $definition, RebuildContext $context): RebuildPlan
    {
        $this->guardUnmanagedDependents($definition, $context);

        $view = $definition->name();
        $statements = [];

        $statements[] = $this->statements->dropIfExists($view, $context->dropCascade);
        $statements[] = $this->statements->create(
            $view,
            $definition->sqlSource()->sql(),
            $definition->createWithData(),
        );

        foreach ($definition->indexes() as $index) {
            $statements[] = $this->statements->createIndex($view, $index, $index->name);
        }

        $statements[] = $this->statements->comment($view, $context->managementComment);

        foreach ($context->grantStatements as $grantStatement) {
            $statements[] = $grantStatement;
        }

        if ($this->shouldRefreshDuringRebuild($definition)) {
            $statements[] = $this->statements->refreshWithData($view);
        }

        return RebuildPlan::of(RebuildStrategy::DropCreate, $statements);
    }

    public function rebuild(MaterializedViewDefinition $definition, RebuildContext $context): void
    {
        foreach ($this->planFor($definition, $context)->statements() as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    private function shouldRefreshDuringRebuild(MaterializedViewDefinition $definition): bool
    {
        return $definition->populationPolicy()->refreshesDuringSync()
            && !$definition->createWithData();
    }

    private function guardUnmanagedDependents(MaterializedViewDefinition $definition, RebuildContext $context): void
    {
        $unmanaged = $context->unmanagedDependentNames();

        if ([] !== $unmanaged) {
            throw UnmanagedDependentFound::blockingRebuild($definition->name(), $unmanaged);
        }
    }
}
