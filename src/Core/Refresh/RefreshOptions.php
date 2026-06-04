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
        );
    }
}
