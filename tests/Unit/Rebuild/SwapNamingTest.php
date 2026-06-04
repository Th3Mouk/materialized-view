<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Rebuild;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Rebuild\SwapNaming;

#[Group('rebuild')]
final class SwapNamingTest extends TestCase
{
    public function testDerivesTemporaryAndOldViewNames(): void
    {
        $naming = SwapNaming::for(MaterializedViewName::fromString('public.summary'), 'abc123');

        self::assertSame('summary__mv_tmp_abc123', $naming->temporaryViewName());
        self::assertSame('summary__mv_old_abc123', $naming->oldViewName());
    }

    public function testDerivesTemporaryIndexName(): void
    {
        $naming = SwapNaming::for(MaterializedViewName::fromString('public.summary'), 'abc123');

        self::assertSame('ux_summary_identity__tmp_abc123', $naming->temporaryIndexName('ux_summary_identity'));
    }

    public function testSanitizesNonAlphanumericTokenCharacters(): void
    {
        $naming = SwapNaming::for(MaterializedViewName::fromString('public.summary'), 'AB-12:cd');

        self::assertSame('summary__mv_tmp_ab12cd', $naming->temporaryViewName());
    }

    public function testKeepsTemporaryViewNameWithinPostgresIdentifierLimit(): void
    {
        $longName = str_repeat('a', 63);
        $naming = SwapNaming::for(MaterializedViewName::create('public', $longName), 'deadbeefdeadbeef');

        self::assertLessThanOrEqual(63, \strlen($naming->temporaryViewName()));
        self::assertStringEndsWith('__mv_tmp_deadbeefdeadbeef', $naming->temporaryViewName());
    }

    public function testKeepsTemporaryIndexNameWithinPostgresIdentifierLimit(): void
    {
        $longIndex = str_repeat('b', 63);
        $naming = SwapNaming::for(MaterializedViewName::fromString('public.summary'), 'deadbeefdeadbeef');

        self::assertLessThanOrEqual(63, \strlen($naming->temporaryIndexName($longIndex)));
        self::assertStringEndsWith('__tmp_deadbeefdeadbeef', $naming->temporaryIndexName($longIndex));
    }
}
