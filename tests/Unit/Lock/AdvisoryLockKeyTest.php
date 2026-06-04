<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Lock;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Lock\AdvisoryLockKey;
use Th3Mouk\MaterializedView\Core\Lock\InvalidAdvisoryLockKey;

#[Group('lock')]
final class AdvisoryLockKeyTest extends TestCase
{
    public function testKeepsNamespaceAndKeyAtInt4Bounds(): void
    {
        $lockKey = AdvisoryLockKey::of(-2147483648, 2147483647);

        self::assertSame(-2147483648, $lockKey->namespace);
        self::assertSame(2147483647, $lockKey->key);
    }

    public function testEqualityComparesBothComponents(): void
    {
        $reference = AdvisoryLockKey::of(392817, -719761347);

        self::assertTrue($reference->equals(AdvisoryLockKey::of(392817, -719761347)));
        self::assertFalse($reference->equals(AdvisoryLockKey::of(392818, -719761347)));
        self::assertFalse($reference->equals(AdvisoryLockKey::of(392817, 1)));
    }

    #[DataProvider('outOfRangeProvider')]
    public function testRejectsNamespaceOutsideInt4(int $namespace): void
    {
        $this->expectException(InvalidAdvisoryLockKey::class);
        $this->expectExceptionMessage('signed int4');

        AdvisoryLockKey::of($namespace, 0);
    }

    #[DataProvider('outOfRangeProvider')]
    public function testRejectsKeyOutsideInt4(int $key): void
    {
        $this->expectException(InvalidAdvisoryLockKey::class);
        $this->expectExceptionMessage('signed int4');

        AdvisoryLockKey::of(0, $key);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRangeProvider(): iterable
    {
        yield 'just below int4 min' => [-2147483649];
        yield 'just above int4 max' => [2147483648];
        yield 'far above int4 max' => [4294967295];
    }
}
