<?php

namespace Tests\Feature\Marketplace;

use App\Models\LegalEntity;
use App\Models\User;
use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\RefundReversalService;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSellerLedgerWorld;

/**
 * The company a seller's money is in, proved on the path the running system takes (GCC-13, D1-03, E-17.2).
 *
 * The tests of VendorLedgerLegalEntityTest call the line writer with an order that is already stamped. Production does the opposite: an order is
 * placed, its lines are saved, and only then is the order stamped with the company that sold it — so a reader of the stamp that runs while the
 * lines are being saved sees nothing, and every row of the seller's ledger was written without a company however the registry was set up.
 *
 * These tests place a real order through OrderManager::generateOrder, in the order the checkout does it, and look at what is on the ledger
 * afterwards.
 */
class OrderCompanyOnTheLivePathTest extends ApiAddressTestCase
{
    use BuildsSellerLedgerWorld;

    private const SELLER = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->createSellerLedgerTables();
        $this->giveSellersACommissionPercentage();

        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        (require database_path('migrations/2026_11_27_100000_create_legal_entities_table.php'))->up();
        $this->runCompanyMigrations();

        DB::table('sellers')->insert(['id' => self::SELLER, 'f_name' => 'Seller', 'status' => 'approved']);
        DB::table('business_settings')->updateOrInsert(['type' => 'sales_commission'], ['value' => '10']);
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        foreach (['vendor_ledger_entries', 'order_item_commissions', 'legal_entities'] as $table) {
            Schema::dropIfExists($table);
        }
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- the live path ---------------------------------------------------------------------------------------------------------

    public function test_the_earning_and_its_commission_of_a_real_order_name_the_company_that_sold_it(): void
    {
        $this->turnSellingCompaniesOn();
        $company = $this->registerSaudiCompany();

        $orderId = $this->placeAnOrderOfOneSellerLine();

        $this->assertSame($company->id, (int) DB::table('orders')->where('id', $orderId)->value(LegalEntityRegistry::COLUMN), 'the order is stamped');
        $this->assertSame(
            [$company->id, $company->id],
            array_values($this->companiesOnTheLedger()),
            'the earning and the commission charged on it name the company the order was stamped with',
        );
    }

    public function test_a_refund_of_that_order_goes_back_into_the_same_company(): void
    {
        $this->turnSellingCompaniesOn();
        $company = $this->registerSaudiCompany();
        $orderId = $this->placeAnOrderOfOneSellerLine();
        $lineId = (int) DB::table('order_details')->where('order_id', $orderId)->value('id');

        $reversal = app(RefundReversalService::class)->reverseForOrderLine(orderDetailsId: $lineId, refundedAmount: 100);

        $this->assertSame(['refund_debit' => 100.0, 'commission_credit' => 10.0], $reversal);
        $this->assertSame(
            [$company->id, $company->id, $company->id, $company->id],
            array_values($this->companiesOnTheLedger()),
            'the money given back leaves the company that holds the earning',
        );
    }

    public function test_the_company_of_the_seller_is_the_one_a_payout_of_that_order_would_be_drawn_on(): void
    {
        $this->turnSellingCompaniesOn();
        $company = $this->registerSaudiCompany();

        $this->placeAnOrderOfOneSellerLine();

        $this->assertSame($company->id, $this->ledger()->companyOfSeller(sellerId: self::SELLER));
    }

    public function test_an_order_of_two_countries_names_no_company_and_is_still_placed(): void
    {
        $this->turnSellingCompaniesOn();
        config(['gcc.storefronts.countries.AE.active' => true]);
        $this->registerSaudiCompany();
        $this->registerCompany('AE');
        StorefrontCatalog::flush();

        $orderId = $this->placeAnOrderOfOneSellerLine(extraCountries: ['AE']);

        $this->assertSame(2, DB::table('order_details')->where('order_id', $orderId)->count());
        $this->assertNull(DB::table('orders')->where('id', $orderId)->value(LegalEntityRegistry::COLUMN), 'two countries freeze no company');
        $this->assertSame([null, null, null, null], array_values($this->companiesOnTheLedger()));
    }

