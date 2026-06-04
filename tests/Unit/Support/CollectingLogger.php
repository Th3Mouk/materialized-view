<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class CollectingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function recordsAtLevel(string $level): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] === $level,
        ));
    }
}
