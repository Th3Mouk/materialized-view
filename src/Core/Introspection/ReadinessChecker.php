<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Introspection;

use Doctrine\DBAL\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;

final class ReadinessChecker
{
    private const string RELISPOPULATED_SQL = <<<'SQL'
        SELECT c.relispopulated
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relkind = 'm'
          AND n.nspname = :schema_name
          AND c.relname = :view_name
        SQL;

    /**
     * @var array<string, bool>
     */
    private array $populationByName = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function isReady(MaterializedViewName $name): bool
    {
        $key = $name->qualifiedName();

        return $this->populationByName[$key] ??= $this->readPopulationState($name);
    }

    /**
     * @throws ViewNotPopulated
     */
    public function ensureReadable(MaterializedViewName $name): void
    {
        if (!$this->isReady($name)) {
            throw ViewNotPopulated::forRead($name);
        }
    }

    public function forget(MaterializedViewName $name): void
    {
        unset($this->populationByName[$name->qualifiedName()]);
    }

    public function forgetAll(): void
    {
        $this->populationByName = [];
    }

    private function readPopulationState(MaterializedViewName $name): bool
    {
        $value = $this->connection->fetchOne(
            self::RELISPOPULATED_SQL,
            [
                'schema_name' => $name->schema,
                'view_name' => $name->name,
            ],
        );

        if (false === $value || null === $value) {
            return false;
        }

        if (\is_bool($value)) {
            return $value;
        }

        return \in_array($value, [1, '1', 't', 'true'], true);
    }
}
