<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Sql;

/**
 * A PostgreSQL schema-qualified identifier as the server renders it in catalog and
 * error output: each part is either an unquoted identifier or a double-quoted one
 * (with "" as an escaped embedded quote). The value object stores the *logical*
 * (de-quoted) parts; render() produces a SQL-safe, re-quoted form suitable for
 * to_regclass().
 *
 * This is intentionally distinct from {@see \Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName}:
 * that type is a *validated* managed-view name (mandatory schema, simple identifiers
 * only), whereas this one is a *best-effort parsed reference* that must tolerate
 * unqualified names and arbitrary quoted identifiers as they appear in raw server
 * error text. A null schema means "unqualified" (the server did not print a schema)
 * and is intentionally not treated as "public": to_regclass resolves it through the
 * session search_path.
 *
 * Equality is logical and case-sensitive: PostgreSQL has already case-folded unquoted
 * identifiers and preserved quoted ones before printing, so the parsed parts are
 * compared byte-for-byte and never re-folded.
 */
final readonly class QualifiedName
{
    public function __construct(
        public ?string $schema,
        public string $name,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->schema === $other->schema && $this->name === $other->name;
    }

    public function render(): string
    {
        $rendered = self::quoteIfNeeded($this->name);

        if (null !== $this->schema) {
            return self::quoteIfNeeded($this->schema).'.'.$rendered;
        }

        return $rendered;
    }

    /**
     * Scan one qualified identifier from $input starting at $offset, writing the
     * position just past it into $offsetAfter.
     *
     * Quote-aware: an unquoted "." separates schema from name and unquoted whitespace
     * ends the identifier, while a double-quoted run may legally contain ".", spaces,
     * or escaped quotes ("") — so the delimiter is never matched by naive string
     * search. Returns null when no complete identifier can be read (an unterminated
     * quote, an empty unquoted token, or end-of-input), letting callers degrade
     * gracefully instead of throwing.
     */
    public static function scan(string $input, int $offset, int &$offsetAfter): ?self
    {
        $length = \strlen($input);
        $position = $offset;
        $parts = [];

        while (true) {
            if ($position >= $length) {
                return null;
            }

            if ('"' === $input[$position]) {
                $part = self::scanQuotedPart($input, $length, $position);

                if (null === $part) {
                    return null;
                }

                $parts[] = $part;
            } else {
                $start = $position;

                while ($position < $length && !self::isUnquotedDelimiter($input[$position])) {
                    ++$position;
                }

                if ($position === $start) {
                    return null;
                }

                $parts[] = substr($input, $start, $position - $start);
            }

            if ($position < $length && '.' === $input[$position]) {
                ++$position;

                continue;
            }

            break;
        }

        $offsetAfter = $position;
        $count = \count($parts);
        $name = $parts[$count - 1];
        $schema = $count > 1 ? $parts[$count - 2] : null;

        return new self($schema, $name);
    }

    /**
     * Consume a double-quoted run starting at the opening quote ($position), advancing
     * $position past the closing quote. Returns the de-escaped content, or null when
     * the quote is never closed.
     */
    private static function scanQuotedPart(string $input, int $length, int &$position): ?string
    {
        ++$position;
        $buffer = '';

        while ($position < $length) {
            if ('"' === $input[$position]) {
                if ($position + 1 < $length && '"' === $input[$position + 1]) {
                    $buffer .= '"';
                    $position += 2;

                    continue;
                }

                ++$position;

                return $buffer;
            }

            $buffer .= $input[$position];
            ++$position;
        }

        return null;
    }

    private static function isUnquotedDelimiter(string $character): bool
    {
        return '.' === $character
            || '"' === $character
            || ' ' === $character
            || "\t" === $character
            || "\n" === $character;
    }

    private static function quoteIfNeeded(string $identifier): string
    {
        if ('' !== $identifier && 1 === preg_match('/\A[a-z_][a-z0-9_$]*\z/', $identifier)) {
            return $identifier;
        }

        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
