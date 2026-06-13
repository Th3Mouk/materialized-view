<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Exception\UnmanagedDependentFound;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

final readonly class DropCreateRebuilder implements Rebuilder
{
    private RebuildStatementFactory $statements;

    private LoggerInterface $logger;

    public function __construct(
        private Connection $connection,
        ?LoggerInterface $logger = null,
    ) {
        $this->statements = new RebuildStatementFactory(new IdentifierQuoter());
        $this->logger = $logger ?? new NullLogger();
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
        $view = $definition->name()->qualifiedName();

        $this->logger->debug('Recreating materialized view "{view}" via drop/create.', [
            'view' => $view,
            'strategy' => RebuildStrategy::DropCreate->value,
            'drop_cascade' => $context->dropCascade,
        ]);

        if ($context->dropCascade && $context->hasDependents()) {
            $this->logger->notice(
                'Dropping materialized view "{view}" with CASCADE, taking down its dependents.',
                [
                    'view' => $view,
                    'dependents' => $context->dependentNames(),
                ],
            );
        }

        foreach ($this->planFor($definition, $context)->statements() as $statement) {
            $this->logger->debug('Executing rebuild statement.', ['view' => $view, 'sql' => $statement]);

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
