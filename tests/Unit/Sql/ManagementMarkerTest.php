<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;

#[Group('sql')]
final class ManagementMarkerTest extends TestCase
{
    public function testSerializesHashVersionAndSourceWithUnescapedSlashes(): void
    {
        $marker = ManagementMarker::create(
            hash: 'abc123',
            version: 1,
            source: 'db/matviews/score.sql',
        );

        self::assertSame(
            '{"th3mouk_materialized_view":{"hash":"abc123","version":1,"source":"db/matviews/score.sql"}}',
            $marker->toJson(),
        );
    }

    public function testDefaultsVersionToOneAndOmitsSourceWhenNull(): void
    {
        self::assertSame(
            '{"th3mouk_materialized_view":{"hash":"abc123","version":1}}',
            ManagementMarker::create('abc123')->toJson(),
        );
    }

    public function testRoundTripsThroughJsonDecode(): void
    {
        $decoded = json_decode(ManagementMarker::create('h', 3, 's')->toJson(), true);

        self::assertSame(
            ['th3mouk_materialized_view' => ['hash' => 'h', 'version' => 3, 'source' => 's']],
            $decoded,
        );
    }
}
