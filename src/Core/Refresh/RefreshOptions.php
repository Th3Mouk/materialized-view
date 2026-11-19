<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Refresh;

final readonly class RefreshOptions
{
    private function __construct(
        public bool $concurrently,
        public bool $withData,
        public bool $analyzeAfterRefresh,
        public ?string $lockTimeout,
        public ?string $statementTimeout,
        public bool $onlyUnpopulated,
    ) {
    }

    public static function default(): self
    {
        return new self(
            concurrently: false,
            withData: true,
            analyzeAfterRefresh: true,
            lockTimeout: null,
            statementTimeout: null,
            onlyUnpopulated: false,
        );
    }

    public static function concurrent(): self
    {
        return self::default()->withConcurrently();
    }

    public function withConcurrently(bool $concurrently = true): self
    {
        return new self(
            concurrently: $concurrently,
            withData: $this->withData,
            analyzeAfterRefresh: $this->analyzeAfterRefresh,
            lockTimeout: $this->lockTimeout,
            statementTimeout: $this->statementTimeout,
            onlyUnpopulated: $this->onlyUnpopulated,
        );
    }

    public function withData(bool $withData = true): self
    {
        return new self(
            concurrently: $this->concurrently,
            withData: $withData,
            analyzeAfterRefresh: $this->analyzeAfterRefresh,
            lockTimeout: $this->lockTimeout,
            statementTimeout: $this->statementTimeout,
            onlyUnpopulated: $this->onlyUnpopulated,
        );
    }

    public function withNoData(): self
    {
        return $this->withData(false);
    }

    public function withAnalyzeAfterRefresh(bool $analyzeAfterRefresh = true): self
    {
        return new self(
            concurrently: $this->concurrently,
            withData: $this->withData,
            analyzeAfterRefresh: $analyzeAfterRefresh,
            lockTimeout: $this->lockTimeout,
            statementTimeout: $this->statementTimeout,
            onlyUnpopulated: $this->onlyUnpopulated,
        );
    }

    public function withLockTimeout(?string $lockTimeout): self
    {
        return new self(
            concurrently: $this->concurrently,
            withData: $this->withData,
            analyzeAfterRefresh: $this->analyzeAfterRefresh,
            lockTimeout: $lockTimeout,
            statementTimeout: $this->statementTimeout,
            onlyUnpopulated: $this->onlyUnpopulated,
        );
    }

    public function withStatementTimeout(?string $statementTimeout): self
    {
        return new self(
            concurrently: $this->concurrently,
            withData: $this->withData,
            analyzeAfterRefresh: $this->analyzeAfterRefresh,
            lockTimeout: $this->lockTimeout,
            statementTimeout: $statementTimeout,
            onlyUnpopulated: $this->onlyUnpopulated,
        );
    }

    public function withOnlyUnpopulated(bool $onlyUnpopulated = true): self
    {
        return new self(
            concurrently: $this->concurrently,
            withData: $this->withData,
            analyzeAfterRefresh: $this->analyzeAfterRefresh,
            lockTimeout: $this->lockTimeout,
            statementTimeout: $this->statementTimeout,
            onlyUnpopulated: $onlyUnpopulated,
        );
    }
}
