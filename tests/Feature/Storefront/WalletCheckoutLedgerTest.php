<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsLedgerTables;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\Support\BuildsStorefrontCart;

/**
 * E-05 criteria 1 and 5, end to end: a real wallet checkout of the API, served from a storefront, writes one debit whose ledger row names the order group it paid and carries
 * the storefront, the currency and the selling company that the order itself recorded. A Saudi checkout with every switch off leaves the row what it always was, with the
 * new columns empty but the order group, and a database that has not run the ledger migration is debited exactly as before.
 */
class WalletCheckoutLedgerTest extends ApiAddressTestCase
{
    use BuildsLedgerTables;
    use BuildsLegalEntityWorld;
    use BuildsStorefrontCart;

    private const WALLET = '/api/v1/customer/order/place-by-wallet';

    private const BALANCE = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
        $this->addTheOtherCatalogs();
        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        $this->runLegalEntityMigrations();
        $this->createWalletAndLoyaltyLedgers();
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);

        foreach ([2 => ['AED', 'UAE Dirham'], 3 => ['KWD', 'Kuwaiti Dinar'], 4 => ['QAR', 'Qatari Riyal'], 5 => ['BHD', 'Bahraini Dinar'], 6 => ['OMR', 'Omani Rial']] as $id => [$code, $name]) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $name, 'code' => $code, 'symbol' => $code, 'exchange_rate' => 1, 'status' => 1]);
        }
        cache()->forget('active_currency_list');
        app(LedgerAttribution::class)->flush();
    }

    /** The catalog of the other four storefronts: the one product each sells, priced in its own currency (the seeded two are the Saudi 100 and the Emirati 250). */
    private function addTheOtherCatalogs(): void
    {
        $product = ['added_by' => 'admin', 'user_id' => 1, 'code' => 'SKU-1', 'variation' => '[]', 'images' => '[]', 'thumbnail' => 'x.png', 'category_ids' => '[]', 'discount' => 0, 'discount_type' => 'flat', 'choice_options' => '[]', 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude', 'current_stock' => 10];

        DB::table('products')->insert([
            $product + ['id' => 3, 'name' => 'Paracetamol KW', 'slug' => 'paracetamol-kw', 'unit_price' => 30, 'storefront_country' => 'KW'],
            $product + ['id' => 4, 'name' => 'Paracetamol QA', 'slug' => 'paracetamol-qa', 'unit_price' => 45, 'storefront_country' => 'QA'],
            $product + ['id' => 5, 'name' => 'Paracetamol BH', 'slug' => 'paracetamol-bh', 'unit_price' => 12.5, 'storefront_country' => 'BH'],
            $product + ['id' => 6, 'name' => 'Paracetamol OM', 'slug' => 'paracetamol-om', 'unit_price' => 14, 'storefront_country' => 'OM'],
        ]);
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function theSixStorefronts(): array
    {
        return [
            'Saudi Arabia' => ['SA', 1, 'SAR'],
            'United Arab Emirates' => ['AE', 2, 'AED'],
            'Kuwait' => ['KW', 3, 'KWD'],
            'Qatar' => ['QA', 4, 'QAR'],
            'Bahrain' => ['BH', 5, 'BHD'],
            'Oman' => ['OM', 6, 'OMR'],
        ];
    }

    /**
     * Every market switch on, and each storefront given leave to sell: being switched on in the configuration is not leave, because a
     * storefront sells once its tax is decided and a person has made it active, both of which are recorded rather than configured
     * (StorefrontSellingGate). Without that every request below is refused 422 before it reaches a wallet.
     *
     * `withTheirOwnMoney` is the storefront currency switch. While it is on, a storefront that trades in its own money has its wallet
     * CLOSED (EnsureWalletIsOpenInStorefront, GCC-05 slice 5b): one balance kept in the platform's money cannot pay a dinar order, so the
     * checkout answers 403 and nothing is written. Turning it off is how this test reaches the debit of a storefront other than the default
     * one, which is where the attribution of E-05 is read.
     */
    private function everyMarketSwitchOn(bool $withTheirOwnMoney = true): void
    {
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.currency.enabled' => $withTheirOwnMoney, 'gcc.storefronts.legal_entities.enabled' => true]);
        foreach (['QA', 'BH', 'OM', 'KW'] as $storefront) {
            config(["gcc.storefronts.countries.{$storefront}.active" => true]);
        }
        config(['gcc.storefronts.legal.grandfathered' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']]);
        $this->approveNoTaxFor('KW', 'QA', 'BH', 'OM');
        $this->activateStorefrontFor('KW', 'QA', 'BH', 'OM');
        StorefrontCatalog::flush();
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> the five storefronts that trade in a money of their own */
    public static function theStorefrontsWithTheirOwnMoney(): array
    {
        $storefronts = self::theSixStorefronts();
        unset($storefronts['Saudi Arabia']);

        return $storefronts;
    }

    /** Puts the one product of a storefront in a customer's cart and pays for it from the wallet; the header is left off for a platform with storefronts off. */
    private function payFromTheWallet(User $customer, int $productId, ?string $country): void
    {
        $header = $country === null ? [] : ['X-Storefront' => $country];

        $this->fillTheCartFrom(customer: $customer, productId: $productId, country: $country);

        $this->asCustomer($customer)->withHeaders($header)->getJson(self::WALLET)->assertOk()->assertJsonPath('messages', translate('order_placed_successfully'));
    }

    /** The cart the checkout pays for, with a shipping method chosen for each group it holds. */
    private function fillTheCartFrom(User $customer, int $productId, ?string $country): void
    {
        $header = $country === null ? [] : ['X-Storefront' => $country];

        $this->asCustomer($customer)->withHeaders($header)->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);
        foreach (DB::table('carts')->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }
    }

    /** @return array<string, mixed> the ledger row the debit wrote */
    private function theDebit(): array
    {
        $this->assertSame(1, DB::table('wallet_transactions')->count(), 'one checkout is one debit');

        return $this->newestLedgerRow();
    }

    public function test_the_debit_of_a_saudi_checkout_with_every_switch_off_is_what_it_always_was_and_names_only_its_order_group(): void
    {
        $this->ledgerMigration()->up();
        $customer = $this->makeCustomer(['wallet_balance' => self::BALANCE]);

        $this->payFromTheWallet(customer: $customer, productId: 1, country: null);

        $order = DB::table('orders')->first();
        $row = $this->theDebit();
        $this->assertSame(['order_place', 'order payment', null, 0.0], [$row['transaction_type'], $row['reference'], $row['payment_method'], $row['credit']]);
        $this->assertEqualsWithDelta(self::BALANCE - (float) $customer->fresh()->wallet_balance, $row['debit'], 0.0001, 'the debit is what left the wallet');
        $this->assertEqualsWithDelta(self::BALANCE, $row['balance'] + $row['debit'], 0.0001);
        $this->assertSame(['order_group', $order->order_group_id], [$row['source_type'], $row['source_id']]);
        $this->assertNotSame('def-order-group', $row['source_id']);
        $this->assertSame([null, null, null, null, null], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id'], $row['actor_type'], $row['actor_id']]);
    }

    /**
     * The default storefront with every switch on: its wallet stays open, because its money IS the platform's, so this is the one checkout
     * that reaches a debit while the storefront currencies are on, and the debit carries everything the order recorded.
     */
    public function test_the_debit_of_the_default_storefront_carries_the_storefront_the_currency_and_the_company_its_order_recorded(): void
    {
        $this->ledgerMigration()->up();
        $this->everyMarketSwitchOn();
        $entity = $this->registerEntity(country: 'SA');
        $customer = $this->makeCustomer(['wallet_balance' => self::BALANCE]);

        $this->payFromTheWallet(customer: $customer, productId: 1, country: 'SA');

        $order = DB::table('orders')->first();
        $row = $this->theDebit();
        $this->assertSame(['SA', 'SAR', $entity->id], [$order->storefront_country, $order->currency_code, (int) $order->legal_entity_id], 'the order recorded its market');
        $this->assertSame(['SA', 'SAR', $entity->id], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']], 'and the debit copies it');
        $this->assertSame(['order_group', $order->order_group_id], [$row['source_type'], $row['source_id']]);
        $this->assertSame([null, null], [$row['actor_type'], $row['actor_id']], 'a customer is not a staff member');
    }

    /**
     * The other five storefronts, with their money their own: the wallet is closed there, so the checkout is refused and there is no debit
     * to attribute. This was written as a sixfold proof that the debit of every storefront carries its market, and five sixths of it is no
     * longer reachable through this route -- one balance in the platform's money may not pay a dinar order, which is the rule
     * EnsureWalletIsOpenInStorefront enforces and StorefrontWalletClosedTest pins. What is left to prove is that the refusal costs nothing,
     * and the attribution is read below, where the checkout is open.
     */
    #[DataProvider('theStorefrontsWithTheirOwnMoney')]
    public function test_a_storefront_that_trades_in_its_own_money_refuses_the_wallet_checkout_and_writes_no_debit(string $country, int $productId): void
    {
        $this->ledgerMigration()->up();
        $this->everyMarketSwitchOn();
        $this->registerEntity(country: $country);
        $customer = $this->makeCustomer(['wallet_balance' => self::BALANCE]);

        $this->fillTheCartFrom(customer: $customer, productId: $productId, country: $country);
        $this->asCustomer($customer)->withHeaders(['X-Storefront' => $country])->getJson(self::WALLET)->assertForbidden();

        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'a refused checkout writes no ledger row');
        $this->assertSame(0, DB::table('orders')->count(), 'and no order');
        $this->assertEqualsWithDelta(self::BALANCE, (float) $customer->fresh()->wallet_balance, 0.0001, 'and takes nothing from the wallet');
    }

    /**
     * The same five storefronts while their money is the platform's, which is the state the platform ships in and the one in which their
     * wallets are open: the debit carries the selling company of the order it paid. Neither the order nor the row records a storefront or a
     * currency, because an order is only recorded as sold in a storefront's money while that money is in force (OrderStorefrontSnapshot),
     * and the company is recorded on its own switch. So what this proves of those five is the company half; the storefront and the currency
     * are read above, on the one storefront whose wallet is open while the money is theirs.
     */
    #[DataProvider('theStorefrontsWithTheirOwnMoney')]
    public function test_the_debit_of_each_storefront_carries_the_company_while_their_money_is_the_platforms(string $country, int $productId): void
    {
        $this->ledgerMigration()->up();
        $this->everyMarketSwitchOn(withTheirOwnMoney: false);
        $entity = $this->registerEntity(country: $country);
        $customer = $this->makeCustomer(['wallet_balance' => self::BALANCE]);

        $this->payFromTheWallet(customer: $customer, productId: $productId, country: $country);

        $order = DB::table('orders')->first();
        $row = $this->theDebit();
        $this->assertSame([null, $entity->id], [$order->storefront_country, (int) $order->legal_entity_id], 'the order recorded the company that sold it and no money of its own');
        $this->assertSame([null, null, $entity->id], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']], 'and the debit copies it');
        $this->assertSame(['order_group', $order->order_group_id], [$row['source_type'], $row['source_id']]);
        $this->assertSame([null, null], [$row['actor_type'], $row['actor_id']], 'a customer is not a staff member');
    }

    /** No company registered for the storefront the order was sold in: what it was sold in is still recorded, and the company is simply left empty. */
    public function test_a_storefront_without_a_company_still_records_its_storefront_and_currency(): void
    {
        $this->ledgerMigration()->up();
        $this->everyMarketSwitchOn();
        $customer = $this->makeCustomer(['wallet_balance' => self::BALANCE]);

        $this->payFromTheWallet(customer: $customer, productId: 1, country: 'SA');

        $row = $this->theDebit();
        $this->assertSame(['SA', 'SAR', null], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
    }

    public function test_before_the_migration_the_checkout_debits_exactly_as_it_always_did(): void
    {
        $this->everyMarketSwitchOn(withTheirOwnMoney: false);
        $this->registerEntity(country: 'KW');
        $customer = $this->makeCustomer(['wallet_balance' => self::BALANCE]);

        $this->payFromTheWallet(customer: $customer, productId: 3, country: 'KW');

        $row = $this->theDebit();
        $this->assertSame([], array_intersect(self::LEDGER_COLUMNS, Schema::getColumnListing('wallet_transactions')), 'the migration has not run');
        $this->assertSame(['order_place', 'order payment'], [$row['transaction_type'], $row['reference']]);
        $this->assertEqualsWithDelta(self::BALANCE - (float) $customer->fresh()->wallet_balance, $row['debit'], 0.0001);
    }

    public function test_the_amount_and_the_balance_of_the_debit_do_not_depend_on_the_migration(): void
    {
        $this->ledgerMigration()->up();
        $first = $this->makeCustomer(['wallet_balance' => self::BALANCE]);
        $this->payFromTheWallet(customer: $first, productId: 1, country: null);
        $withTheMigration = [$this->theDebit()['debit'], (float) $first->fresh()->wallet_balance];

        DB::table('wallet_transactions')->delete();
        DB::table('orders')->delete();
        DB::table('order_details')->delete();
        DB::table('carts')->delete();
        DB::table('cart_shippings')->delete();
        $this->ledgerMigration()->down();
        app(LedgerAttribution::class)->flush();
        $second = $this->makeCustomer(['wallet_balance' => self::BALANCE]);
        $this->payFromTheWallet(customer: $second, productId: 1, country: null);

        $this->assertSame($withTheMigration, [$this->theDebit()['debit'], (float) $second->fresh()->wallet_balance]);
    }
}
