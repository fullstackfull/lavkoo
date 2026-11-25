<?php

namespace Tests\Feature\Marketing;

use App\Http\Controllers\Admin\CartMarketingController;
use App\Models\Admin;
use App\Models\Cart;
use App\Services\Notification\CartMarketingMarkets;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * I1-04: the WhatsApp cart-marketing screen shows the carts of one market, and sends to that market only.
 *
 * The screen listed every registered customer's cart on the platform and offered one button that messaged whoever was ticked. For one market
 * that is right; for six it is wrong in both directions -- a Kuwaiti operator is shown Saudi carts whose totals are in another currency and
 * whose products their market does not sell, and a template written for Kuwait reaches a Saudi customer who cannot act on it.
 *
 * **Both halves are asserted, and that is the point of the test rather than a thoroughness flourish.** The form carries customer ids. A list
 * narrowed to Kuwait over a send that is not narrowed is a screen that shows one thing and does another, and the id the list never showed is
 * one hand-written POST away. So the send is given the same market the list was given, and the test posts an id the list refused to show.
 *
 * Each refusal has its unrestricted twin, because a refusal on its own cannot tell a guard from a broken fixture: the same customer, with the
 * chooser set to their OWN market, is refused for a different reason entirely (no Chatwoot template), which is how we know the market check
 * is what stopped the first one.
 */
class CartMarketingMarketTest extends TestCase
{
    private const SAUDI_CUSTOMER = 11;

