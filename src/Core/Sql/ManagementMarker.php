<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sql;

use JsonException;

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

    /**
     * Whether a catalog comment marks a library-managed view. Decodes the JSON and checks
     * for the marker key at the top level — never a substring match, so an unmanaged
     * object whose free-text comment merely mentions the key is not misclassified.
     */
    public static function isManagedComment(?string $comment): bool
    {
        return null !== self::decodePayload($comment);
    }

    public static function readHash(?string $comment): ?string
    {
        $payload = self::decodePayload($comment);

        if (null === $payload || !isset($payload['hash']) || !\is_string($payload['hash'])) {
            return null;
        }

        return $payload['hash'];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decodePayload(?string $comment): ?array
    {
        if (null === $comment || '' === $comment) {
            return null;
        }

        try {
            $decoded = json_decode($comment, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\array_key_exists(self::MARKER_KEY, $decoded)) {
            return null;
        }

        $payload = $decoded[self::MARKER_KEY];

        return \is_array($payload) ? $payload : null;
    }
}
