<?php

namespace Tests\Feature\Reports;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The money report screens paged through their rows in an order the database was free to change.
 *
 * Twelve of them paginate. Each ordered by something that is not unique — `created_at`, `current_stock`,
 * `wish_list_count`, or in two cases nothing at all — and then asked for one page. None of those columns
 * is unique in this application: an order, a transaction and an expense are all stamped with `now()`, and
 * `current_stock` is 0 for most of a catalogue, so a run of rows the sort cannot tell apart is the normal
 * case, not the exception. With ties the engine returns them in whatever order suits it, and it may
 * choose differently for page 1 than for page 2, because each page is a separate query with its own
 * OFFSET.
 *
 * The consequence is not cosmetic: a row can appear on two pages and another on none, so an admin reading
 * a transaction report can be shown the same money twice, or never shown it at all. The row count still
 * adds up, which is why it stays quiet.
 *
 * This is not a theory. Two runs of the same suite over the same rows on two versions of MariaDB
 * disagreed about which of two orders stamped in the same second came first — one said order 102, the
 * other 103 — which is what sent anyone looking.
 *
 * The fix is a tie-break on a unique column, normally the primary key. The intended order is untouched —
 * newest first, or by stock, exactly as before — and the rows that tie now have a total order the engine
 * cannot reinterpret between pages.
 *
 * ## The two halves, and what each is for
 *
 * The behavioural half shows the risk is real rather than theoretical, on real rows that share a
 * timestamp. It builds its own query, so it proves the ORDERING and nothing about the screens.
 *
 * The source half is what pins the SCREENS. For every `paginate(` in the report controllers and the
 * seller report service it finds the order that reaches it — on the statement itself, or in the helper or
 * service method the statement calls — and fails when that order carries no unique column. Reverting any
 * one of the twelve fixes turns it red, and a thirteenth report screen written the old way turns it red
 * the day it is added.
 *
 * It resolves ONE level: a `self::helper(...)` or `$this->reports->method(...)` in the same file or in the
 * named service. A query assembled any other way is REPORTED rather than passed, because an order this
 * guard cannot see is not an order it can vouch for — the same rule tests/Support/PlatformWriteScanner.php
 * follows for a table name it cannot resolve.
 */
class StableReportPaginationTest extends TestCase
{
    /** Every file in this application that paginates a report. */
    private const REPORT_SOURCES = [
        'app/Http/Controllers/Admin/OrderReportController.php',
        'app/Http/Controllers/Admin/ProductReportController.php',
        'app/Http/Controllers/Admin/ProductStockReportController.php',
        'app/Http/Controllers/Admin/ProductWishlistReportController.php',
        'app/Http/Controllers/Admin/TransactionReportController.php',
        'app/Http/Controllers/Admin/VendorProductSaleReportController.php',
        'app/Http/Controllers/Vendor/OrderReportController.php',
        'app/Http/Controllers/Vendor/ProductReportController.php',
        'app/Http/Controllers/Vendor/TransactionReportController.php',
    ];

    /** Where a controller's query may come from instead of being built in front of the paginate call. */
    private const QUERY_SERVICES = ['app/Services/Reports/SellerReportService.php'];

    /** How many paginated report screens there are. A screen added or removed should be a deliberate change to this number. */
    private const PAGINATED_SCREENS = 12;

    private const TABLE = 'zz_report_pagination_rows';

    private const ROWS = 25;

