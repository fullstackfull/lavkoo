<?php

namespace Tests\Feature\Storefront;

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
 * M2-04 of the master plan: one customer, one account, in every country. The customer's own endpoints answer the same account from each of the six storefronts and no
 * user is created for a storefront; the orders of every country are in the one list the customer reads, whichever storefront the app asks from; and the wallet is one
 * balance for every storefront (a characterization: GCC-15 will split it, and that check is the one that flips). The sign-in with credentials is not exercised here
 * (it needs the whole user table and the verification settings); the customer acts through a token, as the apps do after it.
 */
class CustomerAcrossStorefrontsTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;

    private const STOREFRONTS = ['SA' => 1, 'AE' => 2, 'KW' => 3, 'QA' => 4, 'BH' => 5, 'OM' => 6];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->setSetting('minimum_order_amount_status', '0');

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

    /** @return array<string, array{string}> */
    public static function storefronts(): array
    {
        return array_combine(array_keys(self::STOREFRONTS), array_map(static fn (string $code): array => [$code], array_keys(self::STOREFRONTS)));
    }

    private function asCustomerFrom(?string $storefront, $customer): static
    {
        $request = $this->asCustomer($customer);

        return $storefront === null ? $request : $request->withHeader('X-Storefront', $storefront);
    }

    #[DataProvider('storefronts')]
    public function test_the_one_account_is_read_through_every_storefront(string $storefront): void
    {
        $customer = $this->makeCustomer();

        $info = $this->asCustomerFrom($storefront, $customer)->getJson('/api/v1/customer/info')->assertOk();

        $this->assertSame($customer->id, $info->json('id'));
        $this->assertSame($customer->email, $info->json('email'));
        $this->assertSame(1, DB::table('users')->count(), 'no user is created for a storefront');
    }

    public function test_the_orders_of_every_country_are_in_the_one_list_the_customer_reads_from_any_storefront(): void
    {
        $customer = $this->makeCustomer();

        foreach (self::STOREFRONTS as $storefront => $productId) {
            $this->fillTheCartFrom(customer: $customer, country: $storefront, productId: $productId);
            $this->asCustomerFrom($storefront, $customer)->getJson('/api/v1/customer/order/place')->assertOk();
        }

        $this->assertSame(6, DB::table('orders')->count());
        $this->assertSame([...array_values(self::STOREFRONTS)], DB::table('orders')->orderBy('id')->pluck('storefront_country')->map(fn (?string $country): int => self::STOREFRONTS[$country])->all());

        $expected = DB::table('orders')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ([...array_keys(self::STOREFRONTS), null] as $storefront) {
            $list = $this->asCustomerFrom($storefront, $customer)->getJson('/api/v1/customer/order/list?limit=20&offset=1')->assertOk();
            $listed = collect($list->json('orders'))->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

            $this->assertSame($expected, $listed, 'the list read from ' . ($storefront ?? 'no storefront'));
        }
    }

    public function test_the_wallet_balance_is_one_number_for_every_storefront(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => 123.45]);

        foreach (array_keys(self::STOREFRONTS) as $storefront) {
            $info = $this->asCustomerFrom($storefront, $customer)->getJson('/api/v1/customer/info')->assertOk();

            $this->assertEquals(123.45, $info->json('wallet_balance'), "the balance read from {$storefront}: one balance for every storefront until GCC-15");
        }
    }
}
