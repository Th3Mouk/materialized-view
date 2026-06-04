<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sync;

final readonly class SyncOutcome
{
    /**
     * @param list<string> $created
     * @param list<string> $rebuilt
     * @param list<string> $upToDate
     * @param list<string> $pruned
     * @param list<string> $orphansKept
     * @param list<string> $skipped
     */
    private function __construct(
        public array $created,
        public array $rebuilt,
        public array $upToDate,
        public array $pruned,
        public array $orphansKept,
        public array $skipped,
    ) {
    }

    /**
     * @param list<string> $created
     * @param list<string> $rebuilt
     * @param list<string> $upToDate
     * @param list<string> $pruned
     * @param list<string> $orphansKept
     * @param list<string> $skipped
     */
    public static function of(
        array $created,
        array $rebuilt,
        array $upToDate,
        array $pruned,
        array $orphansKept,
        array $skipped = [],
    ): self {
        return new self(
            array_values($created),
            array_values($rebuilt),
            array_values($upToDate),
            array_values($pruned),
            array_values($orphansKept),
            array_values($skipped),
        );
    }

    public function changedCount(): int
    {
        return \count($this->created)
            + \count($this->rebuilt)
            + \count($this->pruned);
    }
}
