<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

/**
 * Tells whether a single SQL statement can never be blocked by a (materialized) view
 * depending on the relation it touches, i.e. can never raise SQLSTATE 2BP01 or 0A000.
 *
 * It is an allowlist; anything outside it, or anything it cannot read with certainty, is
 * not safe:
 *
 *   CREATE [UNIQUE] INDEX …            DROP INDEX …
 *   REINDEX …                          ANALYZE …      VACUUM …
 *   ALTER TABLE [IF EXISTS] [ONLY] <name> VALIDATE CONSTRAINT <name>   (that action alone)
 *
 * Comments, whitespace, letter case and trailing semicolons are tolerated. Several
 * statements in one string, dollar-quoted text, backslashes inside a string literal, or an
 * unterminated comment or quote make the statement not safe.
 */
final readonly class DependencySafeStatement
{
    private const string IDENTIFIER = '(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)';

    private const string DOLLAR_QUOTE = '/\G\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/';

    public static function isSafe(string $sql): bool
    {
        $normalized = self::normalize($sql);

        if (null === $normalized) {
            return false;
        }

        return array_any(
            self::patterns(),
            static fn (string $pattern): bool => 1 === preg_match($pattern, $normalized),
        );
    }

    /**
     * @param iterable<string> $statements
     */
    public static function areAllSafe(iterable $statements): bool
    {
        foreach ($statements as $statement) {
            if (!self::isSafe($statement)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function patterns(): array
    {
        $name = self::IDENTIFIER.'(?:\s*\.\s*'.self::IDENTIFIER.')*';

        return [
            '/^CREATE\s+(?:UNIQUE\s+)?INDEX\s/i',
            '/^DROP\s+INDEX\s/i',
            '/^(?:REINDEX|ANALY[SZ]E|VACUUM)(?:\s|\(|$)/i',
            '/^ALTER\s+TABLE\s+(?:(?:ONLY|IF\s+EXISTS)\s+){0,2}'.$name.'\s+VALIDATE\s+CONSTRAINT\s+'.self::IDENTIFIER.'$/iD',
        ];
    }

    private static function normalize(string $sql): ?string
    {
        $length = \strlen($sql);
        $output = '';
        $terminated = false;

        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];

            if ('--' === substr($sql, $i, 2)) {
                $newline = strpos($sql, "\n", $i);
                $i = false === $newline ? $length : $newline;
                $output .= ' ';

                continue;
            }

            if ('/*' === substr($sql, $i, 2)) {
                $end = self::endOfBlockComment($sql, $i);

                if (null === $end) {
                    return null;
                }

                $i = $end;
                $output .= ' ';

                continue;
            }

            if (ctype_space($char)) {
                $output .= $char;

                continue;
            }

            if (';' === $char) {
                $terminated = true;

                continue;
            }

            if ($terminated) {
                return null;
            }

            if ("'" === $char || '"' === $char) {
                $end = self::endOfQuoted($sql, $i, $char);

                if (null === $end) {
                    return null;
                }

                $output .= substr($sql, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ('$' === $char && 1 === preg_match(self::DOLLAR_QUOTE, $sql, $matches, 0, $i)) {
                return null;
            }

            $output .= $char;
        }

        $output = trim($output);

        return '' === $output ? null : $output;
    }

    private static function endOfBlockComment(string $sql, int $start): ?int
    {
        $length = \strlen($sql);
        $depth = 0;

        for ($i = $start; $i < $length - 1; ++$i) {
            $pair = $sql[$i].$sql[$i + 1];

            if ('/*' === $pair) {
                ++$depth;
                ++$i;
            } elseif ('*/' === $pair) {
                --$depth;
                ++$i;

                if (0 === $depth) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function endOfQuoted(string $sql, int $start, string $quote): ?int
    {
        $length = \strlen($sql);

        for ($i = $start + 1; $i < $length; ++$i) {
            if ('\\' === $sql[$i] && "'" === $quote) {
                return null;
            }

            if ($quote !== $sql[$i]) {
                continue;
            }

            if ($i + 1 < $length && $quote === $sql[$i + 1]) {
                ++$i;

                continue;
            }

            return $i;
        }

        return null;
    }
}
