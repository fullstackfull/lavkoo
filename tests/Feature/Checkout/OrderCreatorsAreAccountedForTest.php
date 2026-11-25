<?php

namespace Tests\Feature\Checkout;

use App\Services\Checkout\OrderCreatorRegister;
use App\Services\Storefront\OrderStorefrontSnapshot;
use Tests\TestCase;

/**
 * D1-01, last criterion: every path that writes an order either records the market it sold in, or is exempt on the record.
 *
 * The criterion names five creators outside the customer checkout and asks for one of two things from each. Four of them stamp; the
 * marketplace dispatcher deliberately does not, because whether a sale made on somebody else's marketplace is a sale of one of OUR
 * storefronts is the owner's question and not a technical one. What was missing was not the stamping -- that is in the code -- but
 * anything that could TELL the two apart, or notice a sixth creator.
 *
 * So this reads `OrderCreatorRegister` against the repository, in both directions:
 *
 *   - every file in the tree that writes an `orders` row is in the register, so a new order creator fails here rather than being
 *     discovered later by a market whose orders came out blank;
 *   - every file the register says records its market actually calls the recorder, so a stamp taken out of a till during a tidy-up
 *     fails here too;
 *   - every exemption says why it is one and names the owner decision it waits on, because an exemption with no reason is
 *     indistinguishable from an oversight, which is the whole thing this criterion is about.
 *
 * It also reads the SALE RULE half of the register (D1-16): a till says it asks `PointOfSaleSaleRule` and must call it, a creator behind
 * the route gate must be one whose routes carry `storefront.sellable`, and a creator that materialises an obligation rather than making a
 * new sale must say why -- which I2-09 settled for the sales channels and left open only for a PAUSED country.
 *
 * The till half asserts that the CALL is there, not that it refuses anything: `gcc.storefronts.sale_gate.at_the_till` ships off and
 * `config/gcc.php` says why, so these assertions hold for the wiring while the behaviour stays today's. What the rule does once the key is
 * on is `TheTillOfAStoppedMarketSellsNothingTest`'s business.
 *
 * It is a STRUCTURAL test and says so. That the checkout's stamp reaches every sub-order is proved behaviourally next door, by
 * `EachSubOrderCarriesItsOwnMarketTest`; the tills have their own tests. What cannot be proved behaviourally is the negative -- that
 * nothing ELSE writes an order -- and that is what reading the tree is for.
 *
 * TEETH, each mutation applied and restored: a new class in `app/Services/Checkout` that writes an order row and is in no entry -> 1 red;
 * taking the stamp out of the administrator's till -> 1 red; making the exempt marketplace dispatcher stamp after all -> 1 red; and, for
 * the sale-rule half, taking the rule's call out of the seller app's till -> 1 red here (and 2 more in the till's own test). 9 tests / 25
 * assertions, and it needs no database, so it is the same answer on every engine.
 */
class OrderCreatorsAreAccountedForTest extends TestCase
{
    /**
     * How an `orders` row is written in this repository. Read as patterns rather than as a list of files, because a list of files is the
     * very thing this test exists to check.
     */
    private const WRITES_AN_ORDER_ROW = [
        "#DB::table\('orders'\)->insert#",
        '#\bOrder::create\(#',
        '#orderRepo->add\(#',
    ];