    private const PER_PAGE = 10;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->timestamp('created_at')->nullable();
        });

        $sameSecond = now()->format('Y-m-d H:i:s');
        DB::table(self::TABLE)->insert(array_map(
            static fn (int $row): array => ['created_at' => $sameSecond],
            range(1, self::ROWS),
        ));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    /**
     * The ids a reader is shown when they page through the whole report, in the order shown.
     *
     * @return list<int>
     */
    private function everyRowAReaderIsShown(bool $withTheTieBreak): array
    {
        $shown = [];

        for ($page = 0; $page * self::PER_PAGE < self::ROWS; $page++) {
            $query = DB::table(self::TABLE)->orderByDesc('created_at');

            if ($withTheTieBreak) {
                $query->orderByDesc('id');
            }

            $shown = array_merge($shown, $query->offset($page * self::PER_PAGE)->limit(self::PER_PAGE)->pluck('id')->all());
        }

        return array_map('intval', $shown);
    }

    public function test_with_the_tie_break_every_row_is_shown_exactly_once_and_newest_first(): void
    {
        $shown = $this->everyRowAReaderIsShown(withTheTieBreak: true);

        $this->assertCount(self::ROWS, $shown);
        $this->assertSame(range(self::ROWS, 1), $shown, 'no row missing, none shown twice, newest first');
    }

    public function test_with_the_tie_break_a_refreshed_report_shows_the_same_thing(): void
    {
        $this->assertSame(
            $this->everyRowAReaderIsShown(withTheTieBreak: true),
            $this->everyRowAReaderIsShown(withTheTieBreak: true),
        );
    }

    /**
     * Without the tie-break nothing guarantees a row is shown once. This does not demand that it goes
     * wrong — on a given engine, version and plan it may not — but it records what the order rests on
     * when it does not: luck. What it does assert is the thing that is always true either way, that the
     * COUNT of rows read is right, which is exactly why the defect is quiet: a reader who sees the right
     * number of rows has still not been shown the right rows.
     */
    public function test_without_the_tie_break_the_count_still_adds_up_which_is_why_it_stays_quiet(): void
    {
        $shown = $this->everyRowAReaderIsShown(withTheTieBreak: false);

        $this->assertCount(self::ROWS, $shown);

        $missing = array_values(array_diff(range(1, self::ROWS), $shown));
        $repeated = array_values(array_diff_assoc($shown, array_unique($shown)));

        $this->assertSame(
            count($missing),
            count($repeated),
            'a row shown twice is a row not shown, so the two counts move together: ' . count($missing) . ' missing, ' . count($repeated) . ' repeated',
        );
    }

    public function test_every_paginated_report_screen_orders_by_something_unique(): void
    {
        $offences = [];
        $screens = 0;

        foreach (self::REPORT_SOURCES as $relative) {
            $path = base_path($relative);

            if (!is_file($path)) {
                $offences[] = $relative . ': the file this guard reads is not there any more; point the guard at it again';

                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($this->paginatedStatementsOf(source: $source) as $line => $statement) {
                $screens++;
                $where = $this->whereTheUniqueOrderIs(statement: $statement, source: $source);

                if ($where === null) {
                    $offences[] = $relative . ':' . $line . '  ' . trim(preg_replace('/\s+/', ' ', $statement));
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "a report screen pages through its rows with no unique column in the order, so the database may show a row on two pages and another on none. Add a tie-break, for example ->latest('created_at')->latest('id').",
        );
        $this->assertSame(
            self::PAGINATED_SCREENS,
            $screens,
            'the number of paginated report screens has changed; check the new one carries a tie-break and update this number deliberately',
        );
    }

    public function test_the_guard_would_notice_if_a_tie_break_were_taken_away(): void
    {
        $withATieBreak = "\$orders = \$orders->latest('created_at')->latest('id')->paginate(10);";
        $withoutOne = "\$orders = \$orders->latest('created_at')->paginate(10);";

        $this->assertNotNull($this->whereTheUniqueOrderIs(statement: $withATieBreak, source: ''), 'the guard does not see a tie-break that is there');
        $this->assertNull($this->whereTheUniqueOrderIs(statement: $withoutOne, source: ''), 'the guard passes a statement with no tie-break, so it guards nothing');
    }

    /**
     * Every statement of a source file that calls paginate(), by the line the call is on. A statement is
     * read backwards to the end of the one before it, so a chain broken across lines is read whole.
     *
     * @return array<int, string>
     */
    private function paginatedStatementsOf(string $source): array
    {
        $statements = [];
        $lines = explode("\n", $source);

        foreach ($lines as $index => $line) {
            if (!str_contains($line, '->paginate(')) {
                continue;
            }

            $statement = $line;

            for ($back = $index - 1; $back >= 0; $back--) {
                if (preg_match('/[;{}]\s*$/', $lines[$back])) {
                    break;
                }

                $statement = $lines[$back] . ' ' . $statement;
            }

            $statements[$index + 1] = $statement;
        }

        return $statements;
    }

    /**
     * Where the unique part of this statement's order comes from, or null when this guard cannot find one.
     *
     * It looks on the statement, then in the one helper or service method the statement calls. A query
     * assembled any other way returns null, so it is reported: an order the guard cannot see is not one it
     * can vouch for.
     */
    private function whereTheUniqueOrderIs(string $statement, string $source): ?string
    {
        if ($this->ordersBySomethingUnique(code: $statement, groupedBy: $this->groupKeyOf(code: $statement))) {
            return 'the statement itself';
        }

        foreach ($this->methodsCalledIn(statement: $statement) as $method) {
            foreach (array_merge([$source], $this->serviceSources()) as $haystack) {
                $body = $this->bodyOfMethod(name: $method, source: $haystack);

                if ($body !== null && $this->ordersBySomethingUnique(code: $body, groupedBy: $this->groupKeyOf(code: $body . ' ' . $statement))) {
                    return $method . '()';
                }
            }
        }

        return null;
    }

    /** @return list<string> the helper and service method names a statement calls */
    private function methodsCalledIn(string $statement): array
    {
        preg_match_all('/(?:self::|\$this\s*->\s*[\w]+\s*->\s*|\$this\s*->\s*)(\w+)\s*\(/', $statement, $found);

        return array_values(array_unique(array_diff($found[1], ['paginate', 'appends'])));
    }

    /** The body of a named method of a source file, or null when it has none. */
    private function bodyOfMethod(string $name, string $source): ?string
    {
        if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $source, $start, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $body = substr($source, $start[0][1]);

        return preg_split('/\n    (?:public|protected|private)\s/', $body)[0] ?: null;
    }

    /** @return list<string> */
    private function serviceSources(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $relative): string => is_file(base_path($relative)) ? (string) file_get_contents(base_path($relative)) : '',
            self::QUERY_SERVICES,
        )));
    }

    /** The column a query groups by, if it groups by one: after a GROUP BY, that column is unique per row. */
    private function groupKeyOf(string $code): ?string
    {
        return preg_match('/->\s*groupBy\s*\(\s*[\'"]([\w.]+)[\'"]\s*\)/', $code, $found) ? $found[1] : null;
    }

    /** Whether an order rests on a column the database guarantees is unique for a row of this query. */
    private function ordersBySomethingUnique(string $code, ?string $groupedBy): bool
    {
        $unique = ['id'];

        if ($groupedBy !== null) {
            $unique[] = $groupedBy;
        }

        foreach ($unique as $column) {
            $bare = preg_quote(str_contains($column, '.') ? explode('.', $column)[1] : $column, '/');
            $pattern = '/->\s*(?:latest|oldest)\s*\(\s*[\'"](?:[\w]+\.)?' . $bare . '[\'"]\s*\)'
                . '|->\s*orderBy(?:Desc)?\s*\(\s*[\'"](?:[\w]+\.)?' . $bare . '[\'"]/';

            if (preg_match($pattern, $code)) {
                return true;
            }
        }

        return false;
    }
}
