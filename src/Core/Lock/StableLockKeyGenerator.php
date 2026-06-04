<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Lock;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;

final readonly class StableLockKeyGenerator
{
    private const int SIGNED_INT4_THRESHOLD = 0x80000000;

    private const int UNSIGNED_INT4_MODULUS = 0x100000000;

    public function __construct(
        private int $refreshNamespace,
    ) {
    }

    public function forView(MaterializedViewName $name): AdvisoryLockKey
    {
        return AdvisoryLockKey::of($this->refreshNamespace, self::viewKey($name));
    }

    public static function viewKey(MaterializedViewName $name): int
    {
        return self::toSignedInt4(self::unsignedCrc32($name->qualifiedName()));
    }

    private static function unsignedCrc32(string $canonicalIdentity): int
    {
        return (int) hexdec(hash('crc32b', $canonicalIdentity));
    }

    private static function toSignedInt4(int $unsigned): int
    {
        return $unsigned >= self::SIGNED_INT4_THRESHOLD
            ? $unsigned - self::UNSIGNED_INT4_MODULUS
            : $unsigned;
    }
}
