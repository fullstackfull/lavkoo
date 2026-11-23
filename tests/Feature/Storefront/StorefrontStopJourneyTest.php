<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;

/**
 * M2-14 and I2-16, end to end: a customer buys in a storefront, a person stops that storefront in each of its three modes, and the order the customer already has is still
 * served (listed, tracked, its history read) while no new sale is taken; another storefront keeps selling meanwhile; and the storefront sells again when it is resumed. The
 * routes are the real ones and the order is a real checkout of the API, not a probe route: this is the behaviour that the route structure test of the lifecycle pins from
 * the outside. The default storefront can only be stopped browse-only, so it appears once; the five others appear in every mode.
 *
 * Refunds, the support chat and the payment webhooks of an earlier order are not covered here: their routes carry neither gate (pinned in StorefrontSalesRoutesTest), but no
 * scenario of them is run in this class.
 */
class StorefrontStopJourneyTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;

    private const PERSON = 'The head of the market';

    private const REASON = 'Decision of the operations meeting';

    private const PRODUCT_OF = ['SA' => 1, 'AE' => 2, 'KW' => 3, 'QA' => 4, 'BH' => 5, 'OM' => 6];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->setSetting('minimum_order_amount_status', '0');

        // What the customer's order list reads besides the order (the same additions as CustomerAcrossStorefrontsTest).
        if (!Schema::hasColumn('users', 'referred_by')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('referred_by')->nullable());
        }
        if (!Schema::hasColumn('reviews', 'order_id')) {
            Schema::table('reviews', fn (Blueprint $table) => $table->unsignedBigInteger('order_id')->nullable());
        }
        if (!Schema::hasTable('colors')) {
            Schema::create('colors', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('code')->nullable();
            });
        }
        foreach (['colors', 'attributes'] as $column) {
            if (!Schema::hasColumn('products', $column)) {
                Schema::table('products', fn (Blueprint $table) => $table->text($column)->nullable());
                DB::table('products')->update([$column => '[]']);
            }
        }
    }

    /** @return array<string, array{string, string}> the default storefront stopped browse-only, every other storefront stopped in each of the three modes */
    public static function everyStop(): array
    {
        $stops = ['SA, browse only' => ['SA', StorefrontLifecycle::BROWSE_ONLY]];

        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            foreach ([StorefrontLifecycle::BROWSE_ONLY, StorefrontLifecycle::MAINTENANCE, StorefrontLifecycle::REDIRECT] as $mode) {
                $stops["{$country}, {$mode}"] = [$country, $mode];
            }
        }

        return $stops;
    }

    private function asCustomerFrom(string $country, $customer): static
    {
        return $this->asCustomer($customer)->withHeader('X-Storefront', $country);
    }

    private function buy(string $country, $customer): int
    {
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCT_OF[$country]);
        $this->asCustomerFrom($country, $customer)->getJson('/api/v1/customer/order/place')->assertOk();

        return (int) DB::table('orders')->where('storefront_country', $country)->latest('id')->value('id');
    }

    #[DataProvider('everyStop')]
    public function test_an_earlier_order_is_served_while_its_storefront_is_stopped_and_a_new_sale_is_not_taken(string $country, string $mode): void
    {
        $customer = $this->makeCustomer();
        $orderId = $this->buy(country: $country, customer: $customer);

        app(StorefrontLifecycle::class)->pause(country: $country, mode: $mode, by: self::PERSON, reason: self::REASON);

        $listed = $this->asCustomerFrom($country, $customer)->getJson('/api/v1/customer/order/list?limit=20&offset=1')->assertOk();
        $this->assertContains($orderId, collect($listed->json('orders'))->pluck('id')->map(fn ($id): int => (int) $id)->all(), "the order is listed while {$country} is stopped ({$mode})");

        $this->asCustomerFrom($country, $customer)->getJson("/api/v1/order/track?order_id={$orderId}")->assertOk()->assertJsonPath('id', $orderId);
        $this->asCustomerFrom($country, $customer)->getJson("/api/v1/order/track-order-details?order_id={$orderId}")->assertOk()->assertJsonPath('history.order_placed.status', true);

        if ($mode === StorefrontLifecycle::MAINTENANCE) {
            $this->asCustomerFrom($country, $customer)->postJson('/api/v1/cart/add', ['id' => self::PRODUCT_OF[$country], 'quantity' => 1])
                ->assertStatus(503)->assertJsonPath('code', 'storefront_maintenance');
        } else {
            $this->asCustomerFrom($country, $customer)->postJson('/api/v1/cart/add', ['id' => self::PRODUCT_OF[$country], 'quantity' => 1])->assertOk();
        }

        $this->asCustomerFrom($country, $customer)->getJson('/api/v1/customer/order/place')->assertStatus(422)->assertJsonPath('code', 'storefront_not_sellable');
        $this->assertSame(1, DB::table('orders')->count(), 'a stopped storefront took no new sale');
    }

    #[DataProvider('everyStop')]
    public function test_another_storefront_keeps_selling_and_the_stopped_one_sells_again_when_it_is_resumed(string $country, string $mode): void
    {
        $customer = $this->makeCustomer();
        $other = $country === 'KW' ? 'AE' : 'KW';
        $this->buy(country: $country, customer: $customer);

        app(StorefrontLifecycle::class)->pause(country: $country, mode: $mode, by: self::PERSON, reason: self::REASON);

        $this->buy(country: $other, customer: $customer);
        $this->assertSame(2, DB::table('orders')->count(), "{$other} sold while {$country} was stopped");

        app(StorefrontLifecycle::class)->resume(country: $country, by: self::PERSON, reason: 'The work is done');

        $this->buy(country: $country, customer: $customer);
        $this->assertSame(3, DB::table('orders')->count(), "{$country} sells again once it is resumed");
        $this->assertSame([$country, $other, $country], DB::table('orders')->orderBy('id')->pluck('storefront_country')->all());
    }
}
