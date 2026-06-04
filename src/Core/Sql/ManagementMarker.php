<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sql;

final readonly class ManagementMarker
{
    public const string MARKER_KEY = 'th3mouk_materialized_view';

    private function __construct(
        public string $hash,
        public int $version,
        public ?string $source,
    ) {
    }

    public static function create(string $hash, int $version = 1, ?string $source = null): self
    {
        return new self($hash, $version, $source);
    }

    public function toJson(): string
    {
        $payload = [
            'hash' => $this->hash,
            'version' => $this->version,
        ];

        if (null !== $this->source) {
            $payload['source'] = $this->source;
        }

        return (string) json_encode(
            [self::MARKER_KEY => $payload],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
