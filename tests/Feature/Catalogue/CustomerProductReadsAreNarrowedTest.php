<?php

namespace Tests\Feature\Catalogue;

use Tests\Support\CustomerProductReadScanner;
use Tests\TestCase;

/**
 * B-01: a customer-facing product read that bypasses the storefront narrowing fails here, by the name of its file.
 *
 * The narrowing itself is proved by behaviour next door -- with storefronts on, the Emirati storefront lists only Emirati products
 * (`StorefrontCatalogTest`, `StorefrontByIdReadsTest`). What nothing could say was whether the NEXT read bypasses it. The discovery audit
 * counted 142 static product query starts and 67 raw `products` queries across the application, and the two source scans that existed
 * covered the caches (`StorefrontProductCachesTest`) and the code lookups (`ProductCodeLookupGuardTest`) and not the reads themselves. A
 * storefront narrowing that one read skips is another market's product on a customer's screen, and nothing would have said so.
 *
 * {@see CustomerProductReadScanner} does the reading and its docblock gives the rules. This file is the REGISTER: every query start that
 * does not reach the narrowing is named here with the reason it does not have to. A read added without the narrowing and without a line
 * here fails the first test; a read that LOSES its narrowing fails the second, because the narrowed count can only rise.
 */
class CustomerProductReadsAreNarrowedTest extends TestCase
{
    /**
     * Every customer-facing product query start that does not reach the narrowing, by file, with how many and why.
     *
     * Each of these is a verdict, not a waiver. Adding a line here is a decision somebody has to defend in review; the count beside the
     * file is what stops a second, unjudged read hiding behind a judged one.
     *
     * @var array<string, array{count: int, why: string}>
     */
    private const OPEN = [
        // `refund_details` reads the product of a line of the CUSTOMER'S OWN ORDER. An order's lines are history, and GCC-13 gives one
        // customer one identity across every market, so narrowing this would hide a customer's own past purchase from them -- the
        // opposite of what the narrowing is for. The order itself is reached by its own id and belongs to the customer.
        'app/Http/Controllers/Web/UserProfileController.php' => ['count' => 1, 'why' => 'a product of the customer\'s own order line, which is history and theirs to read'],

        // `rebuild` fills the search index, which holds EVERY product whether or not a customer may see it -- that is the index's own
        // contract, and the narrowing is applied when the index is READ (`StorefrontCatalog::restrictProductIds`, which narrows before any
        // cap on the number of rows). Narrowing the rebuild instead would index one market and answer every market from it.
        'app/Services/Search/ProductSearchIndexer.php' => ['count' => 1, 'why' => 'the index holds every product on purpose; the READ of it is narrowed'],

        // `update_cart_qty` reads the product of a row ALREADY IN THE CART. The cart's rows are narrowed by
        // `StorefrontCatalog::restrictCartRows`, and DECISIONS (2026-11-24) records that the narrowing must NEVER be extended to a row
        // the caller already holds in memory: `offers()` compares a column on such a row, so a row loaded without that column would be
        // refused, and a Saudi installation would stop selling its own products.
        'app/Utils/CartManager.php' => ['count' => 1, 'why' => 'a product of a row already in the cart, whose rows are narrowed by restrictCartRows'],

        // Seven starts in three shapes:
        //   - `get_seller_all_products` lists ONE seller's products by `user_id`. A seller belongs to no market yet (B-09), so there is
        //     nothing to narrow it by until sellers have markets; when they do, this is the line that changes.
        //   - six `Product::whereIn('<category>_id', ...)` builders inside `addAdditionalQueryForCategoryWithSubCategories` and
        //     `filterQueryForCategoryWithSubCategories` collect the IDS of a category's products across every market and are then
        //     intersected with the caller's own narrowed builder (`$query->whereIn('id', $ids)`), so the ANSWER is narrowed. The
        //     intermediate list reaches no response. This is the shape the scanner cannot follow: a builder handed on as an argument.
        'app/Utils/ProductManager.php' => ['count' => 7, 'why' => 'one seller-scoped list (B-09) and six id lists intersected with the caller\'s narrowed query'],
    ];

    /** What the scan read when this register was written. Both can only move in one direction, and the messages say which. */
    private const STARTS_READ = 93;

    private const NARROWED = 83;

    public function test_no_customer_facing_product_read_bypasses_the_narrowing_without_a_verdict_in_this_file(): void
    {
        $open = (new CustomerProductReadScanner())->analyse(base_path())['open'];
        $registered = array_map(static fn (array $entry): int => $entry['count'], self::OPEN);

        $this->assertSame(
            $registered,
            $open,
            'a customer-facing product read does not reach the storefront narrowing and is not accounted for in this file. Either narrow '
            . 'it -- `active()`, `inCurrentStorefront()`, one of StorefrontCatalog\'s `restrict*` helpers, or `offers()` for a row already '
            . 'in hand -- or add it to self::OPEN with the reason it does not have to. A count that fell is just as much a change: lower it.',
        );
    }

    public function test_the_scan_still_reads_the_code_and_the_narrowing_is_still_there(): void
    {
        $read = (new CustomerProductReadScanner())->analyse(base_path());

        $this->assertGreaterThanOrEqual(
            self::STARTS_READ,
            $read['starts'],
            'the scan found fewer product query starts than when this was measured: a tree of CustomerProductReadScanner::TREES has moved, '
            . 'and a scan of nothing passes every assertion above it',
        );
        $this->assertGreaterThanOrEqual(
            self::NARROWED,
            $read['narrowed'],
            'fewer customer-facing product reads reach the narrowing than when this was measured. A read that LOST its `active()` is the '
            . 'thing this number exists to catch, and the register above cannot catch it on its own',
        );
    }

    public function test_every_file_the_register_names_still_exists(): void
    {
        foreach (array_keys(self::OPEN) as $file) {
            $this->assertFileExists(base_path($file), "the register names a file that has moved: {$file}");
        }
    }
}
