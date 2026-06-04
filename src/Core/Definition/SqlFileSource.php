<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Definition;

use Th3Mouk\MaterializedView\Core\Exception\CannotReadSqlSource;

final readonly class SqlFileSource implements SqlSource
{
    private function __construct(
        public string $path,
    ) {
    }

    public static function fromAbsolutePath(string $absolutePath): self
    {
        return new self($absolutePath);
    }

    public static function fromProjectPath(string $relativePath, ?string $projectDir = null): self
    {
        $base = $projectDir ?? getcwd();
        $base = false === $base ? '.' : $base;

        return new self(rtrim($base, '/').'/'.ltrim($relativePath, '/'));
    }

    public function sql(): string
    {
        if (!is_file($this->path)) {
            throw CannotReadSqlSource::fileNotFound($this->path);
        }

        if (!is_readable($this->path)) {
            throw CannotReadSqlSource::fileNotReadable($this->path);
        }

        $contents = file_get_contents($this->path);

        if (false === $contents || '' === trim($contents)) {
            throw CannotReadSqlSource::fileIsEmpty($this->path);
        }

        return $contents;
    }

    public function identifier(): string
    {
        return $this->path;
    }
}
