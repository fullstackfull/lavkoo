<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeSites;
use Tests\Support\ProductCodeLookupScanner;
use Tests\TestCase;

/**
 * Every place the platform looks a product up, filters or validates it by its SKU or barcode is either routed through ProductCodeScope or listed
 * here with the survey site it belongs to and why it stays as it is. A new lookup added without its country in mind fails this test, which is what
 * keeps a code that names one product in one country from silently naming two in another.
 *
 * The table is exact: a file whose count differs, or that no longer holds a lookup, fails too, so it cannot rot into a list nobody reads.
 * Lookups the scanner reads as being about another record (a colour, a currency, a coupon) are not product lookups and need no entry; a lookup whose
 * subject it cannot read is listed like a product lookup.
 */
class ProductCodeLookupGuardTest extends TestCase
{
    /**
     * file => count of lookups the scanner finds there, the survey sites they are, whether the file routes through ProductCodeScope, and why the
     * lookup itself stays.
     *
     * @var array<string, array{count: int, sites: list<string>, routed: bool, reason: string}>
     */
    private const LOOKUPS = [
        'app/Http/Controllers/Admin/Fulfillment/TorodMatchingController.php' => [
            'count' => 2, 'sites' => ['E2'], 'routed' => true,
            'reason' => 'The matching picker searches by name, SKU or barcode; ProductCodeScope::restrictToCountry keeps it to the provider\'s country.',
        ],
        'app/Http/Controllers/Admin/OperationalCosts/ProductCostController.php' => [
            'count' => 2, 'sites' => ['F1'], 'routed' => false,
            'reason' => 'The cost-layer picker offers every country and shows none: not covered by slice 1 (the layer is written by the picked product id).',
        ],
        'app/Http/Controllers/Admin/Product/ProductController.php' => [
            'count' => 2, 'sites' => ['C5', 'C6'], 'routed' => false,
            'reason' => 'The reviews filter and the promotion pickers search products by code through ProductRepository::getListWhere: no country to narrow to, the rows show none (slice 2).',
        ],
        'app/Http/Controllers/Admin/SalesChannels/Support/ProductDirectory.php' => [
            'count' => 2, 'sites' => ['D7'], 'routed' => false,
            'reason' => 'The listing-link picker shows no country; the link is saved by the picked id (slice 2).',
        ],
        'app/Http/Controllers/Vendor/Product/ProductController.php' => [
            'count' => 1, 'sites' => ['C5'], 'routed' => false,
            'reason' => 'The vendor product search filters one seller\'s own products by code; seller products are all in the table default country until sellers have markets.',
        ],
        'app/Http/Requests/API/v3/ProductAddRequest.php' => [
            'count' => 1, 'sites' => [], 'routed' => false,
            'reason' => 'Dead code: imported by the v3 seller ProductController and never type-hinted. The live rule is the inline one of add_new (A7), which goes through ProductCodeScope.',
        ],
        'app/Http/Requests/API/v3/ProductUpdateRequest.php' => [
            'count' => 1, 'sites' => [], 'routed' => false,
            'reason' => 'Dead code, as its add twin: the live rule is the inline one of updateProduct (A8), which goes through ProductCodeScope.',
        ],
        'app/Repositories/ProductRepository.php' => [
            'count' => 12, 'sites' => ['C2', 'C3', 'C4', 'C5', 'C6', 'C7'], 'routed' => true,
            'reason' => 'The list queries of the panels: LIKE filters of lists a person reads and pickers. The order-edit picker is narrowed by the storefront_country filter through ProductCodeScope::restrictToCountry; the rest are lists (id-keyed) or pickers that slice 2 narrows.',
        ],
        'app/Repositories/RestockProductRepository.php' => [
            'count' => 1, 'sites' => ['C8'], 'routed' => false,
            'reason' => 'The restock list filters by the product\'s code (a list a person reads; every action is keyed by the row id).',
        ],
        'app/Services/Fulfillment/ProviderMappingService.php' => [
            'count' => 2, 'sites' => ['E1'], 'routed' => true,
            'reason' => 'The Torod suggestions match the warehouse item\'s SKU and barcode; the query is restricted to the provider\'s country by ProductCodeScope::restrictToCountry.',
        ],
        'app/Services/Fulfillment/Readiness/Compatibility/CheckoutSection.php' => [
            'count' => 1, 'sites' => [], 'routed' => false,
            'reason' => 'Not a product: the `code` of a currency row, read through a diagnostics probe that names its table in an argument.',
        ],
        'app/Services/OrderEditService.php' => [
            'count' => 1, 'sites' => [], 'routed' => false,
            'reason' => 'Not a product: the `code` of a colour, found in a collection of colours.',
        ],
        'app/Services/SalesChannels/Inventory/LocationStockPage.php' => [
            'count' => 1, 'sites' => ['D8'], 'routed' => false,
            'reason' => 'The stock page filter (code LIKE): a list a person reads.',
        ],
        'app/Services/SalesChannels/Sync/ProductMatcher.php' => [
            'count' => 3, 'sites' => ['D1'], 'routed' => true,
            'reason' => 'The channel listing proposal matches SKU, barcode and variant SKU; ProductCodeScope::restrictToCountry keeps it to the account\'s country when that is known.',
        ],
        'app/Services/SellerCenter/Lists/InventoryList.php' => [
            'count' => 1, 'sites' => ['C10'], 'routed' => false,
            'reason' => 'A list a person reads (code LIKE); every action on a row is keyed by its id.',
        ],
        'app/Services/SellerCenter/Lists/ProductList.php' => [
            'count' => 1, 'sites' => ['C9'], 'routed' => false,
            'reason' => 'A list a person reads (code LIKE); every action on a row is keyed by its id.',
        ],
        'app/Services/SellerCenter/Search.php' => [
            'count' => 1, 'sites' => ['C11'], 'routed' => false,
            'reason' => 'The seller centre global search (code LIKE): a list a person reads.',
        ],
        'app/Services/SellerIntelligence/Producers/CatalogIntegrityProducer.php' => [
            'count' => 1, 'sites' => ['G8'], 'routed' => false,
            'reason' => 'A diagnostic that flags the same barcode inside one seller: a false positive for twins once sellers have markets.',
        ],
        'app/Utils/ProductManager.php' => [
            'count' => 1, 'sites' => [], 'routed' => false,
            'reason' => 'Not a product: the `code` of a colour, found in a collection of colours.',
        ],
        'app/Utils/settings.php' => [
            'count' => 1, 'sites' => [], 'routed' => false,
            'reason' => 'Not a product: the `code` of a setting in a collection of currencies and languages.',
        ],
        'app/Http/Controllers/Admin/Settings/LanguageController.php' => [
            'count' => 2, 'sites' => [], 'routed' => false,
            'reason' => 'Not a product: the `code` of a language, found in collections of languages and countries.',
        ],
    ];

