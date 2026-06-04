<?php

declare(strict_types=1);

// Automated refactoring configuration only — no library logic lives here.

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets(php84: true)
    ->withSets([LevelSetList::UP_TO_PHP_84])
    ->withImportNames(removeUnusedImports: true);
