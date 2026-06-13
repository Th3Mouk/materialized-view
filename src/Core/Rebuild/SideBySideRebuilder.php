<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Exception\SideBySideRequiresLeafView;
use Th3Mouk\MaterializedView\Core\Sql\IdentifierQuoter;

final readonly class SideBySideRebuilder implements Rebuilder
{
    private RebuildStatementFactory $statements;

    private LoggerInterface $logger;

    public function __construct(
        private Connection $connection,
        private string $swapToken,
        ?LoggerInterface $logger = null,
    ) {
        $this->statements = new RebuildStatementFactory(new IdentifierQuoter());
        $this->logger = $logger ?? new NullLogger();
    }

    public function strategy(): RebuildStrategy
    {
        return RebuildStrategy::SideBySide;
    }

    public function planFor(MaterializedViewDefinition $definition, RebuildContext $context): RebuildPlan
    {
        $this->guardLeafView($definition, $context);

        $view = $definition->name();
        $naming = SwapNaming::for($view, $this->swapToken);

        $temporaryView = $this->temporaryViewName($view, $naming);
        $oldView = $this->oldViewName($view, $naming);

        $statements = [];

        foreach ($this->buildTemporaryStatements($definition, $naming, $temporaryView) as $statement) {
            $statements[] = $statement;
        }

        foreach ($this->swapStatements($definition, $context, $naming, $view, $temporaryView, $oldView) as $statement) {
            $statements[] = $statement;
        }

        return RebuildPlan::of(RebuildStrategy::SideBySide, $statements);
    }

    public function rebuild(MaterializedViewDefinition $definition, RebuildContext $context): void
    {
        $this->guardLeafView($definition, $context);

        $view = $definition->name();
        $qualifiedName = $view->qualifiedName();
        $naming = SwapNaming::for($view, $this->swapToken);

        $temporaryView = $this->temporaryViewName($view, $naming);
        $oldView = $this->oldViewName($view, $naming);

        $this->logger->debug('Building side-by-side replacement for materialized view "{view}".', [
            'view' => $qualifiedName,
            'strategy' => RebuildStrategy::SideBySide->value,
            'temporary_view' => $temporaryView->qualifiedName(),
        ]);

        foreach ($this->buildTemporaryStatements($definition, $naming, $temporaryView) as $statement) {
            $this->logger->debug('Executing side-by-side build statement.', [
                'view' => $qualifiedName,
                'sql' => $statement,
            ]);

            $this->connection->executeStatement($statement);
        }

        $swapStatements = $this->swapStatements($definition, $context, $naming, $view, $temporaryView, $oldView);

        $this->logger->debug('Swapping side-by-side replacement into materialized view "{view}".', [
            'view' => $qualifiedName,
            'old_view' => $oldView->qualifiedName(),
        ]);

        $this->connection->transactional(function (Connection $connection) use ($swapStatements, $qualifiedName): void {
            foreach ($swapStatements as $statement) {
                $this->logger->debug('Executing side-by-side swap statement.', [
                    'view' => $qualifiedName,
                    'sql' => $statement,
                ]);

                $connection->executeStatement($statement);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function buildTemporaryStatements(
        MaterializedViewDefinition $definition,
        SwapNaming $naming,
        MaterializedViewName $temporaryView,
    ): array {
        $statements = [];

        $statements[] = $this->statements->create(
            $temporaryView,
            $definition->sqlSource()->sql(),
            false,
        );

        foreach ($definition->indexes() as $index) {
            $statements[] = $this->statements->createIndex(
                $temporaryView,
                $index,
                $naming->temporaryIndexName($index->name),
            );
        }

        $statements[] = $this->statements->refreshWithData($temporaryView);

        return $statements;
    }

    /**
     * @return list<string>
     */
    private function swapStatements(
        MaterializedViewDefinition $definition,
        RebuildContext $context,
        SwapNaming $naming,
        MaterializedViewName $view,
        MaterializedViewName $temporaryView,
        MaterializedViewName $oldView,
    ): array {
        $statements = [];

        $statements[] = $this->statements->lockForSwap($view);
        $statements[] = $this->statements->renameView($view, $naming->oldViewName());
        $statements[] = $this->statements->renameView($temporaryView, $view->name);
        $statements[] = $this->statements->dropIfExists($oldView);

        foreach ($definition->indexes() as $index) {
            $statements[] = $this->statements->renameIndex(
                $view,
                $naming->temporaryIndexName($index->name),
                $index->name,
            );
        }

        $statements[] = $this->statements->comment($view, $context->managementComment);

        foreach ($context->grantStatements as $grantStatement) {
            $statements[] = $grantStatement;
        }

        return $statements;
    }

    private function temporaryViewName(MaterializedViewName $view, SwapNaming $naming): MaterializedViewName
    {
        return MaterializedViewName::create($view->schema, $naming->temporaryViewName());
    }

    private function oldViewName(MaterializedViewName $view, SwapNaming $naming): MaterializedViewName
    {
        return MaterializedViewName::create($view->schema, $naming->oldViewName());
    }

    private function guardLeafView(MaterializedViewDefinition $definition, RebuildContext $context): void
    {
        if ($context->hasDependents()) {
            throw SideBySideRequiresLeafView::forView($definition->name(), $context->dependentNames());
        }
    }
}
