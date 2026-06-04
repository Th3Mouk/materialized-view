<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Definition\InlineSqlSource;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Hashing\DefinitionHasher;
use Th3Mouk\MaterializedView\Core\Introspection\PostgreSqlMaterializedViewIntrospector;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sync\MaterializedViewComparator;
use Th3Mouk\MaterializedView\Core\Sync\SyncAction;
use Th3Mouk\MaterializedView\Tests\Unit\Support\FakeConnectionFactory;

#[Group('sync')]
final class MaterializedViewComparatorTest extends TestCase
{
    private DefinitionHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = DefinitionHasher::create();
    }

    public function testFlagsAbsentViewAsCreate(): void
    {
        $registry = MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]);

        $plan = $this->comparator([])->compare($registry);

        self::assertCount(1, $plan->toCreate());
        self::assertSame('public.summary', $plan->toCreate()[0]->name->qualifiedName());
        self::assertSame(SyncAction::Create, $plan->toCreate()[0]->action);
        self::assertSame([], $plan->toRebuild());
        self::assertSame([], $plan->orphans());
        self::assertSame($this->hasher->hash($this->summaryDefinition()), $plan->toCreate()[0]->desiredHash);
    }

    public function testFlagsMatchingHashAsUpToDate(): void
    {
        $definition = $this->summaryDefinition();
        $liveComment = $this->markerJsonFor($definition);

        $plan = $this->comparator([
            'public' => [FakeConnectionFactory::matviewRow('public', 'summary', $liveComment)],
        ])->compare(MaterializedViewRegistry::fromDefinitions([$definition]));

        self::assertCount(1, $plan->upToDate());
        self::assertSame('public.summary', $plan->upToDate()[0]->name->qualifiedName());
        self::assertSame([], $plan->toCreate());
        self::assertSame([], $plan->toRebuild());
        self::assertFalse($plan->hasPendingWrites());
    }

    public function testFlagsHashDriftAsRebuild(): void
    {
        $definition = $this->summaryDefinition();
        $staleComment = ManagementMarker::create('a-stale-hash')->toJson();

        $plan = $this->comparator([
            'public' => [FakeConnectionFactory::matviewRow('public', 'summary', $staleComment)],
        ])->compare(MaterializedViewRegistry::fromDefinitions([$definition]));

        self::assertCount(1, $plan->toRebuild());
        self::assertSame(SyncAction::Rebuild, $plan->toRebuild()[0]->action);
        self::assertNotNull($plan->toRebuild()[0]->liveState);
        self::assertTrue($plan->hasPendingWrites());
    }

    public function testTreatsManagedButUndeclaredViewAsOrphan(): void
    {
        $orphanComment = ManagementMarker::create('whatever-hash')->toJson();

        $plan = $this->comparator([
            'public' => [FakeConnectionFactory::matviewRow('public', 'legacy_view', $orphanComment)],
        ])->compare(MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]));

        self::assertCount(1, $plan->orphans());
        self::assertSame('public.legacy_view', $plan->orphans()[0]->name->qualifiedName());
        self::assertSame(SyncAction::Orphan, $plan->orphans()[0]->action);
        self::assertCount(1, $plan->toCreate());
    }

    public function testIgnoresUnmanagedLiveViewWithoutManagementComment(): void
    {
        $plan = $this->comparator([
            'public' => [
                FakeConnectionFactory::matviewRow('public', 'hand_made_view', null),
                FakeConnectionFactory::matviewRow('public', 'free_text_view', 'a human note, not JSON'),
            ],
        ])->compare(MaterializedViewRegistry::fromDefinitions([$this->summaryDefinition()]));

        self::assertSame([], $plan->orphans());
        self::assertCount(1, $plan->toCreate());
    }

    public function testTreatsManagedViewWithUnparseableCommentAsRebuild(): void
    {
        $definition = $this->summaryDefinition();

        $plan = $this->comparator([
            'public' => [FakeConnectionFactory::matviewRow('public', 'summary', '{not valid json')],
        ])->compare(MaterializedViewRegistry::fromDefinitions([$definition]));

        self::assertCount(1, $plan->toRebuild());
        self::assertSame('public.summary', $plan->toRebuild()[0]->name->qualifiedName());
    }

    public function testTreatsManagedViewWithMissingHashKeyAsRebuild(): void
    {
        $definition = $this->summaryDefinition();
        $commentWithoutHash = (string) json_encode(['th3mouk_materialized_view' => ['version' => 1]]);

        $plan = $this->comparator([
            'public' => [FakeConnectionFactory::matviewRow('public', 'summary', $commentWithoutHash)],
        ])->compare(MaterializedViewRegistry::fromDefinitions([$definition]));

        self::assertCount(1, $plan->toRebuild());
    }

    public function testIntrospectsEverySchemaReferencedByTheRegistry(): void
    {
        $public = $this->summaryDefinition();
        $analytics = MaterializedViewDefinition::create('analytics.rollup')
            ->fromSql(InlineSqlSource::fromString('SELECT 2 AS id'));

        $plan = $this->comparator([
            'public' => [FakeConnectionFactory::matviewRow('public', 'summary', $this->markerJsonFor($public))],
            'analytics' => [FakeConnectionFactory::matviewRow('analytics', 'rollup', $this->markerJsonFor($analytics))],
        ])->compare(MaterializedViewRegistry::fromDefinitions([$public, $analytics]));

        self::assertCount(2, $plan->upToDate());
        self::assertSame([], $plan->toCreate());
        self::assertSame([], $plan->toRebuild());
    }

    public function testComparesASingleDefinitionAgainstAbsentLiveState(): void
    {
        $comparison = $this->comparator([])->compareOne($this->summaryDefinition(), null);

        self::assertSame(SyncAction::Create, $comparison->action);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $matviewRowsBySchema
     */
    private function comparator(array $matviewRowsBySchema): MaterializedViewComparator
    {
        $connection = FakeConnectionFactory::create($this, $matviewRowsBySchema);

        return new MaterializedViewComparator(
            new PostgreSqlMaterializedViewIntrospector($connection),
            $this->hasher,
        );
    }

    private function markerJsonFor(MaterializedViewDefinition $definition): string
    {
        return ManagementMarker::create($this->hasher->hash($definition))->toJson();
    }

    private function summaryDefinition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.summary')
            ->fromSql(InlineSqlSource::fromString('SELECT 1 AS product_id, 2 AS score_id'));
    }
}
