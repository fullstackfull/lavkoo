<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\LegacyColumnWidener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The part of the column widener that needs no database: the DEFAULT a column keeps, as MySQL and MariaDB each report it. */
class LegacyColumnWidenerTest extends TestCase
{
    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** @return iterable<string, array{?string, bool, bool, ?string, ?string}> raw default, nullable, MariaDB, clause, refusal */
    public static function defaults(): iterable
    {
        yield 'no default, nullable' => [null, true, false, 'DEFAULT NULL', null];
        yield 'no default, not nullable' => [null, false, false, null, null];
        yield 'no default, nullable, MariaDB' => [null, true, true, 'DEFAULT NULL', null];

        yield 'MySQL: a word' => ['pending', false, false, "DEFAULT 'pending'", null];
        yield 'MySQL: a quote inside it' => ["O'Neil", false, false, "DEFAULT 'O''Neil'", null];
        yield 'MySQL: empty text' => ['', false, false, "DEFAULT ''", null];
        yield 'MySQL: a number kept as text' => ['0', false, false, "DEFAULT '0'", null];

        yield 'MariaDB: a quoted word' => ["'pending'", false, true, "DEFAULT 'pending'", null];
        yield 'MariaDB: a quoted word with spaces around it' => ["  'pending'  ", false, true, "DEFAULT 'pending'", null];
        yield 'MariaDB: empty text' => ["''", false, true, "DEFAULT ''", null];
        yield 'MariaDB: a doubled quote' => ["'it''s'", false, true, "DEFAULT 'it''s'", null];
        yield 'MariaDB: a backslash-escaped quote' => ["'a\\'b'", false, true, "DEFAULT 'a\\'b'", null];
        yield 'MariaDB: the word NULL' => ['NULL', true, true, 'DEFAULT NULL', null];
        yield 'MariaDB: the word null in lower case' => ['null', true, true, 'DEFAULT NULL', null];
        yield 'MariaDB: a whole number' => ['12', false, true, 'DEFAULT 12', null];
        yield 'MariaDB: a negative decimal' => ['-1.5', false, true, 'DEFAULT -1.5', null];

        yield 'MariaDB: current_timestamp()' => ['current_timestamp()', false, true, null, 'its default (current_timestamp()) is an expression'];
        yield 'MariaDB: a function call' => ["concat('a','b')", false, true, null, "its default (concat('a','b')) is an expression"];
        yield 'MariaDB: a literal followed by more' => ["'a' + 1", false, true, null, "its default ('a' + 1) is an expression"];
        yield 'MariaDB: two literals' => ["'a' 'b'", false, true, null, "its default ('a' 'b') is an expression"];
        yield 'MariaDB: a literal that never ends' => ["'unterminated", false, true, null, "its default ('unterminated) is an expression"];
    }

    #[DataProvider('defaults')]
    public function test_the_default_clause_says_what_the_column_says_now(?string $raw, bool $nullable, bool $mariaDb, ?string $clause, ?string $refusal): void
    {
        $this->assertSame([$clause, $refusal], LegacyColumnWidener::defaultClause($raw, $nullable, $mariaDb, self::quote(...)));
    }
}
