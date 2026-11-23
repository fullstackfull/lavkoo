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

    private function everyMarketSwitchOn(): void
    {
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.legal_entities.enabled' => true]);
        foreach (['QA', 'BH', 'OM', 'KW'] as $storefront) {
            config(["gcc.storefronts.countries.{$storefront}.active" => true]);
        }
        config(['gcc.storefronts.legal.grandfathered' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']]);
        StorefrontCatalog::flush();
    }

    /** Puts the one product of a storefront in a customer's cart and pays for it from the wallet; the header is left off for a platform with storefronts off. */
    private function payFromTheWallet(User $customer, int $productId, ?string $country): void
    {
        $header = $country === null ? [] : ['X-Storefront' => $country];

        $this->asCustomer($customer)->withHeaders($header)->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);
        foreach (DB::table('carts')->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }

        $this->asCustomer($customer)->withHeaders($header)->getJson(self::WALLET)->assertOk()->assertJsonPath('messages', translate('order_placed_successfully'));
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

    #[DataProvider('theSixStorefronts')]
    public function test_the_debit_of_each_storefront_carries_the_storefront_the_currency_and_the_company_its_order_recorded(string $country, int $productId, string $currencyCode): void
    {
        $this->ledgerMigration()->up();
        $this->everyMarketSwitchOn();
        $entity = $this->registerEntity(country: $country);
        $customer = $this->makeCustomer(['wallet_balance' => self::BALANCE]);

        $this->payFromTheWallet(customer: $customer, productId: $productId, country: $country);

        $order = DB::table('orders')->first();
        $row = $this->theDebit();
        $this->assertSame([$country, $currencyCode, $entity->id], [$order->storefront_country, $order->currency_code, (int) $order->legal_entity_id], 'the order recorded its market');
        $this->assertSame([$country, $currencyCode, $entity->id], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']], 'and the debit copies it');
        $this->assertSame(['order_group', $order->order_group_id], [$row['source_type'], $row['source_id']]);
        $this->assertSame([null, null], [$row['actor_type'], $row['actor_id']], 'a customer is not a staff member');
    }

    public function test_a_storefront_without_a_company_still_records_its_storefront_and_currency(): void
    {
        $this->ledgerMigration()->up();
        $this->everyMarketSwitchOn();
        $customer = $this->makeCustomer(['wallet_balance' => self::BALANCE]);

        $this->payFromTheWallet(customer: $customer, productId: 3, country: 'KW');

        $row = $this->theDebit();
        $this->assertSame(['KW', 'KWD', null], [$row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
    }

    public function test_before_the_migration_the_checkout_debits_exactly_as_it_always_did(): void
    {
        $this->everyMarketSwitchOn();
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
