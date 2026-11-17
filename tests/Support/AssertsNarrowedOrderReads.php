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
    /** @var list<string> */
    private const ORDER_TABLES = ['orders', 'order_details', 'order_transactions', 'refund_requests', 'refund_transactions'];

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

    /**
     * Whether a statement selects from a table of orders, not counting the tables the narrowing condition itself reads, and not the rows that hang off an order that a screen has
     * already read, which are loaded by the key of that order (`where order_details.order_id in (102, 103)`): they are as narrow as the orders they come from.
     */
    protected function readsAnOrderTable(string $statement): bool
    {
        if (!str_starts_with(strtolower(ltrim($statement)), 'select')) {
            return false;
        }

        if (preg_match('/from\s+[`"]?(?:order_details|order_transactions|refund_transactions)[`"]?\s+where\s+[`"]?(?:order_details|order_transactions|refund_transactions)[`"]?\.[`"]?order_id[`"]?\s+(?:in\s*\(|=\s*\?)/i', $statement) === 1) {
            return false;
        }

        $withoutTheCondition = (string) preg_replace('/(?:from|join)\s+[`"]?(?:order_details|products)[`"]?\s+as\s+[`"]?(?:visible_lines|foreign_lines|home_products|refund_lines|refund_products)[`"]?/i', '', $statement);

        return preg_match('/(?:from|join)\s+[`"]?(?:' . implode('|', self::ORDER_TABLES) . ')[`"]?(?=[\s,)]|$)/i', $withoutTheCondition) === 1;
    }

    protected function isNarrowed(string $statement): bool
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
        $this->assertSame([], array_values(array_filter($statements, fn (string $statement): bool => $this->isNarrowed($statement))), $label . ': nothing is narrowed for who is not restricted');
    }
}