    /** The one file that may look a product up by its code without an entry: it is the answer. */
    private const THE_SCOPE = 'app/Services/Catalog/ProductCodeScope.php';

    /** @return list<array{file: string, line: int, kind: string, subject: string, text: string}> */
    private function lookupsOf(string $source, string $file = 'app/Probe.php'): array
    {
        return array_values(array_filter(
            (new ProductCodeLookupScanner())->scanSource(source: $source, file: $file),
            static fn (array $lookup): bool => $lookup['subject'] !== ProductCodeLookupScanner::OTHER,
        ));
    }

    /**
     * What is wrong between what the scanner found and what the table says.
     *
     * @param list<array{file: string, line: int, kind: string, subject: string, text: string}> $found
     * @param array<string, array{count: int, sites: list<string>, routed: bool, reason: string}> $table
     * @param callable(string): string $contentsOf
     * @return list<string>
     */
    public static function problemsOf(array $found, array $table, array $siteIds, callable $contentsOf): array
    {
        $problems = [];
        $counted = [];
        foreach ($found as $lookup) {
            if ($lookup['file'] !== self::THE_SCOPE) {
                $counted[$lookup['file']][] = $lookup;
            }
        }

        foreach ($counted as $file => $lookups) {
            $listed = $table[$file] ?? null;
            if ($listed === null) {
                $lines = implode(', ', array_column($lookups, 'line'));
                $problems[] = "{$file} looks a record up by code or barcode (line {$lines}, {$lookups[0]['subject']}) and is neither routed through ProductCodeScope nor listed in the table of this test.";
            } elseif ($listed['count'] !== count($lookups)) {
                $problems[] = sprintf('%s holds %d such lookups and the table says %d: list the new one with its survey site and a reason, or route it through ProductCodeScope.', $file, count($lookups), $listed['count']);
            }
        }
        foreach ($table as $file => $listed) {
            if (!isset($counted[$file])) {
                $problems[] = "{$file} is listed but no longer holds a lookup: remove its entry.";

                continue;
            }
            if (trim($listed['reason']) === '') {
                $problems[] = "{$file} is listed without a reason.";
            }
            if ($listed['routed'] && !str_contains($contentsOf($file), 'ProductCodeScope')) {
                $problems[] = "{$file} is listed as routed through ProductCodeScope but does not use it.";
            }
            foreach ($listed['sites'] as $site) {
                if (!in_array($site, $siteIds, true)) {
                    $problems[] = "{$file} names the survey site {$site}, which ProductCodeSites does not know.";
                }
            }
        }

        return $problems;
    }

    // ---- the real tree ---------------------------------------------------------------------------------------------------------

