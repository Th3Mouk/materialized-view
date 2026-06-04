<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class CatalogDependencyEdge
{
    private const string MATERIALIZED_VIEW_RELKIND = 'm';

    private const string PLAIN_VIEW_RELKIND = 'v';

    private function __construct(
        public MaterializedViewName $dependent,
        public string $dependentRelkind,
        public MaterializedViewName $referenced,
        public string $referencedRelkind,
    ) {
    }

    public static function create(
        MaterializedViewName $dependent,
        string $dependentRelkind,
        MaterializedViewName $referenced,
        string $referencedRelkind,
    ): self {
        return new self($dependent, $dependentRelkind, $referenced, $referencedRelkind);
    }

    public function dependentIsMaterializedView(): bool
    {
        return self::MATERIALIZED_VIEW_RELKIND === $this->dependentRelkind;
    }

    public function dependentIsPlainView(): bool
    {
        return self::PLAIN_VIEW_RELKIND === $this->dependentRelkind;
    }

    public function referencedIsMaterializedView(): bool
    {
        return self::MATERIALIZED_VIEW_RELKIND === $this->referencedRelkind;
    }

    public function referencedIsPlainView(): bool
    {
        return self::PLAIN_VIEW_RELKIND === $this->referencedRelkind;
    }
}