    private const KUWAITI_CUSTOMER = 22;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.mode' => 'live', 'marketing.consent.enabled' => false]);
        Http::fake(['*' => Http::response(['message_templates' => []], 200)]);
        $this->buildTheCartWorld();
        $this->buildTheAdmins();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        StorefrontCatalog::flush();
        $this->signInAsAdmin(1);
    }

    /** The first test of the switch: with storefronts off the screen is the one it has always been. */
    public function test_while_storefronts_are_off_the_list_is_every_customers_cart(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        StorefrontCatalog::flush();

        $this->assertEqualsCanonicalizing([self::SAUDI_CUSTOMER, self::KUWAITI_CUSTOMER], $this->listedCustomers(market: 'KW'));
        $this->assertFalse(app(CartMarketingMarkets::class)->applies());
    }

    public function test_choosing_a_market_lists_only_the_carts_of_that_market(): void
    {
        $this->assertSame([self::SAUDI_CUSTOMER], $this->listedCustomers(market: 'SA'));

        // Kuwait's cart is NOT shown, and the market chooser is only half the reason: Kuwait exists and is configured, but it may not sell
        // yet, so I1-07's marketing stop withholds a message that would invite a purchase nobody can take. The narrowing's own half is
        // proved on the service below, where the selling gate is not in the way.
        $this->assertSame([], $this->listedCustomers(market: 'KW'));
    }

    /** The narrowing in isolation, so the list test above cannot be passing for the selling gate's reason instead of this one. */
    public function test_the_narrowing_keeps_the_carts_of_the_named_market_and_drops_the_others(): void
    {
        $carts = Cart::query()->where('is_guest', 0)->get();
        $markets = app(CartMarketingMarkets::class);

        $this->assertSame([self::SAUDI_CUSTOMER], $this->customerIdsOf($markets->within($carts, ['SA'])));
        $this->assertSame([self::KUWAITI_CUSTOMER], $this->customerIdsOf($markets->within($carts, ['KW'])));
        $this->assertEqualsCanonicalizing(
            [self::SAUDI_CUSTOMER, self::KUWAITI_CUSTOMER],
            $this->customerIdsOf($markets->within($carts, null)),
            'narrowing nothing dropped a cart',
        );
    }

    public function test_an_unrestricted_operator_who_chooses_no_market_is_narrowed_by_nothing(): void
    {
        $this->assertNull(app(CartMarketingMarkets::class)->inForce(null));
        $this->assertTrue(app(CartMarketingMarkets::class)->mayChooseEveryMarket());
    }

    public function test_an_operator_restricted_to_saudi_arabia_sees_its_carts_without_choosing_anything(): void
    {
        $this->restrictTo(admin: 2, countries: ['SA']);
        $this->signInAsAdmin(2);

        $this->assertSame([self::SAUDI_CUSTOMER], $this->listedCustomers(market: null));
        $this->assertSame(['SA'], app(CartMarketingMarkets::class)->choices());
        $this->assertSame(['SA'], app(CartMarketingMarkets::class)->inForce(null), 'a restricted operator was narrowed by nothing');
        $this->assertFalse(app(CartMarketingMarkets::class)->mayChooseEveryMarket());
    }

    /** A market that is not theirs is not theirs to choose: the chooser's value cannot widen a scope. */
    public function test_an_operator_restricted_to_kuwait_cannot_list_another_markets_carts(): void
    {
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        $this->assertSame([], $this->listedCustomers(market: 'SA'));
        $this->assertSame(['KW'], app(CartMarketingMarkets::class)->inForce('SA'), 'the form chose a market the scope does not grant');
    }

    /** The send half, with the id the list refused to show. */
    public function test_a_send_for_kuwait_refuses_a_customer_whose_cart_is_saudi(): void
    {
        $error = $this->send(customerId: self::SAUDI_CUSTOMER, market: 'KW');

        $this->assertStringContainsString('not in the market this send is for', $error);
    }

    /** The twin: the same customer, their own market chosen, is stopped by something else entirely. */
    public function test_the_same_customer_is_not_refused_for_the_market_when_their_own_market_is_chosen(): void
    {
        $error = $this->send(customerId: self::SAUDI_CUSTOMER, market: 'SA');

        $this->assertStringNotContainsString('not in the market this send is for', $error);
        $this->assertStringContainsString('WhatsApp template not found', $error);
    }

    public function test_a_restricted_operator_cannot_send_to_another_markets_customer_whatever_the_form_carries(): void
    {
        $this->restrictTo(admin: 2, countries: ['KW']);
        $this->signInAsAdmin(2);

        // Nothing chosen, which for an unrestricted operator means every market: a restricted one must still reach only their own.
        $error = $this->send(customerId: self::SAUDI_CUSTOMER, market: null);

        $this->assertStringContainsString('not in the market this send is for', $error);
    }

    public function test_an_operator_whose_scope_grants_no_market_may_not_send_at_all(): void
    {
        $this->restrictTo(admin: 2, countries: ['ZZ']);
        $this->signInAsAdmin(2);

        $this->assertFalse(app(CartMarketingMarkets::class)->mayMarket());
        $this->assertStringContainsString(
            translate('your_countries_grant_no_market_to_send_cart_marketing_in'),
            $this->send(customerId: self::SAUDI_CUSTOMER, market: null),
        );
    }

    /**
     * A cart group that spans two markets is left out of a narrowed list rather than half-messaged: the message carries the whole cart's
     * total, and a legacy group can hold the rows of two countries (CartManager::cartGroupsSharedWithOtherStorefronts).
     */
    public function test_a_cart_group_that_spans_two_markets_is_not_kept_for_either(): void
    {
        $this->giveCart(customerId: 33, cartGroupId: 'group-mixed', productIds: [1, 2]);

        $carts = Cart::query()->where('cart_group_id', 'group-mixed')->get();
        $markets = app(CartMarketingMarkets::class);

        $this->assertSame([], $this->customerIdsOf($markets->within($carts, ['SA'])));
        $this->assertSame([], $this->customerIdsOf($markets->within($carts, ['KW'])));
        $this->assertSame([33], $this->customerIdsOf($markets->within($carts, ['SA', 'KW'])), 'a group of two markets was dropped from a list that holds both');
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @param \Illuminate\Support\Collection<int, Cart> $carts
     * @return list<int>
     */
    private function customerIdsOf(\Illuminate\Support\Collection $carts): array
    {
        return $carts->pluck('customer_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
    }

    /** @return list<int> the customer ids the screen would show */
    private function listedCustomers(?string $market): array
    {
        $view = app(CartMarketingController::class)->index(
            Request::create(uri: '/admin/integrations/cart-marketing', parameters: $market === null ? [] : ['market' => $market]),
        );

        return array_map(static fn (array $row): int => (int) $row['customer_id'], $view->getData()['customers']);
    }

    /** The message the send came back with. */
    private function send(int $customerId, ?string $market): string
    {
        $response = app(CartMarketingController::class)->send(Request::create(
            uri: '/admin/integrations/cart-marketing/send',
            method: 'POST',
            parameters: array_filter([
                'mapping_id' => 1,
                'customer_ids' => [$customerId],
                'market' => $market,
            ], static fn ($value): bool => $value !== null),
        ));

        $session = $response->getSession()?->get('error') ?? $response->getSession()?->get('success');

        return (string) $session;
    }

    private function buildTheCartWorld(): void
    {
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('users');
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('products');
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Saudi lamp', StorefrontCatalog::COLUMN => 'SA'],
            ['id' => 2, 'name' => 'Kuwaiti lamp', StorefrontCatalog::COLUMN => 'KW'],
        ]);

        Schema::dropIfExists('carts');
        Schema::create('carts', static function (Blueprint $table): void {
            $table->id();
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->decimal('price', 24, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        // Chatwoot reads its account from here when the controller resolves the service; without the table the send is a query error
        // before it reaches the market check, which would have hidden the very thing this test is about.
        Schema::dropIfExists('integration_settings');
        Schema::create('integration_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('base_url')->nullable();
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        // A complete account, because ChatwootService refuses an incomplete one IN ITS CONSTRUCTOR, which the controller resolves before
        // it reaches any customer: without this the send fails for the wrong reason and proves nothing about markets.
        DB::table('integration_settings')->insert([
            'provider' => 'chatwoot',
            'base_url' => 'https://chatwoot.example.test',
            'credentials' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['api_access_token' => 'test-token'])),
            'settings' => json_encode(['account_id' => 1, 'inbox_id' => 2]),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::dropIfExists('notification_template_mappings');
        Schema::create('notification_template_mappings', static function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('event')->nullable();
            $table->string('template_name')->nullable();
            $table->string('template_language')->nullable();
            $table->string('template_category')->nullable();
            $table->json('variable_mapping')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        DB::table('notification_template_mappings')->insert([
            'id' => 1, 'provider' => 'chatwoot', 'event' => 'cart_marketing',
            'template_name' => 'cart_reminder', 'template_language' => 'ar', 'template_category' => 'MARKETING',
            'variable_mapping' => json_encode([]), 'enabled' => true,
        ]);

        DB::table('users')->insert([
            ['id' => self::SAUDI_CUSTOMER, 'f_name' => 'Saudi', 'l_name' => 'Shopper', 'phone' => '+966500000011'],
            ['id' => self::KUWAITI_CUSTOMER, 'f_name' => 'Kuwaiti', 'l_name' => 'Shopper', 'phone' => '+965500000022'],
            ['id' => 33, 'f_name' => 'Mixed', 'l_name' => 'Cart', 'phone' => '+966500000033'],
        ]);
        $this->giveCart(customerId: self::SAUDI_CUSTOMER, cartGroupId: 'group-sa', productIds: [1]);
        $this->giveCart(customerId: self::KUWAITI_CUSTOMER, cartGroupId: 'group-kw', productIds: [2]);
    }

    /** @param list<int> $productIds */
    private function giveCart(int $customerId, string $cartGroupId, array $productIds): void
    {
        foreach ($productIds as $productId) {
            DB::table('carts')->insert([
                'cart_group_id' => $cartGroupId,
                'customer_id' => $customerId,
                'product_id' => $productId,
                'is_guest' => 0,
                'price' => 10,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function buildTheAdmins(): void
    {
        Schema::dropIfExists('admins');
        Schema::dropIfExists('admin_roles');
        Schema::create('admin_roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        Schema::create('admins', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        DB::table('admin_roles')->insert([
            ['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1],
            ['id' => 2, 'name' => 'Marketing', 'module_access' => json_encode(['marketing']), 'status' => 1],
        ]);
        DB::table('admins')->insert([
            ['id' => 1, 'name' => 'Master', 'email' => 'master@example.test', 'admin_role_id' => 1],
            ['id' => 2, 'name' => 'Marketing', 'email' => 'marketing@example.test', 'admin_role_id' => 2],
        ]);

        Schema::dropIfExists(StaffCountryScope::TABLE);
        Schema::create(StaffCountryScope::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('country', 2);
            $table->timestamps();
        });
        StaffCountryScope::forgetTheSchema();
    }

    /** @param list<string> $countries */
    private function restrictTo(int $admin, array $countries): void
    {
        config(['gcc.staff.country_scopes' => true]);

        foreach ($countries as $country) {
            DB::table(StaffCountryScope::TABLE)->insert(['admin_id' => $admin, 'country' => $country, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function signInAsAdmin(int $admin): void
    {
        $this->actingAs(Admin::query()->findOrFail($admin), 'admin');
    }
}