    public function test_every_lookup_of_a_product_by_code_or_barcode_is_routed_or_listed(): void
    {
        $found = array_values(array_filter(
            (new ProductCodeLookupScanner())->scan(roots: [app_path(), base_path('Modules')], base: base_path()),
            static fn (array $lookup): bool => $lookup['subject'] !== ProductCodeLookupScanner::OTHER,
        ));
        $siteIds = array_column(ProductCodeSites::all(), 'id');

        $problems = self::problemsOf($found, self::LOOKUPS, $siteIds, static fn (string $file): string => (string) file_get_contents(base_path($file)));

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_every_entry_names_a_site_the_registry_knows_or_says_it_is_not_a_product_or_dead_code(): void
    {
        $siteIds = array_column(ProductCodeSites::all(), 'id');

        foreach (self::LOOKUPS as $file => $entry) {
            $this->assertFileExists(base_path($file));
            foreach ($entry['sites'] as $site) {
                $this->assertContains($site, $siteIds, $file);
            }
            $this->assertTrue($entry['sites'] !== [] || str_starts_with($entry['reason'], 'Not a product') || str_starts_with($entry['reason'], 'Dead code'), "{$file} has no survey site and does not say it is not a product or dead code");
        }
    }

    public function test_the_registry_does_not_call_a_site_covered_while_its_lookup_is_still_unrouted(): void
    {
        $covered = array_column(array_filter(ProductCodeSites::all(), static fn (array $site): bool => $site['status'] === ProductCodeSites::COVERED), 'id');

        foreach (self::LOOKUPS as $file => $entry) {
            if (!$entry['routed']) {
                $this->assertSame([], array_values(array_intersect($entry['sites'], $covered)), "{$file} is not routed through ProductCodeScope, so its sites are not covered");
            }
        }
    }

    // ---- the scanner and the guard are not blind --------------------------------------------------------------------------------

    public function test_a_product_lookup_is_found_whichever_way_it_is_written(): void
    {
        $sources = [
            'the model' => '<?php class A { function f($c) { return Product::where("code", $c)->first(); } }',
            'the model, qualified' => '<?php class A { function f($c) { return \App\Models\Product::whereIn(\'barcode\', $c)->get(); } }',
            'an array of columns' => '<?php class A { function f($c) { return Product::where([\'added_by\' => \'seller\', \'code\' => $c])->first(); } }',
            'the table' => '<?php class A { function f($c) { return DB::table(\'products\')->where(\'code\', $c)->exists(); } }',
            'a closure inside the model query' => '<?php class A { function f($c) { return Product::query()->where(function ($query) use ($c) { $query->where(\'code\', $c)->orWhere(\'barcode\', $c); })->get(); } }',
            'a closure inside a block inside a closure' => '<?php class A { function f($c) { return Product::when($c, function ($query) use ($c) { if ($c) { $query->orWhere(\'code\', \'like\', $c); } })->get(); } }',
            'an arrow function' => '<?php class A { function f($c) { return Product::where(fn ($query) => $query->where(\'code\', $c))->get(); } }',
            'the repository' => '<?php class A { function f($c) { return $this->productRepo->getFirstWhere(params: [\'code\' => $c]); } }',
            'the repository property' => '<?php class A { function f($c) { return $this->product->where(\'code\', $c)->first(); } }',
            'a unique rule string' => '<?php class A { function f() { return [\'code\' => \'required|unique:products\']; } }',
            'a unique rule on a column' => '<?php class A { function f() { return [\'code\' => \'required|unique:products,code,5\']; } }',
            'a unique rule object' => '<?php class A { function f() { return Rule::unique(\'products\', \'code\'); } }',
            'a nullsafe chain' => '<?php class A { function f($c) { return Product::query()?->where(\'code\', $c)->first(); } }',
        ];

        foreach ($sources as $name => $source) {
            $found = $this->lookupsOf($source);

            $this->assertNotEmpty($found, "the scanner missed: {$name}");
            $this->assertSame(ProductCodeLookupScanner::PRODUCT, $found[0]['subject'], "read wrongly: {$name}");
        }
    }

    public function test_a_lookup_on_another_record_is_not_a_product_lookup(): void
    {
        $sources = [
            'a colour' => '<?php class A { function f($c) { return Color::where("code", $c)->first(); } }',
            'a currency in a condition' => '<?php class A { function f($c) { if ($c && Currency::where([\'code\' => $c, \'status\' => 1])->first()) { return 1; } } }',
            'a coupon' => '<?php class A { function f($c) { return \App\Models\Coupon::where([\'code\' => $c])->first(); } }',
            'another table' => '<?php class A { function f($c) { return DB::table(\'analytics_campaigns\')->where(\'code\', $c)->first(); } }',
            'a cast around a model' => '<?php class A { function f($c) { $rate = (float) (Currency::where(\'code\', $c)->value(\'rate\') ?? 0); } }',
            'the colour repository' => '<?php class A { function f($c) { return $this->colorRepo->getFirstWhere([\'code\' => $c]); } }',
        ];

        foreach ($sources as $name => $source) {
            $all = (new ProductCodeLookupScanner())->scanSource(source: $source, file: 'app/Probe.php');

            $this->assertNotEmpty($all, "the scanner missed: {$name}");
            $this->assertSame(ProductCodeLookupScanner::OTHER, $all[0]['subject'], "read wrongly: {$name}");
        }
    }

    public function test_a_lookup_whose_subject_cannot_be_read_is_not_skipped(): void
    {
        $found = $this->lookupsOf('<?php class A { function f($query, $c) { return $query->where("code", $c)->first(); } }');

        $this->assertSame([ProductCodeLookupScanner::UNKNOWN], array_column($found, 'subject'));
    }

    public function test_a_comment_a_string_and_a_name_that_only_mention_a_lookup_are_not_lookups(): void
    {
        $source = '<?php class A {
            // Product::where("code", $c)->first()
            /** unique:products */
            function f($c) {
                $text = "Product::where(\'code\', 1)";
                $this->where_code = $c;
                return Product::where("name", $c)->whereIn("id", [1])->first();
            }
        }';

        $this->assertSame([], $this->lookupsOf($source));
    }

