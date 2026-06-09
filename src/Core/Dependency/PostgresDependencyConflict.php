<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Core\Dependency;

use Doctrine\DBAL\Exception\DriverException;
use PDOException;
use Th3Mouk\MaterializedView\Core\Sql\DependencyConflictSqlState;
use Th3Mouk\MaterializedView\Core\Sql\QualifiedName;
use Throwable;

/**
 * Classifies a PostgreSQL "an object depends on this" DDL failure into a structured,
 * locale-best-effort result for the reactive targeted-drop lane.
 *
 * Gate on SQLSTATE, never on the exception class: PostgreSQL surfaces both relevant
 * states as the generic Doctrine\DBAL\Exception\DriverException, and 0A000 is even
 * shared with the TRUNCATE/foreign-key case.
 *
 *   2BP01 (dependent_objects_still_exist) — DROP COLUMN / DROP TABLE blocked.
 *         The ERROR line names the blocked relation; DETAIL lists the dependents:
 *         "<materialized view|view> <name> depends on ...".
 *   0A000 (feature_not_supported)        — ALTER COLUMN ... TYPE blocked by a view/rule.
 *         The ERROR line names neither the table nor the column; only DETAIL names the
 *         object, via its rewrite rule: "rule _RETURN on <materialized view|view> <name> ...".
 *
 * The ERROR/DETAIL text is emitted in the server's lc_messages locale, which the
 * application role cannot pin at runtime (SET and PGOPTIONS are superuser-only). Parsing
 * is therefore best-effort: a gated error whose text is absent or non-English yields
 * isParsed() === false with an empty dependent list, rather than throwing or guessing,
 * so the caller falls back (e.g. drop-all). The authoritative dependent closure must
 * still be resolved from the system catalog: DETAIL is only a seed and PostgreSQL caps
 * the printed list at 100 entries ("and N other objects").
 */
