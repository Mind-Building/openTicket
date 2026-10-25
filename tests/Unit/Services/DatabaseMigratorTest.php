<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DatabaseMigrator;
use PHPUnit\Framework\TestCase;

/**
 * Splitting migration files into statements PDO can execute, incl. DELIMITER blocks
 * (database/migrate_wheelchair.sql uses a stored procedure).
 */
class DatabaseMigratorTest extends TestCase
{
    public function testSimpleStatementsAndComments(): void
    {
        $sql = "-- comment\nALTER TABLE a ADD COLUMN x int;\n\n# other comment\nINSERT INTO b VALUES (1);\n";
        self::assertSame(['ALTER TABLE a ADD COLUMN x int', 'INSERT INTO b VALUES (1)'], DatabaseMigrator::splitStatements($sql));
    }

    public function testDelimiterBlockStaysOneStatement(): void
    {
        $sql = "DROP PROCEDURE IF EXISTS p;\n\nDELIMITER \$\$\nCREATE PROCEDURE p()\nBEGIN\n    SELECT 1;\n    SELECT 2;\nEND\$\$\nDELIMITER ;\n\nCALL p();\nDROP PROCEDURE p;\n";
        $statements = DatabaseMigrator::splitStatements($sql);

        self::assertCount(4, $statements);
        self::assertSame('DROP PROCEDURE IF EXISTS p', $statements[0]);
        self::assertStringStartsWith('CREATE PROCEDURE p()', $statements[1]);
        self::assertStringContainsString('SELECT 2;', $statements[1]);
        self::assertStringEndsWith('END', $statements[1]);
        self::assertSame('CALL p()', $statements[2]);
    }

    public function testAlterClausesSplitAtTopLevelCommasOnly(): void
    {
        $clauses = DatabaseMigrator::alterClauses(
            "\n    ADD COLUMN IF NOT EXISTS `a` varchar(100) DEFAULT 'x,y' AFTER `b`,\n    ADD INDEX IF NOT EXISTS `idx` (`event_id`, `a`)"
        );
        self::assertSame([
            "ADD COLUMN IF NOT EXISTS `a` varchar(100) DEFAULT 'x,y' AFTER `b`",
            'ADD INDEX IF NOT EXISTS `idx` (`event_id`, `a`)',
        ], $clauses);
    }

    public function testEveryListedMigrationFileExists(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn('ticket_test');
        $pdo->method('query')->willReturn($stmt);

        $migrator = new DatabaseMigrator($pdo);
        foreach ($migrator->migrations() as $migration) {
            self::assertFileExists(dirname(__DIR__, 3) . '/database/' . $migration['file'], $migration['id']);
        }
    }
}
