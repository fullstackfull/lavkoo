<?php

namespace Tests\Feature\Marketing;

use App\Models\Cart;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Address\AddressCountry;
use App\Services\Retention\AbandonedCartService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsMarketingConsentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Base for the marketing-consent tests.
 *
 * Nothing here switches the feature on: each test says which state it is measuring, so a test that forgets is a test
 * of the shipped default (off), which is the safer way round.
 */
abstract class MarketingConsentTestCase extends TestCase
{
    use BuildsMarketingConsentSchema, RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildMarketingConsentSchema();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The consent gate on, with the policy the repository ships. */
    protected function switchConsentOn(): void
    {
        Config::set('marketing.consent.enabled', true);
    }

    protected function setMarketPolicy(string $market, string $policy): void
    {
        Config::set('marketing.consent.policies.' . $market, $policy);
    }

    /**
     * Writes business settings the way an administrator's screen does, and then forgets what the application
     * remembered about them.
     *
     * getWebConfig caches the WHOLE business_settings table for three hours on its first call, so a setting written
     * after anything has read any setting is invisible without this. Every helper below goes through here.
     *
     * @param array<string, string> $settings
     */
    protected function writeBusinessSettings(array $settings): void
    {
        foreach ($settings as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }

        Cache::flush();
    }

    /** The abandoned-cart feature as an administrator would switch it on, through its own settings. */
    protected function switchAbandonedCartRemindersOn(bool $includeGuests = false): void
    {
        $this->writeBusinessSettings(settings: [
            AbandonedCartService::SETTING_ENABLED => '1',
            AbandonedCartService::SETTING_INCLUDE_GUESTS => $includeGuests ? '1' : '0',
        ]);
    }

    /**
     * The store's default currency, as the install wizard sets it. Needed only by a test that RENDERS a message:
     * webCurrencyConverter reads the default currency and then its row.
     */
    protected function setDefaultCurrency(string $code = 'SAR', string $symbol = 'SAR'): void
    {
        $currencyId = DB::table('currencies')->insertGetId([
            'name' => $code,
            'symbol' => $symbol,
            'code' => $code,
            'exchange_rate' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->writeBusinessSettings(settings: ['system_default_currency' => (string) $currencyId]);
    }

    protected function makeCustomer(string $email, ?string $country = AddressCountry::LEGACY_NAME, string $phone = '+966500000000'): User
    {
        $customer = new User;
        $customer->f_name = 'Test';
        $customer->l_name = 'Customer';
        $customer->email = $email;
        $customer->phone = $phone;
        $customer->save();

        if ($country !== null) {
            $address = new ShippingAddress;
            $address->customer_id = (string) $customer->id;
            $address->is_guest = false;
            $address->contact_person_name = 'Test Customer';
            $address->country = $country;
            $address->city = 'Riyadh';
            $address->save();
        }

        return $customer;
    }

    /** A product the cart filter accepts: admin-owned, active, approved, physical, no brand. */
    protected function makeOrderableProduct(): int
    {
        return DB::table('products')->insertGetId([
            'name' => 'Test product',
            'slug' => 'test-product',
            'added_by' => 'admin',
            'brand_id' => null,
            'product_type' => 'physical',
            'status' => 1,
            'request_status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A cart left alone long enough to be chased, built through the Cart model the service reads. */
    protected function makeAbandonedCart(?int $customerId, string $groupId, bool $isGuest = false, float $price = 120.0): Cart
    {
        $idleSince = Carbon::now()->subHours(AbandonedCartService::DEFAULT_IDLE_HOURS + 2);

        $cart = new Cart;
        $cart->customer_id = $customerId;
        $cart->cart_group_id = $groupId;
        $cart->product_id = $this->makeOrderableProduct();
        $cart->is_guest = $isGuest;
        $cart->name = 'Test product';
        $cart->quantity = 2;
        $cart->price = $price;
        $cart->discount = 0;
        $cart->save();

        // The service selects on `updated_at`, which Eloquent has just set to now.
        Cart::where('id', $cart->id)->update(['created_at' => $idleSince, 'updated_at' => $idleSince]);

        return $cart->refresh();
    }
}
