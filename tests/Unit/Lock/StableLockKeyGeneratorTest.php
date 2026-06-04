<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Lock;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Lock\StableLockKeyGenerator;

#[Group('lock')]
final class StableLockKeyGeneratorTest extends TestCase
{
    private const int INT4_MIN = -2147483648;

    private const int INT4_MAX = 2147483647;

    private const int REFRESH_NAMESPACE = 392817;

    #[DataProvider('knownKeyProvider')]
    public function testProducesFrozenKeyForQualifiedName(string $qualifiedName, int $expectedKey): void
    {
        $name = MaterializedViewName::fromString($qualifiedName);

        self::assertSame($expectedKey, StableLockKeyGenerator::viewKey($name));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function knownKeyProvider(): iterable
    {
        yield 'documented example view' => ['public.sales_by_category', -797292571];
        yield 'short non-negative key' => ['public.a', 1406210170];
        yield 'non-public schema' => ['analytics.report', 653266293];
        yield 'negative key path' => ['public.top_categories', -1026400114];
        yield 'sibling view distinct key' => ['public.sales_by_category_score', -1645174675];
    }

    #[DataProvider('signednessProbeProvider')]
    public function testKeyAlwaysFitsSignedInt4(string $qualifiedName): void
    {
        $key = StableLockKeyGenerator::viewKey(MaterializedViewName::fromString($qualifiedName));

        self::assertGreaterThanOrEqual(self::INT4_MIN, $key);
        self::assertLessThanOrEqual(self::INT4_MAX, $key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function signednessProbeProvider(): iterable
    {
        foreach (range(0, 256) as $index) {
            yield 'probe_'.$index => ['public.view_'.$index];
        }
    }

    public function testKeyIsDeterministicAcrossInvocations(): void
    {
        $name = MaterializedViewName::fromString('public.sales_by_category');

        self::assertSame(
            StableLockKeyGenerator::viewKey($name),
            StableLockKeyGenerator::viewKey($name),
        );
    }

    public function testKeyDependsOnCanonicalIdentityNotInstance(): void
    {
        $first = MaterializedViewName::create('public', 'sales_by_category');
        $second = MaterializedViewName::fromString('public.sales_by_category');

        self::assertSame(
            StableLockKeyGenerator::viewKey($first),
            StableLockKeyGenerator::viewKey($second),
        );
    }

    public function testSchemaIsPartOfTheKey(): void
    {
        $public = MaterializedViewName::create('public', 'report');
        $analytics = MaterializedViewName::create('analytics', 'report');

        self::assertNotSame(
            StableLockKeyGenerator::viewKey($public),
            StableLockKeyGenerator::viewKey($analytics),
        );
    }

    public function testForViewCombinesNamespaceAndViewKey(): void
    {
        $generator = new StableLockKeyGenerator(self::REFRESH_NAMESPACE);
        $name = MaterializedViewName::fromString('public.sales_by_category');

        $lockKey = $generator->forView($name);

        self::assertSame(self::REFRESH_NAMESPACE, $lockKey->namespace);
        self::assertSame(StableLockKeyGenerator::viewKey($name), $lockKey->key);
    }
}
