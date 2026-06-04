<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class IndexSnapshot
{
    /**
     * @param list<CapturedIndex> $indexes
     */
    private function __construct(
        public MaterializedViewName $view,
        public array $indexes,
    ) {
    }

    /**
     * @param list<CapturedIndex> $indexes
     */
    public static function forView(MaterializedViewName $view, array $indexes): self
    {
        return new self($view, array_values($indexes));
    }

    public static function emptyFor(MaterializedViewName $view): self
    {
        return new self($view, []);
    }

    public function isEmpty(): bool
    {
        return [] === $this->indexes;
    }

    public function count(): int
    {
        return \count($this->indexes);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (CapturedIndex $index): string => $index->name, $this->indexes);
    }

    /**
     * @return list<string>
     */
    public function definitions(): array
    {
        return array_map(static fn (CapturedIndex $index): string => $index->definition, $this->indexes);
    }
}
