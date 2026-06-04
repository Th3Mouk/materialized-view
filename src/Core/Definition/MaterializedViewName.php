<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Definition;

use Th3Mouk\MaterializedView\Core\Exception\InvalidViewName;

final readonly class MaterializedViewName
{
    public const string DEFAULT_SCHEMA = 'public';

    private const int MAX_IDENTIFIER_LENGTH = 63;

    private const string IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private function __construct(
        public string $schema,
        public string $name,
    ) {
    }

    public static function create(string $schema, string $name): self
    {
        return new self(
            self::assertValidIdentifier($schema),
            self::assertValidIdentifier($name),
        );
    }

    public static function fromString(string $qualifiedName, string $defaultSchema = self::DEFAULT_SCHEMA): self
    {
        $trimmed = trim($qualifiedName);

        if ('' === $trimmed) {
            throw InvalidViewName::empty();
        }

        $parts = explode('.', $trimmed);

        if (\count($parts) > 2) {
            throw InvalidViewName::tooManyParts($qualifiedName);
        }

        if (1 === \count($parts)) {
            return self::create($defaultSchema, $parts[0]);
        }

        [$schema, $name] = $parts;

        if ('' === $schema || '' === $name) {
            throw InvalidViewName::blankSegment($qualifiedName);
        }

        return self::create($schema, $name);
    }

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->name;
    }

    public function equals(self $other): bool
    {
        return $this->schema === $other->schema
            && $this->name === $other->name;
    }

    private static function assertValidIdentifier(string $identifier): string
    {
        if ('' === $identifier) {
            throw InvalidViewName::empty();
        }

        if (\strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            throw InvalidViewName::tooLong($identifier, self::MAX_IDENTIFIER_LENGTH);
        }

        if (1 !== preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw InvalidViewName::unsupportedCharacters($identifier);
        }

        return $identifier;
    }
}
