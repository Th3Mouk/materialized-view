<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sync;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Introspection\IntrospectedMaterializedView;

final readonly class MaterializedViewComparison
{
    private function __construct(
        public MaterializedViewName $name,
        public SyncAction $action,
        public ?MaterializedViewDefinition $definition,
        public ?IntrospectedMaterializedView $liveState,
        public ?string $desiredHash,
    ) {
    }

    public static function create(MaterializedViewDefinition $definition, string $desiredHash): self
    {
        return new self(
            name: $definition->name(),
            action: SyncAction::Create,
            definition: $definition,
            liveState: null,
            desiredHash: $desiredHash,
        );
    }

    public static function rebuild(
        MaterializedViewDefinition $definition,
        IntrospectedMaterializedView $liveState,
        string $desiredHash,
    ): self {
        return new self(
            name: $definition->name(),
            action: SyncAction::Rebuild,
            definition: $definition,
            liveState: $liveState,
            desiredHash: $desiredHash,
        );
    }

    public static function upToDate(
        MaterializedViewDefinition $definition,
        IntrospectedMaterializedView $liveState,
        string $desiredHash,
    ): self {
        return new self(
            name: $definition->name(),
            action: SyncAction::UpToDate,
            definition: $definition,
            liveState: $liveState,
            desiredHash: $desiredHash,
        );
    }

    public static function orphan(IntrospectedMaterializedView $liveState): self
    {
        return new self(
            name: $liveState->name,
            action: SyncAction::Orphan,
            definition: null,
            liveState: $liveState,
            desiredHash: null,
        );
    }
}
