<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reads the statements a request ran, and says which of them read a table that holds orders, their lines, their money records or refunds (GCC-14c, slice 3), and whether such a
 * statement carries the condition that narrows it to the countries of an employee (the aliases `visible_lines` and `refund_lines` of App\Services\Staff\StaffOrderVisibility).
 * It is how a test proves that a screen reads no order of another country, whatever the screen's own code does, without knowing the screen.
 */
trait AssertsNarrowedOrderReads
{
    /**
     * Runs what a request does and returns the statements it ran.
     *
     * @return list<string>
     */
    protected function statementsOf(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    /** @var array<string, string> the table, and the column of it that the narrowing condition of the table is correlated with */
    private const NARROWED_BY = [
        'orders' => 'id',
        'order_details' => 'order_id',
        'order_transactions' => 'order_id',
        'refund_requests' => 'order_details_id',
        'refund_transactions' => 'order_details_id',
    ];

    /**
     * The tables of orders a statement selects from, not counting the tables the narrowing condition itself reads, and not the rows that hang off an order that a screen has already
     * read, which are loaded by the key of that order (`where order_details.order_id in (102, 103)`), nor the order or the line of a row that a screen has already read, which is
     * loaded by the ids of those rows (`where orders.id in (101, 102)`): they are as narrow as the rows they come from.
     *
     * @return list<string>
     */
    protected function tablesRead(string $statement): array
    {
        if (!str_starts_with(strtolower(ltrim($statement)), 'select')) {
            return [];
        }

        $byTheKeyOfARowAlreadyRead = '/from\s+[`"]?(?:order_details|order_transactions|refund_transactions)[`"]?\s+where\s+[`"]?(?:order_details|order_transactions|refund_transactions)[`"]?\.[`"]?order_id[`"]?\s+(?:in\s*\(|=\s*\?)/i';
        $theOrderOrLineOfARowAlreadyRead = '/from\s+[`"]?(orders|order_details)[`"]?\s+where\s+[`"]?\1[`"]?\.[`"]?id[`"]?\s+in\s*\(\s*\d+(?:\s*,\s*\d+)*\s*\)/i';
        if (preg_match($byTheKeyOfARowAlreadyRead, $statement) === 1 || preg_match($theOrderOrLineOfARowAlreadyRead, $statement) === 1) {
            return [];
        }

        $withoutTheCondition = (string) preg_replace('/(?:from|join)\s+[`"]?(?:order_details|products)[`"]?\s+as\s+[`"]?(?:visible_lines|foreign_lines|home_products|refund_lines|refund_products)[`"]?/i', '', $statement);

        $read = array_filter(array_keys(self::NARROWED_BY), static fn (string $table): bool => preg_match('/(?:from|join)\s+[`"]?' . $table . '[`"]?(?=[\s,)]|$)/i', $withoutTheCondition) === 1);

        return array_values(array_filter($read, fn (string $table): bool => !$this->hangsOffANarrowedOrder($statement, $table)));
    }

    /** Whether the rows of a table are reached through the order a statement has narrowed (`where orders.id = order_details.order_id`, a sum or a count over the lines of each order). */
    private function hangsOffANarrowedOrder(string $statement, string $table): bool
    {
        if (!in_array($table, ['order_details', 'order_transactions'], true) || !$this->narrows($statement, 'orders')) {
            return false;
        }

        $order = '[`"]?orders[`"]?\.[`"]?id[`"]?';
        $row = '[`"]?' . $table . '[`"]?\.[`"]?order_id[`"]?';

        return preg_match('/' . $order . '\s*=\s*' . $row . '|' . $row . '\s*=\s*' . $order . '/', $statement) === 1;
    }

    protected function readsAnOrderTable(string $statement): bool
    {
        return $this->tablesRead($statement) !== [];
    }

    /** Whether the narrowing condition of a table is in a statement, correlated with the column of that very table (so that narrowing another table of the statement does not count). */
    private function narrows(string $statement, string $table): bool
    {
        $alias = in_array($table, ['refund_requests', 'refund_transactions'], true) ? 'refund_lines' : 'visible_lines';
        $lineColumn = $alias === 'refund_lines' ? 'id' : 'order_id';

        return preg_match('/[`"]?' . $alias . '[`"]?\.[`"]?' . $lineColumn . '[`"]?\s*=\s*[`"]?' . $table . '[`"]?\.[`"]?' . self::NARROWED_BY[$table] . '[`"]?/', $statement) === 1;
    }

    /** Whether every table of orders a statement reads carries its own narrowing condition. */
    protected function isNarrowed(string $statement): bool
    {
        foreach ($this->tablesRead($statement) as $table) {
            if (!$this->narrows($statement, $table)) {
                return false;
            }
        }

        return true;
    }

    /** Whether a statement carries any narrowing condition at all (for who is not restricted there must be none). */
    protected function carriesAnyNarrowing(string $statement): bool
    {
        return str_contains($statement, 'visible_lines') || str_contains($statement, 'refund_lines');
    }

    /**
     * @param list<string> $statements
     * @return list<string> the statements that read a table of orders and are not narrowed
     */
    protected function unnarrowedOrderReads(array $statements): array
    {
        return array_values(array_filter($statements, fn (string $statement): bool => $this->readsAnOrderTable($statement) && !$this->isNarrowed($statement)));
    }

    /**
     * @param list<string> $statements
     * @return list<string> the statements that read a table of orders and are narrowed
     */
    protected function narrowedOrderReads(array $statements): array
    {
        return array_values(array_filter($statements, fn (string $statement): bool => $this->readsAnOrderTable($statement) && $this->isNarrowed($statement)));
    }

    /** @param list<string> $statements */
    protected function assertNoOrderReadEscapes(array $statements, string $label): void
    {
        $this->assertSame([], $this->unnarrowedOrderReads($statements), $label . ': a statement reads a table of orders and is not narrowed to the countries of the employee');
        $this->assertNotSame([], $this->narrowedOrderReads($statements), $label . ': the screen was expected to read orders');
    }

    /** @param list<string> $statements */
    protected function assertNothingIsNarrowed(array $statements, string $label): void
    {
        $this->assertSame([], array_values(array_filter($statements, fn (string $statement): bool => $this->carriesAnyNarrowing($statement))), $label . ': nothing is narrowed for who is not restricted');
    }
}
