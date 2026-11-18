<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\EnsureWalletIsOpenInStorefront;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;

/**
 * GCC-05, slice 5b: a customer has one wallet balance, kept in the platform's money, until the balances are per market (GCC-15). A Kuwaiti order of 30 dinars paid
 * from it would take 30 riyals, and a top-up in dinars would add dinars to a riyal balance, so while a storefront trades in its own currency the wallet is closed there:
 * the two settings that open it answer 0 through the seam, and the two checkout handlers that compare only the balance sit behind a middleware that refuses them.
 * The default storefront, a request with no storefront and every storefront while the switch is off are untouched.
 */
class StorefrontWalletClosedTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;

    private const BY_WALLET = '/api/v1/customer/order/place-by-wallet';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->setSetting('wallet_status', '1');
        $this->setSetting('add_funds_to_wallet', '1');
    }

    /** @return array<string, array{string, int}> the storefronts that trade in their own currency and the product each sells */
    public static function storefrontsWithTheirOwnCurrencyAndProduct(): array
    {
        return ['United Arab Emirates' => ['AE', 2], 'Kuwait' => ['KW', 3], 'Qatar' => ['QA', 4], 'Bahrain' => ['BH', 5], 'Oman' => ['OM', 6]];
    }

    private function wallet(string $country, string $setting): mixed
    {
        return $this->inStorefront($country, fn () => getWebConfig(name: $setting));
    }

    // ---- the two settings that open the wallet -----------------------------------------------------------------------------------

    #[DataProvider('storefrontsWithTheirOwnCurrencyAndProduct')]
    public function test_a_storefront_with_its_own_currency_has_its_wallet_and_top_up_closed(string $country): void
    {
        $this->assertSame('0', $this->wallet($country, 'wallet_status'));
        $this->assertSame('0', $this->wallet($country, 'add_funds_to_wallet'));
    }

    public function test_the_default_storefront_and_a_request_with_no_storefront_read_the_platform_settings(): void
    {
        foreach (['wallet_status', 'add_funds_to_wallet'] as $setting) {
            $this->assertSame('1', $this->wallet('SA', $setting), 'the default storefront');
            $this->assertSame('1', getWebConfig(name: $setting), 'a panel, a queue and the console');
        }
    }

    public function test_the_platform_rows_and_their_cache_are_never_changed_by_a_storefront_reading_them(): void
    {
        $this->wallet('KW', 'wallet_status');

        $this->assertSame('1', DB::table('business_settings')->where('type', 'wallet_status')->value('value'));
        $this->assertSame('1', getWebConfig(name: 'wallet_status'));
    }

    public function test_nothing_is_closed_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertSame('1', $this->wallet('KW', 'wallet_status'));
        $this->assertSame('1', $this->wallet('KW', 'add_funds_to_wallet'));
    }

    // ---- the checkout handlers ---------------------------------------------------------------------------------------------------

    #[DataProvider('storefrontsWithTheirOwnCurrencyAndProduct')]
    public function test_an_order_of_a_storefront_with_its_own_currency_cannot_be_paid_from_the_wallet(string $country, int $productId): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => 1000]);
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);

        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson(self::BY_WALLET)
            ->assertStatus(403)
            ->assertJsonPath('message', translate('the_wallet_is_not_available_in_the_currency_of_this_storefront'));

        $this->assertSame(0, DB::table('orders')->count(), 'no order is saved');
        $this->assertEquals(1000, $customer->fresh()->wallet_balance, 'and not a coin is taken');
    }

    public function test_the_default_storefront_is_not_refused_by_the_middleware(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        $customer = $this->makeCustomer(['wallet_balance' => 0]);
        $this->fillTheCartFrom(customer: $customer, country: 'SA', productId: 1);

        $this->asCustomer($customer)->withHeader('X-Storefront', 'SA')->getJson(self::BY_WALLET)
            ->assertStatus(403)
            ->assertJsonPath('message', 'inefficient balance in your wallet to pay for this order');
    }

    public function test_while_the_switch_is_off_the_wallet_handler_answers_what_it_always_did(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        config(['gcc.storefronts.currency.enabled' => false]);
        $customer = $this->makeCustomer(['wallet_balance' => 0]);
        $this->fillTheCartFrom(customer: $customer, country: 'KW', productId: 3);

        $this->asCustomer($customer)->withHeader('X-Storefront', 'KW')->getJson(self::BY_WALLET)
            ->assertStatus(403)
            ->assertJsonPath('message', 'inefficient balance in your wallet to pay for this order');
    }

    // ---- the middleware itself, and the routes that carry it ---------------------------------------------------------------------

    public function test_a_web_request_is_sent_back_with_the_message_and_never_reaches_the_handler(): void
    {
        $handlerReached = false;
        $next = function () use (&$handlerReached) {
            $handlerReached = true;

            return response('handler');
        };

        $inKuwait = $this->inStorefront('KW', fn () => (new EnsureWalletIsOpenInStorefront())->handle(Request::create('/checkout-complete-wallet', 'POST'), $next));
        $this->assertInstanceOf(RedirectResponse::class, $inKuwait);
        $this->assertFalse($handlerReached);

        $inSaudi = $this->inStorefront('SA', fn () => (new EnsureWalletIsOpenInStorefront())->handle(Request::create('/checkout-complete-wallet', 'POST'), $next));
        $this->assertSame('handler', $inSaudi->getContent());
        $this->assertTrue($handlerReached);
    }

    public function test_both_checkout_wallet_payments_carry_the_middleware_after_the_storefront_ones(): void
    {
        $api = Route::getRoutes()->match(Request::create(self::BY_WALLET, 'GET'));
        $web = Route::getRoutes()->getByName('checkout-complete-wallet');

        foreach ([$api, $web] as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('storefront.wallet', $middleware, $route->uri());
            $this->assertGreaterThan(array_search('storefront.sellable', $middleware, true), array_search('storefront.wallet', $middleware, true), 'it judges the storefront the request was bound to');
        }
        $this->assertSame(EnsureWalletIsOpenInStorefront::class, app('router')->getMiddleware()['storefront.wallet']);
    }
}
