<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedView\Tests\Unit\Dependency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Dependency\DependencySafeStatement;

#[Group('sync')]
final class DependencySafeStatementTest extends TestCase
{
    #[DataProvider('safeStatementProvider')]
    public function testRecognisesDependencySafeStatements(string $sql): void
    {
        self::assertTrue(DependencySafeStatement::isSafe($sql));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeStatementProvider(): iterable
    {
        yield 'create index' => ['CREATE INDEX idx_orders_total ON orders (total)'];
        yield 'create unique index concurrently' => ['CREATE UNIQUE INDEX CONCURRENTLY uniq_orders_ref ON public.orders (reference)'];
        yield 'create index if not exists with a partial predicate' => ["CREATE INDEX IF NOT EXISTS idx_open ON orders (status) WHERE status = 'open;closed'"];
        yield 'create index on a quoted relation' => ['CREATE INDEX CONCURRENTLY "idx;x" ON "Public"."Orders" USING gin (payload)'];
        yield 'drop index' => ['DROP INDEX idx_orders_total'];
        yield 'drop index concurrently if exists' => ['DROP INDEX CONCURRENTLY IF EXISTS public.idx_orders_total'];
        yield 'reindex' => ['REINDEX TABLE CONCURRENTLY orders'];
        yield 'reindex with options' => ['REINDEX (VERBOSE) INDEX idx_orders_total'];
        yield 'analyze' => ['ANALYZE orders'];
        yield 'analyze alone' => ['ANALYZE'];
        yield 'analyse spelling' => ['ANALYSE orders'];
        yield 'vacuum' => ['VACUUM orders'];
        yield 'vacuum with options' => ['VACUUM (ANALYZE) orders'];
        yield 'validate constraint' => ['ALTER TABLE orders VALIDATE CONSTRAINT fk_orders_customer'];
        yield 'validate constraint only if exists' => ['ALTER TABLE ONLY IF EXISTS public.orders VALIDATE CONSTRAINT fk_orders_customer'];
        yield 'validate constraint if exists only' => ['ALTER TABLE IF EXISTS ONLY public.orders VALIDATE CONSTRAINT fk_orders_customer'];
        yield 'validate constraint on quoted names' => ['ALTER TABLE "Public"."Orders" VALIDATE CONSTRAINT "FK ""x"""'];
        yield 'lowercase and extra whitespace' => ["  create\n\tunique   index concurrently idx on orders (a)  "];
        yield 'trailing semicolons' => ['CREATE INDEX idx ON orders (a);;'];
        yield 'line comment before and after' => ["-- build the index\nCREATE INDEX idx ON orders (a) -- done\n"];
        yield 'block comment, nested' => ['/* a /* nested */ comment */ DROP INDEX idx'];
        yield 'comment between tokens' => ['ALTER TABLE orders /* why */ VALIDATE CONSTRAINT fk'];
        yield 'comment after the terminating semicolon' => ["ANALYZE orders; -- refreshed\n"];
    }

    #[DataProvider('unsafeStatementProvider')]
    public function testRejectsEverythingOutsideTheAllowlist(string $sql): void
    {
        self::assertFalse(DependencySafeStatement::isSafe($sql));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeStatementProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \n "];
        yield 'comment only' => ['-- nothing'];
        yield 'drop column' => ['ALTER TABLE orders DROP COLUMN total'];
        yield 'alter column type' => ['ALTER TABLE orders ALTER COLUMN total TYPE bigint'];
        yield 'add constraint' => ['ALTER TABLE orders ADD CONSTRAINT fk FOREIGN KEY (a) REFERENCES b (id) NOT VALID'];
        yield 'validate constraint with a second action' => ['ALTER TABLE orders VALIDATE CONSTRAINT fk, DROP COLUMN total'];
        yield 'validate constraint on several tables' => ['ALTER TABLE orders, items VALIDATE CONSTRAINT fk'];
        yield 'validate constraint with trailing words' => ['ALTER TABLE orders VALIDATE CONSTRAINT fk CASCADE'];
        yield 'alter table without an action' => ['ALTER TABLE orders'];
        yield 'alter index' => ['ALTER INDEX idx RENAME TO idx2'];
        yield 'drop table' => ['DROP TABLE orders'];
        yield 'drop materialized view' => ['DROP MATERIALIZED VIEW order_totals'];
        yield 'create table' => ['CREATE TABLE t (id int)'];
        yield 'create index lookalike' => ['CREATE INDEXES idx ON orders (a)'];
        yield 'index word not at the start' => ['SELECT 1 /* CREATE INDEX */'];
        yield 'vacuum lookalike' => ['VACUUMING orders'];
        yield 'insert' => ['INSERT INTO orders (id) VALUES (1)'];
        yield 'two statements' => ['CREATE INDEX idx ON orders (a); DROP TABLE orders'];
        yield 'two safe statements' => ['CREATE INDEX idx ON orders (a); ANALYZE orders'];
        yield 'leading semicolon' => [';CREATE INDEX idx ON orders (a)'];
        yield 'statement hidden after a comment' => ["CREATE INDEX idx ON orders (a); -- ok\nDROP TABLE orders"];
        yield 'statement after a quoted name' => ['CREATE INDEX "a" ON orders (a); DROP TABLE orders'];
        yield 'unterminated block comment' => ['CREATE INDEX idx ON orders (a) /* oops'];
        yield 'unterminated string' => ["CREATE INDEX idx ON orders (a) WHERE b = 'oops"];
        yield 'unterminated quoted identifier' => ['CREATE INDEX "idx ON orders (a)'];
        yield 'backslash inside a string' => ["CREATE INDEX idx ON orders (a) WHERE b = '\\'; DROP TABLE orders; --'"];
        yield 'escape string with a backslash' => ["CREATE INDEX idx ON orders (a) WHERE b = E'\\''"];
        yield 'dollar quoting' => ['CREATE INDEX idx ON orders (a) WHERE b = $$x; DROP TABLE orders$$'];
        yield 'tagged dollar quoting' => ['CREATE INDEX idx ON orders (a) WHERE b = $tag$x$tag$'];
        yield 'command wrapped in a comment trick' => ['/* CREATE INDEX */ DROP TABLE orders'];
    }

    public function testAreAllSafeRequiresEveryStatement(): void
    {
        self::assertTrue(DependencySafeStatement::areAllSafe(['ANALYZE orders', 'DROP INDEX idx']));
        self::assertFalse(DependencySafeStatement::areAllSafe(['ANALYZE orders', 'DROP TABLE orders']));
    }

    public function testNoStatementsAreVacuouslySafe(): void
    {
        self::assertTrue(DependencySafeStatement::areAllSafe([]));
    }
}
