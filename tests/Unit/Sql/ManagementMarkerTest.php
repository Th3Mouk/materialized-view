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

    public function testRecognisesItsOwnCommentAsManaged(): void
    {
        self::assertTrue(ManagementMarker::isManagedComment(ManagementMarker::create('abc123')->toJson()));
    }

    public function testTreatsNullEmptyAndMalformedCommentsAsUnmanaged(): void
    {
        self::assertFalse(ManagementMarker::isManagedComment(null));
        self::assertFalse(ManagementMarker::isManagedComment(''));
        self::assertFalse(ManagementMarker::isManagedComment('{not valid json'));
    }

    public function testDoesNotMatchTheMarkerKeyAsASubstringOfFreeText(): void
    {
        self::assertFalse(ManagementMarker::isManagedComment('a human note mentioning th3mouk_materialized_view in passing'));
        self::assertFalse(ManagementMarker::isManagedComment('{"other_key":{"th3mouk_materialized_view":"nested, not top-level"}}'));
    }

    public function testReadsTheHashOnlyFromAValidMarker(): void
    {
        self::assertSame('abc123', ManagementMarker::readHash(ManagementMarker::create('abc123')->toJson()));
        self::assertNull(ManagementMarker::readHash('{"th3mouk_materialized_view":{"version":1}}'));
        self::assertNull(ManagementMarker::readHash(null));
        self::assertNull(ManagementMarker::readHash('not json'));
    }
}
