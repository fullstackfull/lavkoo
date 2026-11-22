<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\OrderEditHistory;
use App\Models\User;
use App\Services\Payments\GatewayCurrencySupport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BuildsAddressSchema;
use Tests\Support\BuildsApiAddressSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Base for the payment-start tests: the real routes a customer and a phone start a payment on, with the
 * store tables those routes read.
 *
 * The schema helpers are the shared ones in tests/Support -- the same traits the address suite uses to
 * drive /api/v1/digital-payment -- rather than a second hand-written copy of the order pipeline. A test
 * that builds its own idea of the schema proves the test and not the platform.
 *
 * translate() writes any key it does not know into the tracked language files, and a refusal a customer
 * reads is exactly such a key, so the files are put back after every test.
 */
abstract class PaymentCurrencyTestCase extends TestCase
{
    use BuildsAddressSchema;
    use BuildsApiAddressSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();

        Http::preventStrayRequests();
        $this->buildAddressSchema();
        $this->createApiStoreTables();
        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->useThrowawayPassportKeys();
        $this->aStoreWithBranding();
    }

    /** The company name and logo every payment start puts in the gateway's additional_data. */
    private function aStoreWithBranding(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'company_name'], ['value' => json_encode('Lavkoo')]);
        DB::table('business_settings')->updateOrInsert(['type' => 'company_web_logo'], ['value' => json_encode(['image_name' => 'logo.png', 'storage' => 'public'])]);
        Cache::flush();
        $this->useTaxIncludedPrices();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /**
     * A marketplace that prices in several currencies: the money model the three payment starts convert in.
     *
     * The rates are the ones the conversion helpers read -- an amount held in USD is multiplied by the
     * target currency's rate -- so every figure a test asserts can be worked out by hand from them.
     */
    protected function aMultiCurrencyStore(array $activeCodes = ['SAR', 'USD', 'BDT']): void
    {
        DB::table('currencies')->truncate();
        $id = 0;
        foreach ([['USD', 'United States Dollar', 1], ['SAR', 'Saudi Riyal', 3.75], ['BDT', 'Bangladeshi Taka', 110], ['KWD', 'Kuwaiti Dinar', 0.31]] as [$code, $name, $rate]) {
            DB::table('currencies')->insert([
                'id' => ++$id, 'name' => $name, 'code' => $code, 'symbol' => $code,
                'exchange_rate' => $rate, 'status' => in_array($code, $activeCodes, true) ? 1 : 0,
            ]);
        }

        $this->setSetting('currency_model', 'multi_currency');
        $this->setSetting('system_default_currency', 2);
    }

    protected function aSingleCurrencyStore(): void
    {
        $this->aMultiCurrencyStore();
        $this->setSetting('currency_model', 'single_currency');
    }

    protected function setSetting(string $type, mixed $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => json_encode($value)]);

        // getWebConfig caches the whole settings table, so a setting written here is invisible until the
        // cache is dropped -- and dropping it takes the tax set-up the order code reads with it, so that
        // is put back in the same breath.
        Cache::flush();
        $this->useTaxIncludedPrices();
    }

    protected function paymentRequests(): int
    {
        return DB::table('payment_requests')->count();
    }

    /** @return array{currency: string, amount: float} what the gateway was asked to take. */
    protected function theCharge(): array
    {
        // Exactly one, so a figure is never read off whichever of several rows came back first.
        $rows = DB::table('payment_requests')->get();
        $this->assertCount(1, $rows, 'the charge was read with ' . $rows->count() . ' payment rows in the table');
        $row = $rows->first();

        return ['currency' => (string) $row->currency_code, 'amount' => (float) $row->payment_amount];
    }

    protected function aCartReadyToPay(int $callerId, bool $isGuest = false): void
    {
        $this->putOneProductInTheCart($callerId, $isGuest);
        DB::table('cart_shippings')->insert(['cart_group_id' => 'group-' . $callerId . ($isGuest ? 'g' : 'c'), 'shipping_method_id' => 1, 'shipping_cost' => 0]);
    }

    protected function asCustomer(User $customer): static
    {
        $this->actingAs($customer, 'api');

        return $this->withHeader('Authorization', 'Bearer test-token');
    }

    /** An order edit that left a due amount, and the history row the due-payment screens read. */
    protected function anOrderWithADueAmount(int $customerId, float $due = 40): Order
    {
        $order = Order::unguarded(static fn () => Order::create([
            'customer_id' => $customerId, 'customer_type' => 'customer', 'order_amount' => 300, 'edit_due_amount' => $due,
            'payment_status' => 'unpaid', 'order_status' => 'pending', 'payment_method' => 'ssl_commerz',
            'order_type' => 'default_type', 'seller_id' => 1, 'seller_is' => 'admin',
        ]));

        OrderEditHistory::create([
            'u_id' => (string) Str::uuid(), 'order_id' => $order->id, 'edit_by' => 'admin',
            'order_amount' => 300, 'order_due_amount' => $due,
        ]);

        return $order;
    }

    /** `orders.edit_due_amount` and the edit history, which the shared order-pipeline helper does not build. */
    protected function createOrderEditTables(): void
    {
        if (!Schema::hasColumn('orders', 'edit_due_amount')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->decimal('edit_due_amount', 16, 2)->default(0);
            });
        }

        if (!Schema::hasTable('order_edit_histories')) {
            (require database_path('migrations/2026_01_11_211839_create_order_edit_histories_table.php'))->up();
        }
    }

    /** Switches the refusal on, the way an operator would. */
    protected function switchTheRefusalOn(): void
    {
        $this->setSetting(GatewayCurrencySupport::SETTING, '1');
    }

    /** The wallet top-up, open and with a band the test amounts fall inside. */
    protected function walletTopUpIsOpen(): void
    {
        $this->setSetting('add_funds_to_wallet', 1);
        $this->setSetting('minimum_add_fund_amount', 1);
        $this->setSetting('maximum_add_fund_amount', 10000);
    }
}