final readonly class PostgresDependencyConflict
{
    /**
     * @param list<ParsedDependent> $dependents
     */
    private function __construct(
        private string $sqlState,
        private ?QualifiedName $blockedRelation,
        private array $dependents,
        private bool $truncated,
    ) {
    }

    /**
     * Build from a DBAL exception, or null when its SQLSTATE is not one we react to.
     * The raw PostgreSQL text is read from the deepest \PDOException in the chain
     * (errorInfo[2]); the chain depth is not hard-coded, and getMessage() is the
     * fallback. SQLSTATE comes from getSQLState(), falling back to errorInfo[0].
     */
    public static function fromDriverException(DriverException $exception): ?self
    {
        $pdoException = self::deepestPdoException($exception);

        $sqlState = $exception->getSQLState();
        $rawErrorText = null;

        if (null !== $pdoException) {
            $errorInfo = $pdoException->errorInfo;

            if (\is_array($errorInfo)) {
                if ((null === $sqlState || '' === $sqlState) && isset($errorInfo[0]) && \is_string($errorInfo[0])) {
                    $sqlState = $errorInfo[0];
                }

                if (isset($errorInfo[2]) && \is_string($errorInfo[2])) {
                    $rawErrorText = $errorInfo[2];
                }
            }
        }

        if (null === $sqlState || !DependencyConflictSqlState::isDependencyConflictState($sqlState)) {
            return null;
        }

        return self::parse($sqlState, $rawErrorText ?? $exception->getMessage());
    }

    /**
     * Build from a raw SQLSTATE and PostgreSQL error payload, or null when not gated.
     * The payload may be either the raw errorInfo[2] form (beginning at "ERROR:") or
     * the DBAL-wrapped getMessage() form (with a leading prefix): the parser anchors on
     * the "ERROR:"/"DETAIL:" markers and tolerates either.
     */
    public static function fromRawError(string $sqlState, string $rawErrorText): ?self
    {
        if (!DependencyConflictSqlState::isDependencyConflictState($sqlState)) {
            return null;
        }

        return self::parse($sqlState, $rawErrorText);
    }

    public function sqlState(): string
    {
        return $this->sqlState;
    }

    public function blockedRelation(): ?QualifiedName
    {
        return $this->blockedRelation;
    }

    /**
     * @return list<ParsedDependent>
     */
    public function dependents(): array
    {
        return $this->dependents;
    }

    /**
     * Whether at least one dependent object was extracted from DETAIL. False means the
     * text was absent or not in a recognised (English) shape, and the caller must fall
     * back (e.g. drop-all). This is independent of blockedRelation(): for 2BP01 the
     * blocked relation can be a usable catalog seed even when isParsed() is false.
     */
    public function isParsed(): bool
    {
        return [] !== $this->dependents;
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    private static function parse(string $sqlState, string $rawErrorText): self
    {
        $text = str_replace(["\r\n", "\r"], "\n", $rawErrorText);

        $blockedRelation = null;

        if (DependencyConflictSqlState::DEPENDENT_OBJECTS_STILL_EXIST === $sqlState) {
            $errorPrimary = self::matchGroup('/ERROR:[ \t]*([^\n]*)/', $text);

            if (null !== $errorPrimary) {
                $blockedRelation = self::parseBlockedRelation($errorPrimary);
            }
        }

        $dependents = [];
        $truncated = false;

        $detailBlock = self::matchGroup('/^DETAIL:[ \t]*(.*?)(?=^HINT:|\z)/ms', $text);

        if (null !== $detailBlock) {
            foreach (explode("\n", $detailBlock) as $rawLine) {
                $line = trim($rawLine);

                if ('' === $line) {
                    continue;
                }

                if (1 === preg_match('/^and \d+ other object/', $line)) {
                    $truncated = true;

                    continue;
                }

                $dependent = self::parseDependentLine($sqlState, $line);

                if (null !== $dependent) {
                    $dependents[] = $dependent;
                }
            }
        }

        return new self($sqlState, $blockedRelation, $dependents, $truncated);
    }

    private static function parseBlockedRelation(string $errorPrimary): ?QualifiedName
    {
        $dropTable = self::markerOffset($errorPrimary, 'cannot drop table ');

        if (null !== $dropTable) {
            $end = 0;

            return QualifiedName::scan($errorPrimary, $dropTable, $end);
        }

        // "cannot drop column <col> of table <rel> …": skip the column name quote-aware
        // before locating " of table ", so a column whose name embeds that substring
        // (e.g. "x of table y") cannot misdirect the search to the wrong relation.
        $dropColumn = self::markerOffset($errorPrimary, 'cannot drop column ');

        if (null === $dropColumn) {
            return null;
        }

        $columnEnd = 0;

        if (null === QualifiedName::scan($errorPrimary, $dropColumn, $columnEnd)) {
            return null;
        }

        $ofTable = self::markerOffset(substr($errorPrimary, $columnEnd), ' of table ');

        if (null === $ofTable) {
            return null;
        }

        $end = 0;

        return QualifiedName::scan($errorPrimary, $columnEnd + $ofTable, $end);
    }

    private static function parseDependentLine(string $sqlState, string $line): ?ParsedDependent
    {
        if (DependencyConflictSqlState::FEATURE_NOT_SUPPORTED === $sqlState) {
            return self::parseRuleDependentLine($line);
        }

        return self::parseDirectDependentLine($line);
    }

    private static function parseDirectDependentLine(string $line): ?ParsedDependent
    {
        $materializedViewPrefix = 'materialized view ';

        if (str_starts_with($line, $materializedViewPrefix)) {
            return self::scanDependent($line, \strlen($materializedViewPrefix), ParsedDependentKind::MaterializedView);
        }

        $viewPrefix = 'view ';

        if (str_starts_with($line, $viewPrefix)) {
            return self::scanDependent($line, \strlen($viewPrefix), ParsedDependentKind::View);
        }

        return null;
    }

    private static function parseRuleDependentLine(string $line): ?ParsedDependent
    {
        if (!str_starts_with($line, 'rule ')) {
            return null;
        }

        $materializedViewMarker = ' on materialized view ';
        $position = strpos($line, $materializedViewMarker);

        if (false !== $position) {
            return self::scanDependent($line, $position + \strlen($materializedViewMarker), ParsedDependentKind::MaterializedView);
        }

        $viewMarker = ' on view ';
        $position = strpos($line, $viewMarker);

        if (false !== $position) {
            return self::scanDependent($line, $position + \strlen($viewMarker), ParsedDependentKind::View);
        }

        return null;
    }

    private static function scanDependent(string $line, int $offset, ParsedDependentKind $kind): ?ParsedDependent
    {
        $end = 0;
        $name = QualifiedName::scan($line, $offset, $end);

        if (null === $name) {
            return null;
        }

        if (!str_starts_with(substr($line, $end), ' depends on')) {
            return null;
        }

        return new ParsedDependent($kind, $name);
    }

    private static function deepestPdoException(Throwable $throwable): ?PDOException
    {
        $found = null;
        $current = $throwable;
        $guard = 0;

        while (null !== $current && $guard < 20) {
            if ($current instanceof PDOException) {
                $found = $current;
            }

            $current = $current->getPrevious();
            ++$guard;
        }

        return $found;
    }

    private static function matchGroup(string $pattern, string $subject): ?string
    {
        if (1 === preg_match($pattern, $subject, $matches) && isset($matches[1])) {
            return $matches[1];
        }

        return null;
    }

    private static function markerOffset(string $haystack, string $marker): ?int
    {
        $position = strpos($haystack, $marker);

        if (false === $position) {
            return null;
        }

        return $position + \strlen($marker);
    }
}