    /** @return list<string> every PHP file of app/ and Modules/, relative to the project root. */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (['app', 'Modules'] as $tree) {
            $directory = base_path($tree);

            if (!is_dir($directory)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), DIRECTORY_SEPARATOR);
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The files that write an order row, with comments stripped first.
     *
     * Stripping them matters here more than it looks: three of these files TALK about the other writers in their comments -- one of them
     * names `DB::table()->insertGetId()` and the two tills in a sentence explaining why it does not use a model event -- so a scanner
     * reading raw text finds order creators that are prose.
     *
     * @return list<string>
     */
    private function filesThatWriteAnOrder(): array
    {
        $found = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents(base_path($file));
            $code = $this->withoutComments($source);

            foreach (self::WRITES_AN_ORDER_ROW as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $found[] = $file;

                    continue 2;
                }
            }
        }

        return $found;
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function test_every_file_that_writes_an_order_is_in_the_register(): void
    {
        $unaccounted = array_values(array_diff($this->filesThatWriteAnOrder(), array_keys(OrderCreatorRegister::CREATORS)));

        $this->assertSame([], $unaccounted, implode("\n", [
            'A path writes an order row and is in no register entry, so nothing says whether it records the market it sold in:',
            ...$unaccounted,
            'Add it to App\Services\Checkout\OrderCreatorRegister as records_its_market or as exempt with its reason (D1-01).',
        ]));
    }

    public function test_the_register_names_no_path_that_does_not_write_an_order(): void
    {
        $gone = array_values(array_diff(array_keys(OrderCreatorRegister::CREATORS), $this->filesThatWriteAnOrder()));

        $this->assertSame([], $gone, implode("\n", [
            'The register names a path that no longer writes an order row:',
            ...$gone,
            'Take the entry out, or correct it: a register that lists paths which are gone stops being read.',
        ]));
    }

    public function test_every_creator_that_claims_to_record_its_market_calls_the_recorder(): void
    {
        foreach (array_keys(OrderCreatorRegister::thatRecordTheirMarket()) as $file) {
            $code = $this->withoutComments((string) file_get_contents(base_path($file)));

            $this->assertMatchesRegularExpression(
                '#(OrderStorefrontSnapshot::class\)|\$storefrontSnapshot)\s*(\)?)\s*(->|\n\s*->)?\s*record\(#',
                $code,
                $file . ' says it records the market of the orders it writes, and does not call ' . OrderCreatorRegister::RECORDER . '.',
            );
        }
    }

    public function test_every_exempt_creator_records_nothing_and_says_whose_decision_that_is(): void
    {
        $exempt = OrderCreatorRegister::exempt();

        $this->assertNotSame([], $exempt, 'the register is expected to hold the marketplace dispatcher as the one exemption');

        foreach ($exempt as $file => $creator) {
            $code = $this->withoutComments((string) file_get_contents(base_path($file)));

            $this->assertDoesNotMatchRegularExpression(
                '#->record\(\s*orderId#',
                $code,
                $file . ' is registered as exempt from recording its market and records it anyway. One of the two is wrong.',
            );

            $this->assertNotEmpty($creator['reason'] ?? '', $file . ' is exempt with no reason, which cannot be told from an oversight.');
            $this->assertNotEmpty($creator['decision'] ?? '', $file . ' is exempt and names no owner decision it waits on.');
        }
    }

    /** The five creators the criterion names, each accounted for -- which is the criterion read back as an assertion. */
    public function test_the_creators_the_criterion_names_are_each_accounted_for(): void
    {
        $this->assertSame([
            'app/Http/Controllers/Admin/POS/POSOrderController.php' => OrderCreatorRegister::RECORDS_ITS_MARKET,
            'app/Http/Controllers/RestAPI/v3/seller/POSController.php' => OrderCreatorRegister::RECORDS_ITS_MARKET,
            'app/Http/Controllers/Vendor/POS/POSOrderController.php' => OrderCreatorRegister::RECORDS_ITS_MARKET,
            'app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php' => OrderCreatorRegister::EXEMPT,
            'app/Utils/OrderManager.php' => OrderCreatorRegister::RECORDS_ITS_MARKET,
        ], collect(OrderCreatorRegister::CREATORS)->map(fn (array $creator): string => $creator['state'])->sortKeys()->all());

        // The auction claim the criterion also names: there is no Auction module in this repository, so there is nothing to account for
        // and nothing to exempt. Asserted rather than assumed, because a module appearing later must bring its own entry with it.
        $this->assertDirectoryDoesNotExist(base_path('Modules/Auction'));
    }

    /** The recorder the register names is the real method, so renaming it cannot leave the register pointing at nothing. */
    public function test_the_recorder_the_register_names_exists(): void
    {
        $this->assertTrue(
            method_exists(OrderStorefrontSnapshot::class, 'record'),
            OrderCreatorRegister::RECORDER . ' is what every registered creator is checked against and it does not exist.',
        );
    }

    public function test_every_till_that_says_it_asks_the_sale_rule_calls_it(): void
    {
        $tills = OrderCreatorRegister::thatAskTheSaleRule();

        $this->assertCount(3, $tills, 'the three tills are the creators no storefront serves, so they are the ones that ask the rule');

        foreach (array_keys($tills) as $file) {
            $code = $this->withoutComments((string) file_get_contents(base_path($file)));

            $this->assertMatchesRegularExpression(
                '#PointOfSaleSaleRule::class\)\s*->\s*marketThatMayNotSell\(#',
                $code,
                $file . ' says it asks the point-of-sale sale rule before a new sale and does not call ' . OrderCreatorRegister::SALE_RULE . '.',
            );

            // THAT the rule is asked before anything is written is NOT asserted here, and the first version of this test tried to:
            // it compared the POSITION of the two calls in the source, which passed for the two panels and failed for the seller app
            // because that controller asks the rule from a private helper DEFINED BELOW the method that writes the order. Source order
            // is not call order, so the measurement was wrong rather than the code. It is proved where it can be -- behaviourally, by
            // `ATillOfAStoppedMarketSellsNothingTest`, which refuses a sale and then asserts no order, no stock movement and no wallet
            // debit.
        }
    }

    /** A creator the register puts behind the route gate must have routes that carry it, or the register is describing a gate nobody applies. */
    public function test_a_creator_behind_the_route_gate_is_reachable_only_through_routes_that_carry_it(): void
    {
        $behindTheGate = array_filter(
            OrderCreatorRegister::CREATORS,
            static fn (array $creator): bool => $creator['sale_rule'] === OrderCreatorRegister::BEHIND_THE_ROUTE_GATE,
        );

        $this->assertNotSame([], $behindTheGate, 'the customer checkout is expected to be the creator behind the route gate');

        $routeFiles = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents(base_path($file)),
            ['routes/web/routes.php', 'routes/rest_api/v1/api.php'],
        ));

        $this->assertStringContainsString(
            OrderCreatorRegister::ROUTE_GATE,
            $routeFiles,
            'the register says the checkout is behind ' . OrderCreatorRegister::ROUTE_GATE . ' and no route file applies it.',
        );
    }

    /** Every entry says something about the sale rule, and an obligation that is not gated says why -- the D1-16 half of this register. */
    public function test_every_creator_states_its_sale_rule_and_an_ungated_one_says_why(): void
    {
        foreach (OrderCreatorRegister::CREATORS as $file => $creator) {
            $this->assertContains($creator['sale_rule'] ?? '', [
                OrderCreatorRegister::ASKS_THE_SALE_RULE,
                OrderCreatorRegister::BEHIND_THE_ROUTE_GATE,
                OrderCreatorRegister::NOT_A_NEW_SALE,
            ], $file . ' says nothing about the sale rule it applies (D1-16).');

            if (($creator['sale_rule'] ?? '') === OrderCreatorRegister::NOT_A_NEW_SALE) {
                $this->assertNotEmpty(
                    $creator['sale_rule_reason'] ?? '',
                    $file . ' is not gated and does not say why, which cannot be told from a creator nobody gated.',
                );
            }
        }
    }
}
