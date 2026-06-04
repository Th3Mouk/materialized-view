<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Support;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final readonly class FakeConnectionFactory
{
    /**
     * @param array<string, list<array<string, mixed>>>  $matviewRowsBySchema
     * @param array<string, list<array<string, string>>> $indexRowsByView
     * @param list<string>                               $executed
     * @param list<array<string, string>>                $dependencyEdges
     * @param array<string, list<array<string, string>>> $grantRowsByView
     * @param array<string, string>                      $createFailureSqlStateByView
     */
    public static function create(
        TestCase $testCase,
        array $matviewRowsBySchema = [],
        array $indexRowsByView = [],
        array &$executed = [],
        bool|int|string|null $fetchOneReturns = false,
        array $dependencyEdges = [],
        array $grantRowsByView = [],
        array $createFailureSqlStateByView = [],
    ): Connection&Stub {
        $platform = new PostgreSQLPlatform();

        $buildConnection = Closure::bind(
            static fn (TestCase $case): Connection&Stub => $case->getStubBuilder(Connection::class)
                ->disableOriginalConstructor()
                ->onlyMethods([
                    'getDatabasePlatform',
                    'quoteSingleIdentifier',
                    'fetchAllAssociative',
                    'fetchAssociative',
                    'fetchOne',
                    'executeStatement',
                    'transactional',
                ])
                ->getStub(),
            null,
            TestCase::class,
        );

        $connection = $buildConnection($testCase);

        $connection->method('getDatabasePlatform')->willReturn($platform);

        $connection
            ->method('quoteSingleIdentifier')
            ->willReturnCallback(static fn (string $identifier): string => $platform->quoteSingleIdentifier($identifier));

        $connection
            ->method('fetchAllAssociative')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$executed, $matviewRowsBySchema, $indexRowsByView, $dependencyEdges, $grantRowsByView): array {
                    if (self::isDependencyQuery($sql)) {
                        return $dependencyEdges;
                    }

                    if (self::isGrantsQuery($sql)) {
                        $schema = (string) ($params['schema'] ?? '');
                        $name = (string) ($params['name'] ?? '');

                        if (self::isDropped($schema, $name, $executed)) {
                            return [];
                        }

                        return $grantRowsByView[$schema.'.'.$name] ?? [];
                    }

                    if (self::isIndexQuery($sql)) {
                        $view = ((string) ($params['schema_name'] ?? '')).'.'.((string) ($params['view_name'] ?? ''));

                        return $indexRowsByView[$view] ?? [];
                    }

                    if (self::isSchemaMatviewQuery($sql)) {
                        return $matviewRowsBySchema[(string) ($params['schema_name'] ?? '')] ?? [];
                    }

                    return [];
                },
            );

        $connection
            ->method('fetchAssociative')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$executed, $matviewRowsBySchema): array|false {
                    $schema = (string) ($params['schema_name'] ?? '');
                    $name = (string) ($params['view_name'] ?? '');

                    if (self::isDropped($schema, $name, $executed)) {
                        return false;
                    }

                    foreach ($matviewRowsBySchema[$schema] ?? [] as $row) {
                        if (($row['view_name'] ?? null) === $name) {
                            return $row;
                        }
                    }

                    return false;
                },
            );

        $connection->method('fetchOne')->willReturn($fetchOneReturns);

        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$executed, $createFailureSqlStateByView): int {
                $executed[] = $sql;

                if (str_contains($sql, 'CREATE MATERIALIZED VIEW')) {
                    foreach ($createFailureSqlStateByView as $view => $sqlState) {
                        if (str_contains($sql, self::quoteQualified($view))) {
                            throw self::dbalDriverException($sqlState);
                        }
                    }
                }

                return 0;
            });

        $connection
            ->method('transactional')
            ->willReturnCallback(static fn (Closure $func): mixed => $func($connection));

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public static function matviewRow(
        string $schema,
        string $name,
        ?string $comment,
        bool $isPopulated = true,
        string $definition = 'SELECT 1',
    ): array {
        return [
            'schema_name' => $schema,
            'view_name' => $name,
            'definition' => $definition,
            'is_populated' => $isPopulated,
            'comment' => $comment,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function dependencyEdge(string $dependent, string $referenced): array
    {
        [$dependentSchema, $dependentName] = self::split($dependent);
        [$referencedSchema, $referencedName] = self::split($referenced);

        return [
            'dependent_schema' => $dependentSchema,
            'dependent_view' => $dependentName,
            'dependent_relkind' => 'm',
            'referenced_schema' => $referencedSchema,
            'referenced_view' => $referencedName,
            'referenced_relkind' => 'm',
        ];
    }

    /**
     * @return array{string, string}
     */
    private static function split(string $qualifiedName): array
    {
        $parts = explode('.', $qualifiedName);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    private static function isSchemaMatviewQuery(string $sql): bool
    {
        return str_contains($sql, "c.relkind = 'm'") && str_contains($sql, 'ORDER BY c.relname');
    }

    private static function isIndexQuery(string $sql): bool
    {
        return str_contains($sql, 'pg_indexes');
    }

    private static function isDependencyQuery(string $sql): bool
    {
        return str_contains($sql, 'pg_depend') && str_contains($sql, 'pg_rewrite');
    }

    private static function isGrantsQuery(string $sql): bool
    {
        return str_contains($sql, 'information_schema.role_table_grants');
    }

    /**
     * @param list<string> $executed
     */
    private static function isDropped(string $schema, string $name, array $executed): bool
    {
        $qualified = \sprintf('"%s"."%s"', $schema, $name);

        $dropped = false;
        foreach ($executed as $statement) {
            if (str_contains($statement, 'DROP MATERIALIZED VIEW') && str_contains($statement, $qualified)) {
                $dropped = true;

                continue;
            }

            if (str_contains($statement, 'CREATE MATERIALIZED VIEW') && str_contains($statement, $qualified)) {
                $dropped = false;
            }
        }

        return $dropped;
    }

    /**
     * @return array<string, string>
     */
    public static function grantRow(string $grantee, string $privilegeType, bool $isGrantable = false): array
    {
        return [
            'grantee' => $grantee,
            'privilege_type' => $privilegeType,
            'is_grantable' => $isGrantable ? 'YES' : 'NO',
        ];
    }

    private static function quoteQualified(string $qualifiedName): string
    {
        [$schema, $name] = self::split($qualifiedName);

        return \sprintf('"%s"."%s"', $schema, $name);
    }

    private static function dbalDriverException(string $sqlState): DriverException
    {
        $driverException = new class('relation does not exist', $sqlState) extends DriverAbstractException {};

        return new DriverException($driverException, null);
    }
}
