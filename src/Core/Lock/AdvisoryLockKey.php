<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

final readonly class AdvisoryLockKey
{
    private const int INT4_MIN = -2147483648;

    private const int INT4_MAX = 2147483647;

    private function __construct(
        public int $namespace,
        public int $key,
    ) {
    }

    public static function of(int $namespace, int $key): self
    {
        return new self(
            self::assertInt4($namespace),
            self::assertInt4($key),
        );
    }

    public function equals(self $other): bool
    {
        return $this->namespace === $other->namespace
            && $this->key === $other->key;
    }

    private static function assertInt4(int $value): int
    {
        if ($value < self::INT4_MIN || $value > self::INT4_MAX) {
            throw InvalidAdvisoryLockKey::outOfInt4Range($value, self::INT4_MIN, self::INT4_MAX);
        }

        return $value;
    }
}
