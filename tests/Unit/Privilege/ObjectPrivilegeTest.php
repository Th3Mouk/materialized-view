<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Privilege;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Privilege\Exception\InvalidObjectPrivilege;
use Th3Mouk\MaterializedView\Core\Privilege\ObjectPrivilege;

#[Group('privilege')]
final class ObjectPrivilegeTest extends TestCase
{
    public function testNormalisesPrivilegeTypeToUpperCaseAndTrimsGrantee(): void
    {
        $privilege = ObjectPrivilege::granted('  reporting_ro  ', 'select');

        self::assertSame('reporting_ro', $privilege->grantee);
        self::assertSame('SELECT', $privilege->privilegeType);
        self::assertFalse($privilege->withGrantOption);
    }

    public function testMapsGrantableCatalogRowToWithGrantOption(): void
    {
        $privilege = ObjectPrivilege::fromCatalogRow('bi_admin', 'UPDATE', 'YES');

        self::assertTrue($privilege->withGrantOption);
        self::assertSame('UPDATE', $privilege->privilegeType);
    }

    public function testMapsNonGrantableCatalogRowWithoutGrantOption(): void
    {
        $privilege = ObjectPrivilege::fromCatalogRow('app', 'SELECT', 'NO');

        self::assertFalse($privilege->withGrantOption);
    }

    public function testDetectsPublicGranteeCaseInsensitively(): void
    {
        self::assertTrue(ObjectPrivilege::granted('public', 'SELECT')->grantsToPublic());
        self::assertTrue(ObjectPrivilege::granted('PUBLIC', 'SELECT')->grantsToPublic());
        self::assertFalse(ObjectPrivilege::granted('app', 'SELECT')->grantsToPublic());
    }

    public function testRecognisesMultiWordPrivilegeKeyword(): void
    {
        $privilege = ObjectPrivilege::granted('app', 'TRUNCATE');

        self::assertSame('TRUNCATE', $privilege->privilegeType);
    }

    public function testEqualsComparesAllComponents(): void
    {
        $base = ObjectPrivilege::granted('app', 'SELECT');

        self::assertTrue($base->equals(ObjectPrivilege::granted('app', 'SELECT')));
        self::assertFalse($base->equals(ObjectPrivilege::granted('app', 'INSERT')));
        self::assertFalse($base->equals(ObjectPrivilege::granted('other', 'SELECT')));
        self::assertFalse($base->equals(ObjectPrivilege::granted('app', 'SELECT', withGrantOption: true)));
    }

    public function testRejectsBlankGrantee(): void
    {
        $this->expectException(InvalidObjectPrivilege::class);
        $this->expectExceptionMessage('non-empty grantee');

        ObjectPrivilege::granted('   ', 'SELECT');
    }

    public function testRejectsBlankPrivilegeType(): void
    {
        $this->expectException(InvalidObjectPrivilege::class);
        $this->expectExceptionMessage('empty privilege type');

        ObjectPrivilege::granted('app', '   ');
    }

    #[DataProvider('injectionAttemptProvider')]
    public function testRejectsPrivilegeTypeWithUnsupportedCharacters(string $privilegeType): void
    {
        $this->expectException(InvalidObjectPrivilege::class);
        $this->expectExceptionMessage('not a recognised PostgreSQL privilege keyword');

        ObjectPrivilege::granted('app', $privilegeType);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionAttemptProvider(): iterable
    {
        yield 'semicolon injection' => ['SELECT; DROP TABLE users'];
        yield 'comma list' => ['SELECT, INSERT'];
        yield 'digit' => ['SELECT1'];
        yield 'parenthesis' => ['SELECT(col)'];
        yield 'double quote' => ['SE"LECT'];
    }
}
