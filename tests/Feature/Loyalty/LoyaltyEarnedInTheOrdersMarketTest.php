<?php

namespace Tests\Feature\Loyalty;

use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\LedgerOrigin;
use App\Utils\CustomerManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * E-02 and J-V2: an order earns points at the rate of the market IT WAS SOLD IN, and not at the rate of whoever is marking it delivered.
 *
 * The four loyalty settings have been per-market for a while: `StorefrontCurrency::LOYALTY_SETTINGS` routes them through `getWebConfig`, and
 * MarketLoyaltyProgrammeTest proves a market answers its own. What was missing was the market to read them FOR. Both writers that credit an
 * order's points are reached from a panel or a seller API -- the admin order page, the vendor order page and the seller API's three status
 * changes -- and the request there serves no storefront, correctly, because a panel sees every market at once. So:
 *
 *  - the repository writer read the three settings straight off `business_settings`, bypassing the override entirely;
 *  - `CustomerManager` read them through `getWebConfig`, which answers for the market of the REQUEST -- the same platform rate from a panel.
 *
 * Two writers of the same money, disagreeing about where to read the rate, and both landing on the platform's. A Kuwaiti order earned at the
 * Saudi rate, and a market whose programme was switched off earned anyway.
 *
 * The first test is the one that protects what exists: an order that records no market earns exactly what it earns today, which is every
 * order of the Saudi platform and every order placed before the snapshot columns.
 */
class LoyaltyEarnedInTheOrdersMarketTest extends TestCase
{
    use RestoresLanguageFiles;

    private const CUSTOMER = 1;

    /** 10% of the amount, which is what the platform's own programme pays. */
    private const PLATFORM_RATE = '10';

    /** Kuwait pays 4%, as an operator set it with gcc:storefront:money. */
    private const KUWAIT = ['currency' => 'KWD', 'loyalty_point_status' => '1', 'loyalty_point_item_purchase_point' => '4'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        LoyaltyTables::drop();
        WalletTables::drop();
        WalletTables::create();
        LoyaltyTables::create();
        $this->buildTheOrdersAMarketIsRecordedOn();
        $this->buildTheMoneyOfTheMarkets();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_status', '1');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
        WalletTables::setSetting('loyalty_point_item_purchase_point', self::PLATFORM_RATE);
        WalletTables::seedCustomer(self::CUSTOMER, 0.0);

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        clearWebConfigCacheKeys();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('products');
        Schema::dropIfExists('currencies');
        LoyaltyTables::drop();
        WalletTables::drop();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** Only what the earn rate is read through: the row's id and the market the sale was made in. */
    private function buildTheOrdersAMarketIsRecordedOn(): void
    {
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('order_amount', 18, 4)->default(0);
            $table->char('storefront_country', 2)->nullable();
            $table->timestamps();
        });