    public function test_an_empty_registry_leaves_the_ledger_exactly_as_it_is_today(): void
    {
        $this->turnSellingCompaniesOn();

        $orderId = $this->placeAnOrderOfOneSellerLine();

        $this->assertSame(0, LegalEntity::query()->count());
        $this->assertNull(DB::table('orders')->where('id', $orderId)->value(LegalEntityRegistry::COLUMN));
        $this->assertSame([null, null], array_values($this->companiesOnTheLedger()));
        $this->assertSame([100.0, 90.0], $this->runningBalance());
    }

    public function test_with_the_switch_off_a_saudi_order_writes_the_ledger_it_writes_today(): void
    {
        $this->registerSaudiCompany();

        $orderId = $this->placeAnOrderOfOneSellerLine();

        $this->assertNull(DB::table('orders')->where('id', $orderId)->value(LegalEntityRegistry::COLUMN), 'nothing is stamped while the switch is off');
        $this->assertSame([null, null], array_values($this->companiesOnTheLedger()));
        $this->assertSame([100.0, 90.0], $this->runningBalance());
        $this->assertSame(
            [VendorLedgerEntry::TYPE_ORDER_EARNING, VendorLedgerEntry::TYPE_COMMISSION_CHARGE],
            DB::table('vendor_ledger_entries')->orderBy('id')->pluck('entry_type')->all(),
        );
    }

    // ---- the world -------------------------------------------------------------------------------------------------------------

    /** The checkout, run the way every controller runs it: the cart is priced, the order row is written, its lines are saved, and its money is recorded. */
    private function placeAnOrderOfOneSellerLine(array $extraCountries = []): int
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->putASellersProductInTheCart(customerId: $customer->id, productId: 1, country: 'SA');

        foreach ($extraCountries as $index => $country) {
            $this->putASellersProductInTheCart(customerId: $customer->id, productId: 2 + $index, country: $country);
        }

        request()->merge(['user' => $customer]);

        $orderIds = OrderManager::generateOrder(data: [
            'address_id' => $address->id, 'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'pending',
            'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'transaction_ref' => '', 'requestObj' => request(),
        ]);

        return (int) $orderIds[0];
    }

    private function putASellersProductInTheCart(int $customerId, int $productId, string $country): void
    {
        DB::table('products')->insert([
            'id' => $productId, 'name' => 'Paracetamol ' . $country, 'added_by' => 'seller', 'user_id' => self::SELLER,
            'unit_price' => 100, 'current_stock' => 10, StorefrontCatalog::COLUMN => $country,
        ]);

        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => false, 'cart_group_id' => 'group-' . $customerId, 'product_id' => $productId,
            'quantity' => 1, 'price' => 100, 'seller_id' => self::SELLER, 'seller_is' => 'seller', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function registerSaudiCompany(): LegalEntity
    {
        return $this->registerCompany('SA');
    }

    private function registerCompany(string $country): LegalEntity
    {
        $entity = LegalEntity::create([
            'country' => $country,
            'legal_name' => "Lavkoo {$country} Trading Co.",
            'trade_name' => "Lavkoo {$country}",
            'tax_registration_number' => "TAX-{$country}-001",
            'commercial_registration_number' => "CR-{$country}-777",
            'invoice_series_key' => "INV-{$country}",
            'address' => "1 Example Street, {$country}",
            'status' => true,
        ]);
        app(LegalEntityRegistry::class)->flush();

        return $entity;
    }

    /** @return list<float> the balance after each entry of the ledger, in order */
    private function runningBalance(): array
    {
        return DB::table('vendor_ledger_entries')->orderBy('id')->pluck('balance_after')->map(fn ($amount) => (float) $amount)->all();
    }

    /** The column CommissionEngine reads before it falls back to the store's own percentage; the address world's sellers table has no money on it. */
    private function giveSellersACommissionPercentage(): void
    {
        if (!Schema::hasColumn('sellers', 'sales_commission_percentage')) {
            Schema::table('sellers', static function (Blueprint $table): void {
                $table->decimal('sales_commission_percentage', 12, 4)->nullable();
            });
        }
    }
}
