<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Rebuild;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Rebuild\IndexSnapshotter;

#[Group('rebuild')]
final class IndexSnapshotterTest extends TestCase
{
    public function testCapturesIndexNamesAndDefinitionsFromCatalog(): void
    {
        $connection = RebuildTestConnectionFactory::withIndexRows($this, [
            [
                'indexname' => 'ux_summary_identity',
                'indexdef' => 'CREATE UNIQUE INDEX ux_summary_identity ON public.summary USING btree (product_id, score_id)',
            ],
            [
                'indexname' => 'idx_summary_score',
                'indexdef' => 'CREATE INDEX idx_summary_score ON public.summary USING btree (score_id)',
            ],
        ]);

        $snapshot = new IndexSnapshotter($connection)
            ->capture(MaterializedViewName::fromString('public.summary'));

        self::assertSame(2, $snapshot->count());
        self::assertSame(['ux_summary_identity', 'idx_summary_score'], $snapshot->names());
        self::assertSame(
            [
                'CREATE UNIQUE INDEX ux_summary_identity ON public.summary USING btree (product_id, score_id)',
                'CREATE INDEX idx_summary_score ON public.summary USING btree (score_id)',
            ],
            $snapshot->definitions(),
        );
    }

    public function testCapturesEmptySnapshotWhenViewHasNoIndexes(): void
    {
        $connection = RebuildTestConnectionFactory::withIndexRows($this, []);

        $snapshot = new IndexSnapshotter($connection)
            ->capture(MaterializedViewName::fromString('analytics.cold_view'));

        self::assertTrue($snapshot->isEmpty());
        self::assertSame(0, $snapshot->count());
        self::assertSame([], $snapshot->names());
    }
}