        // What the staff country scope narrows an order THROUGH: its lines, and the market of the product on each. An order is one an
        // employee may see when every line is a product of their countries, so these two are what makes the model hide a Kuwaiti order
        // from an employee restricted to Bahrain.
        Schema::create('order_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->char('storefront_country', 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * A market answers its own loyalty settings only once the platform knows the money it sells in: the override is resolved from the
     * market's ACTIVE currency row, because the rate and the minimum are amounts in that currency. Without these rows the platform's own
     * programme stands, which is a correct refusal and would have made this test pass for the wrong reason.
     */
    private function buildTheMoneyOfTheMarkets(): void
    {
        Schema::dropIfExists('currencies');
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        foreach (['SAR' => 1, 'KWD' => 3, 'BHD' => 5] as $code => $id) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => 1, 'status' => 1]);
        }

        WalletTables::setSetting('currency_model', 'single_currency');
        WalletTables::setSetting('system_default_currency', '1');
        WalletTables::setSetting('decimal_point_settings', '2');
        Cache::forget('active_currency_list');
        clearWebConfigCacheKeys();
    }

    private function anOrderSoldIn(?string $market, int $id = 50): int
    {
        DB::table('orders')->insert(['id' => $id, 'customer_id' => self::CUSTOMER, 'order_amount' => 100, 'storefront_country' => $market]);
        DB::table('products')->insert(['id' => $id, 'storefront_country' => $market]);
        DB::table('order_details')->insert(['order_id' => $id, 'product_id' => $id]);

        return $id;
    }

    /** What an operator gave the market, written as `gcc:storefront:money` writes it. */
    private function giveTheMarketItsOwnProgramme(string $country, array $programme): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'storefront_money_' . strtolower($country)],
            ['value' => json_encode($programme)],
        );
        clearWebConfigCacheKeys();
    }

    private function repository(): LoyaltyPointTransactionRepositoryInterface
    {
        return app(LoyaltyPointTransactionRepositoryInterface::class);
    }

    /** @return array{0: string, 1: callable(int, float): bool} the two writers of an order's points, by name */
    public static function bothWriters(): array
    {
        return [
            'the repository, which the admin and vendor order pages use' => ['repository'],
            'CustomerManager, which the seller API uses' => ['customerManager'],
        ];
    }

    private function earn(string $writer, int $orderId, float $amount): bool
    {
        $origin = LedgerOrigin::order(id: $orderId);

        return $writer === 'repository'
            ? $this->repository()->addLoyaltyPointTransaction(userId: self::CUSTOMER, reference: (string) $orderId, amount: $amount, transactionType: 'order_place', origin: $origin)
            : CustomerManager::create_loyalty_point_transaction(self::CUSTOMER, (string) $orderId, $amount, 'order_place', origin: $origin);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothWriters')]
    public function test_an_order_that_records_no_market_earns_what_the_platform_pays(string $writer): void
    {
        $this->giveTheMarketItsOwnProgramme('KW', self::KUWAIT);

        $this->earn($writer, $this->anOrderSoldIn(null), 100.0);

        $this->assertSame(10.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER), '10% of 100, the platform rate, with a Kuwaiti rate set and unread');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothWriters')]
    public function test_an_order_of_the_default_market_earns_what_the_platform_pays(string $writer): void
    {
        $this->giveTheMarketItsOwnProgramme('KW', self::KUWAIT);

        $this->earn($writer, $this->anOrderSoldIn('SA'), 100.0);

        $this->assertSame(10.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothWriters')]
    public function test_an_order_of_a_market_with_its_own_rate_earns_at_that_rate(string $writer): void
    {
        $this->giveTheMarketItsOwnProgramme('KW', self::KUWAIT);

        $this->earn($writer, $this->anOrderSoldIn('KW'), 100.0);

        $this->assertSame(4.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER), 'Kuwait pays 4%, whoever marked the order delivered');
    }

    /** And the request's own market is not the one read: a panel serving Saudi Arabia still earns Kuwait's rate on a Kuwaiti order. */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothWriters')]
    public function test_the_market_of_the_person_marking_the_order_is_not_the_one_read(string $writer): void
    {
        $this->giveTheMarketItsOwnProgramme('KW', self::KUWAIT);
        $this->giveTheMarketItsOwnProgramme('BH', ['currency' => 'BHD', 'loyalty_point_status' => '1', 'loyalty_point_item_purchase_point' => '25']);
        $orderId = $this->anOrderSoldIn('KW');

        \App\Services\Storefront\StorefrontResolver::fromConfig()->context(explicitChoice: 'BH')->serve(fn (): bool => $this->earn($writer, $orderId, 100.0));

        $this->assertSame(4.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER), 'the order was sold in Kuwait, and Bahrain pays 25%');
    }

    /** A market may switch its programme off, and then its orders earn nothing while every other market still earns. */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothWriters')]
    public function test_an_order_of_a_market_with_its_programme_off_earns_nothing(string $writer): void
    {
        $this->giveTheMarketItsOwnProgramme('KW', ['currency' => 'KWD', 'loyalty_point_status' => '0']);

        $this->earn($writer, $this->anOrderSoldIn('KW'), 100.0);

        $this->assertSame(0.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count(), 'and no row at all, as a switched-off programme writes none');

        $this->earn($writer, $this->anOrderSoldIn('SA', id: 51), 100.0);

        $this->assertSame(10.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER), 'the platform still pays its own rate');
    }

    /**
     * A restricted employee still earns the ORDER's market rate.
     *
     * The order is read with the query builder and not the model, because the model carries the staff country scope: an employee restricted
     * to Bahrain, marking a Kuwaiti order delivered, would find no row through the model and the rate would quietly fall back to the
     * platform's -- a silent change of what a customer earns, caused by who happened to press the button.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothWriters')]
    public function test_a_restricted_employee_still_earns_the_rate_of_the_orders_own_market(string $writer): void
    {
        $this->giveTheMarketItsOwnProgramme('KW', self::KUWAIT);
        $orderId = $this->anOrderSoldIn('KW');
        // The two attributes StaffCountryScope keeps a narrowed request under, asserted straight after so a rename fails here loudly
        // rather than leaving this test passing for no reason. Both are needed: the scope narrows a subject, not merely a request.
        request()->attributes->set('staff_country_scope', ['BH']);
        request()->attributes->set('staff_country_scope_subjects', [StaffCountryScope::SUBJECT_ORDERS]);
        $this->assertSame(['BH'], app(StaffCountryScope::class)->boundCountries(), 'the employee is bound to Bahrain alone');
        $this->assertSame(['BH'], app(StaffCountryScope::class)->narrowedCountries(subject: StaffCountryScope::SUBJECT_ORDERS), 'and their order queries are narrowed to it');
        $this->assertNull(\App\Models\Order::query()->find($orderId), 'so the ORDER MODEL cannot see this order at all, which is the trap');

        $this->earn($writer, $orderId, 100.0);

        $this->assertSame(4.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER), 'Kuwait pays 4%, whoever is allowed to see which market');
    }

    /** The two writers agree, which is the point of reading the rate in one place. */
    public function test_both_writers_credit_the_same_points_for_the_same_order(): void
    {
        $this->giveTheMarketItsOwnProgramme('KW', self::KUWAIT);

        $this->earn('repository', $this->anOrderSoldIn('KW'), 250.0);
        $throughTheRepository = LoyaltyTables::loyaltyPointsOf(self::CUSTOMER);

        DB::table('users')->where('id', self::CUSTOMER)->update(['loyalty_point' => 0]);
        $this->earn('customerManager', $this->anOrderSoldIn('KW', id: 52), 250.0);

        $this->assertSame($throughTheRepository, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER));
        $this->assertSame(10.0, $throughTheRepository, '4% of 250');
    }

    /** With storefronts off nothing is read for a market at all, which is the platform as it ships. */
    public function test_with_storefronts_off_a_market_rate_is_not_read(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->giveTheMarketItsOwnProgramme('KW', self::KUWAIT);

        $this->earn('repository', $this->anOrderSoldIn('KW'), 100.0);

        $this->assertSame(10.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER));
    }
}
