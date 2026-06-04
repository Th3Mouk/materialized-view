<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Rebuild;

use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;

final readonly class RebuildPlan
{
    /**
     * @param list<string> $statements
     */
    private function __construct(
        public RebuildStrategy $strategy,
        public array $statements,
    ) {
    }

    /**
     * @param list<string> $statements
     */
    public static function of(RebuildStrategy $strategy, array $statements): self
    {
        return new self($strategy, array_values($statements));
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    public function count(): int
    {
        return \count($this->statements);
    }
}
