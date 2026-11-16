<?php

namespace Tests\Feature\Storefront;

use App\Models\AbandonedCartReminder;
use App\Services\Retention\AbandonedCartService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * The reminder job runs in the console, where the carts of every country are seen, and its link opens the website, which serves the default
 * storefront unless it is told otherwise. The link carries the storefront the cart was made in, so a cart of another country does not open empty.
 */
class StorefrontRecoveryLinkTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const EMIRATI_GUEST = 4242;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
        Schema::create('abandoned_cart_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('cart_group_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('email')->nullable();
            $table->unsignedInteger('stage')->default(1);
            $table->decimal('cart_value', 16, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->dateTime('recovered_at')->nullable();
            $table->unsignedBigInteger('recovered_order_id')->nullable();
            $table->timestamps();
        });
    }

    private function reminderFor(string $cartGroupId, ?int $customerId = null, bool $isGuest = false): AbandonedCartReminder
    {
        return AbandonedCartReminder::create(['cart_group_id' => $cartGroupId, 'customer_id' => $customerId, 'is_guest' => $isGuest, 'email' => 'shopper@example.test', 'stage' => 1, 'cart_value' => 250, 'item_count' => 1]);
    }

    private function guestRow(int $guestId, int $productId, string $group): void
    {
        DB::table('carts')->insert([
            'customer_id' => $guestId, 'is_guest' => 1, 'cart_group_id' => $group, 'product_id' => $productId, 'quantity' => 1, 'price' => 250,
            'seller_id' => 1, 'seller_is' => 'admin', 'is_checked' => 1, 'product_type' => 'physical', 'created_at' => now()->subHours(8), 'updated_at' => now()->subHours(8),
        ]);
    }

    private function linkOf(AbandonedCartReminder $reminder): string
    {
        return app(AbandonedCartService::class)->recoveryUrl(reminder: $reminder);
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_the_link_of_a_cart_made_in_another_country_names_that_storefront_and_stays_signed(): void
    {
        $this->switchStorefrontsOn();
        $this->guestRow(self::EMIRATI_GUEST, productId: 2, group: 'guest-ae');
        $reminder = $this->reminderFor('guest-ae', isGuest: true);

        $link = $this->linkOf($reminder);

        $this->assertSame('AE', $this->queryOf($link)['storefront']);
        $this->get($link)->assertRedirect();
        $this->get(str_replace('storefront=AE', 'storefront=SA', $link))->assertForbidden();
    }

    public function test_the_link_of_a_cart_while_storefronts_are_off_is_what_it_always_was(): void
    {
        $this->guestRow(self::EMIRATI_GUEST, productId: 2, group: 'guest-ae');

        $query = $this->queryOf($this->linkOf($this->reminderFor('guest-ae', isGuest: true)));

        $this->assertSame(['expires', 'signature'], collect($query)->except('reminder')->keys()->sort()->values()->all());
        $this->assertArrayNotHasKey('storefront', $query);
    }

    public function test_opening_the_link_of_a_guest_cart_of_another_country_restores_that_guest_and_stays_in_that_storefront(): void
    {
        $this->switchStorefrontsOn();
        $this->guestRow(self::EMIRATI_GUEST, productId: 2, group: 'guest-ae');
        $link = $this->linkOf($this->reminderFor('guest-ae', isGuest: true));

        $response = $this->get($link);

        $this->assertSame(self::EMIRATI_GUEST, session('guest_id'), 'the guest of an Emirati cart is found, which the default storefront cannot see');
        $this->assertSame('AE', $this->queryOf((string) $response->headers->get('Location'))['storefront']);
    }

    public function test_opening_the_link_of_a_customers_cart_leads_to_the_cart_of_the_storefront_it_was_made_in(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->cartRow($customer->id, 2, 250, group: 'group-ae');
        $link = $this->linkOf($this->reminderFor('group-ae', customerId: $customer->id));

        $location = (string) $this->get($link)->assertRedirect()->headers->get('Location');

        $this->assertStringContainsString('/shop-cart', $location);
        $this->assertSame('AE', $this->queryOf($location)['storefront']);
    }

    public function test_opening_the_link_while_storefronts_are_off_redirects_without_a_storefront(): void
    {
        $customer = $this->makeCustomer();
        $this->cartRow($customer->id, 1, 100, group: 'group-sa');
        $link = $this->linkOf($this->reminderFor('group-sa', customerId: $customer->id));

        $location = (string) $this->get($link)->assertRedirect()->headers->get('Location');

        $this->assertStringNotContainsString('storefront=', $location);
    }
}