    public function test_a_new_unlisted_lookup_fails_the_guard(): void
    {
        $found = $this->lookupsOf('<?php class A { function f($c) { return Product::where("code", $c)->first(); } }', 'app/Services/Brand/New.php');

        $problems = self::problemsOf($found, [], ['C1'], static fn (): string => '');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('app/Services/Brand/New.php', $problems[0]);
        $this->assertStringContainsString('neither routed through ProductCodeScope nor listed', $problems[0]);
    }

    public function test_a_second_lookup_in_a_listed_file_fails_the_guard(): void
    {
        $file = 'app/Services/SellerCenter/Search.php';
        $one = $this->lookupsOf('<?php class A { function f($c) { return Product::where("code", $c)->first(); } }', $file);
        $two = $this->lookupsOf('<?php class A { function f($c) { return Product::where("code", $c)->first() ?? Product::where("barcode", $c)->first(); } }', $file);

        $this->assertSame([], self::problemsOf($one, [$file => self::LOOKUPS[$file]], ['C11'], static fn (): string => ''));
        $problems = self::problemsOf($two, [$file => self::LOOKUPS[$file]], ['C11'], static fn (): string => '');
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('holds 2 such lookups and the table says 1', $problems[0]);
    }

    public function test_an_entry_that_no_longer_has_a_lookup_fails_the_guard(): void
    {
        $problems = self::problemsOf([], ['app/Services/Gone.php' => ['count' => 1, 'sites' => ['C1'], 'routed' => false, 'reason' => 'x']], ['C1'], static fn (): string => '');

        $this->assertSame(['app/Services/Gone.php is listed but no longer holds a lookup: remove its entry.'], $problems);
    }

    public function test_a_file_listed_as_routed_must_use_the_scope(): void
    {
        $file = 'app/Services/Routed.php';
        $found = $this->lookupsOf('<?php class A { function f($c) { return Product::where("code", $c)->first(); } }', $file);
        $table = [$file => ['count' => 1, 'sites' => ['C1'], 'routed' => true, 'reason' => 'x']];

        $this->assertCount(1, self::problemsOf($found, $table, ['C1'], static fn (): string => '<?php // nothing here'));
        $this->assertSame([], self::problemsOf($found, $table, ['C1'], static fn (): string => '<?php use App\Services\Catalog\ProductCodeScope;'));
    }

    public function test_an_entry_without_a_reason_or_with_an_unknown_site_fails_the_guard(): void
    {
        $file = 'app/Services/Odd.php';
        $found = $this->lookupsOf('<?php class A { function f($c) { return Product::where("code", $c)->first(); } }', $file);

        $this->assertCount(1, self::problemsOf($found, [$file => ['count' => 1, 'sites' => ['C1'], 'routed' => false, 'reason' => ' ']], ['C1'], static fn (): string => ''));
        $this->assertCount(1, self::problemsOf($found, [$file => ['count' => 1, 'sites' => ['Z9'], 'routed' => false, 'reason' => 'x']], ['C1'], static fn (): string => ''));
    }

    public function test_the_scope_itself_is_the_one_file_that_needs_no_entry(): void
    {
        $found = $this->lookupsOf('<?php class A { function f($c) { return Product::where("code", $c)->first(); } }', 'app/Services/Catalog/ProductCodeScope.php');

        $this->assertSame([], self::problemsOf($found, [], [], static fn (): string => ''));
    }
}
