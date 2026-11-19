<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Refresh;

final readonly class RefreshOutcome
{
    /**
     * @param list<string> $refreshed
     * @param list<string> $skippedPopulated
     * @param list<string> $skippedMissing
     */
    private function __construct(
        public array $refreshed,
        public array $skippedPopulated,
        public array $skippedMissing,
    ) {
    }

    /**
     * @param list<string> $refreshed
     * @param list<string> $skippedPopulated
     * @param list<string> $skippedMissing
     */
    public static function of(
        array $refreshed = [],
        array $skippedPopulated = [],
        array $skippedMissing = [],
    ): self {
        return new self(
            array_values($refreshed),
            array_values($skippedPopulated),
            array_values($skippedMissing),
        );
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->refreshed, ...$other->refreshed],
            [...$this->skippedPopulated, ...$other->skippedPopulated],
            [...$this->skippedMissing, ...$other->skippedMissing],
        );
    }

    public function skippedCount(): int
    {
        return \count($this->skippedPopulated) + \count($this->skippedMissing);
    }
}
