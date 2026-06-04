<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Hashing;

final class SqlCanonicalizer
{
    private const string DOLLAR_TAG_PATTERN = '/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/';

    public function canonicalize(string $sql): string
    {
        $length = \strlen($sql);
        $canonical = '';
        $pendingWhitespace = false;
        $offset = 0;

        while ($offset < $length) {
            $character = $sql[$offset];

            if ($this->isWhitespace($character)) {
                $pendingWhitespace = true;
                ++$offset;

                continue;
            }

            if ($this->startsLineComment($sql, $offset)) {
                $pendingWhitespace = true;
                $offset = $this->skipLineComment($sql, $offset, $length);

                continue;
            }

            if ($this->startsBlockComment($sql, $offset)) {
                $pendingWhitespace = true;
                $offset = $this->skipBlockComment($sql, $offset, $length);

                continue;
            }

            if ($pendingWhitespace && '' !== $canonical) {
                $canonical .= ' ';
            }

            $pendingWhitespace = false;

            if ("'" === $character) {
                $end = $this->endOfSingleQuotedString($sql, $offset, $length);
                $canonical .= substr($sql, $offset, $end - $offset);
                $offset = $end;

                continue;
            }

            if ('"' === $character) {
                $end = $this->endOfQuotedIdentifier($sql, $offset, $length);
                $canonical .= substr($sql, $offset, $end - $offset);
                $offset = $end;

                continue;
            }

            $dollarTag = $this->matchDollarTag($sql, $offset);

            if (null !== $dollarTag) {
                $end = $this->endOfDollarQuotedString($sql, $offset, $length, $dollarTag);
                $canonical .= substr($sql, $offset, $end - $offset);
                $offset = $end;

                continue;
            }

            $canonical .= $character;
            ++$offset;
        }

        return $this->dropTrailingSemicolon($canonical);
    }

    private function isWhitespace(string $character): bool
    {
        return ' ' === $character
            || "\t" === $character
            || "\n" === $character
            || "\r" === $character
            || "\f" === $character
            || "\v" === $character;
    }

    private function startsLineComment(string $sql, int $offset): bool
    {
        return '-' === $sql[$offset] && '-' === ($sql[$offset + 1] ?? '');
    }

    private function skipLineComment(string $sql, int $offset, int $length): int
    {
        $offset += 2;

        while ($offset < $length && "\n" !== $sql[$offset]) {
            ++$offset;
        }

        return $offset;
    }

    private function startsBlockComment(string $sql, int $offset): bool
    {
        return '/' === $sql[$offset] && '*' === ($sql[$offset + 1] ?? '');
    }

    private function skipBlockComment(string $sql, int $offset, int $length): int
    {
        $offset += 2;
        $depth = 1;

        while ($offset < $length && $depth > 0) {
            if ('/' === $sql[$offset] && '*' === ($sql[$offset + 1] ?? '')) {
                ++$depth;
                $offset += 2;

                continue;
            }

            if ('*' === $sql[$offset] && '/' === ($sql[$offset + 1] ?? '')) {
                --$depth;
                $offset += 2;

                continue;
            }

            ++$offset;
        }

        return $offset;
    }

    private function endOfSingleQuotedString(string $sql, int $offset, int $length): int
    {
        ++$offset;

        while ($offset < $length) {
            if ("'" === $sql[$offset]) {
                if ("'" === ($sql[$offset + 1] ?? '')) {
                    $offset += 2;

                    continue;
                }

                return $offset + 1;
            }

            ++$offset;
        }

        return $length;
    }

    private function endOfQuotedIdentifier(string $sql, int $offset, int $length): int
    {
        ++$offset;

        while ($offset < $length) {
            if ('"' === $sql[$offset]) {
                if ('"' === ($sql[$offset + 1] ?? '')) {
                    $offset += 2;

                    continue;
                }

                return $offset + 1;
            }

            ++$offset;
        }

        return $length;
    }

    private function matchDollarTag(string $sql, int $offset): ?string
    {
        if ('$' !== $sql[$offset]) {
            return null;
        }

        if (1 !== preg_match(self::DOLLAR_TAG_PATTERN, $sql, $matches, 0, $offset)) {
            return null;
        }

        return $matches[0];
    }

    private function endOfDollarQuotedString(string $sql, int $offset, int $length, string $tag): int
    {
        $offset += \strlen($tag);
        $closingPosition = strpos($sql, $tag, $offset);

        if (false === $closingPosition) {
            return $length;
        }

        return $closingPosition + \strlen($tag);
    }

    private function dropTrailingSemicolon(string $canonical): string
    {
        $trimmed = rtrim($canonical);

        if (str_ends_with($trimmed, ';')) {
            return rtrim(substr($trimmed, 0, -1));
        }

        return $trimmed;
    }
}
