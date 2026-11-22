<?php

namespace Tests\Feature\Storefront;

use App\Exceptions\StorefrontPaymentMethodNotOfferedException;
use App\Http\Middleware\EnsureStorefrontOffersPaymentMethod;
use App\Services\Storefront\StorefrontPaymentMethods;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Requirement D2-V2: cash on delivery, digital payment and offline payment can each be switched off for one market without the others. The platform's three payment
 * settings stay what they are; a country lists what its own storefront switches off (gcc.storefronts.payment_methods.switched_off), and getWebConfig() tells every
 * reader of a setting "switched off" in that storefront. A storefront can only switch off, and with the switch off every answer is the platform's own, untouched.
 */
class StorefrontPaymentMethodsTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RestoresLanguageFiles;

    private const ON = ['status' => 1];

    private const OFF = ['status' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->createSettingsTable();
        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->storeSetting($method, '{"status":1}');
        }

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.payment_methods.enabled' => true,
            'gcc.storefronts.payment_methods.switched_off' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('business_settings');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function createSettingsTable(): void
    {
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    private function storeSetting(string $type, ?string $value): void
    {
        DB::table('business_settings')->where('type', $type)->delete();
        if ($value !== null) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        }
        clearWebConfigCacheKeys();
    }

    /** @param array<string, list<string>|string> $byCountry */
    private function switchOff(array $byCountry): void
    {
        config(['gcc.storefronts.payment_methods.switched_off' => $byCountry]);
    }

    private function paymentMethods(): StorefrontPaymentMethods
    {
        return app(StorefrontPaymentMethods::class);
    }

    /** Serves the callback as a customer of the storefront does. */
    private function inStorefront(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }

    /** What a reader of the setting is told in a storefront. */
    private function settingIn(string $country, string $method): mixed
    {
        return $this->inStorefront($country, fn () => getWebConfig(name: $method));
    }

    /** @return array<string, array{string}> */
    public static function methods(): array
    {
        return [
            'cash on delivery' => [StorefrontPaymentMethods::CASH_ON_DELIVERY],
            'digital payment' => [StorefrontPaymentMethods::DIGITAL_PAYMENT],
            'offline payment' => [StorefrontPaymentMethods::OFFLINE_PAYMENT],
        ];
    }

    /** @return list<list<string>> every list a country can give: nothing, each method alone, each pair, all three */
    private static function everyList(): array
    {
        $lists = [[]];
        foreach (StorefrontPaymentMethods::METHODS as $method) {
            foreach ($lists as $list) {
                $lists[] = [...$list, $method];
            }
        }

        return $lists;
    }

    // ---- one market, one method --------------------------------------------------------------------------------------------------

    #[DataProvider('methods')]
    public function test_a_storefront_that_switched_a_method_off_reads_it_as_switched_off(string $method): void
    {
        $this->switchOff(['KW' => [$method]]);

        $this->assertSame(self::OFF, $this->settingIn('KW', $method));
        $this->assertTrue($this->inStorefront('KW', fn () => $this->paymentMethods()->isSwitchedOff(method: $method)));
    }

    #[DataProvider('methods')]
    public function test_the_other_storefronts_still_read_it_as_the_platform_has_it(string $method): void
    {
        $this->switchOff(['KW' => [$method]]);

        foreach (['SA', 'AE'] as $country) {
            $this->assertSame(self::ON, $this->settingIn($country, $method), $country);
            $this->assertFalse($this->inStorefront($country, fn () => $this->paymentMethods()->isSwitchedOff(method: $method)), $country);
        }
    }

    #[DataProvider('methods')]
    public function test_the_other_two_methods_stay_on_in_the_storefront_that_switched_one_off(string $method): void
    {
        $this->switchOff(['KW' => [$method]]);

        foreach (array_diff(StorefrontPaymentMethods::METHODS, [$method]) as $other) {
            $this->assertSame(self::ON, $this->settingIn('KW', $other), $other);
        }
    }

    public function test_every_list_a_country_can_give_switches_off_exactly_what_it_names(): void
    {
        foreach (self::everyList() as $list) {
            $this->switchOff(['KW' => $list]);

            foreach (StorefrontPaymentMethods::METHODS as $method) {
                $this->assertSame(in_array($method, $list, true) ? self::OFF : self::ON, $this->settingIn('KW', $method), json_encode($list) . ' ' . $method);
                $this->assertSame(self::ON, $this->settingIn('SA', $method), 'the default storefront, whatever Kuwait lists');
            }
        }
    }

    public function test_the_default_storefront_can_switch_a_method_off_for_itself_while_another_keeps_it(): void
    {
        $this->switchOff(['SA' => [StorefrontPaymentMethods::OFFLINE_PAYMENT]]);

        $this->assertSame(self::OFF, $this->settingIn('SA', StorefrontPaymentMethods::OFFLINE_PAYMENT));
        $this->assertSame(self::ON, $this->settingIn('KW', StorefrontPaymentMethods::OFFLINE_PAYMENT));
        $this->assertSame(self::ON, $this->settingIn('SA', StorefrontPaymentMethods::CASH_ON_DELIVERY));
    }

    public function test_a_lower_case_country_and_a_single_name_are_understood(): void
    {
        $this->switchOff(['kw' => StorefrontPaymentMethods::OFFLINE_PAYMENT]);

        $this->assertSame(self::OFF, $this->settingIn('KW', StorefrontPaymentMethods::OFFLINE_PAYMENT));
        $this->assertSame(self::ON, $this->settingIn('KW', StorefrontPaymentMethods::CASH_ON_DELIVERY));
    }

    // ---- a storefront can only switch off ----------------------------------------------------------------------------------------

    #[DataProvider('methods')]
    public function test_no_list_can_offer_a_method_the_platform_has_off(string $method): void
    {
        $this->storeSetting($method, '{"status":0}');

        foreach (self::everyList() as $list) {
            $this->switchOff(['KW' => $list, 'AE' => array_values(array_diff(StorefrontPaymentMethods::METHODS, $list))]);

            foreach (['SA', 'AE', 'KW'] as $country) {
                $this->assertSame(self::OFF, $this->settingIn($country, $method), $country . ' ' . json_encode($list));
            }
        }
    }

    #[DataProvider('methods')]
    public function test_a_platform_that_holds_no_setting_for_a_method_is_never_given_one_by_a_storefront(string $method): void
    {
        $this->storeSetting($method, null);
        $this->switchOff(['KW' => [$method]]);

        $this->assertNull($this->settingIn('KW', $method));
        $this->assertNull($this->settingIn('SA', $method));
    }

    public function test_what_else_a_setting_holds_is_kept_when_a_storefront_switches_it_off(): void
    {
        $this->storeSetting(StorefrontPaymentMethods::DIGITAL_PAYMENT, '{"status":"1","note":"cards and wallets"}');
        $this->switchOff(['KW' => [StorefrontPaymentMethods::DIGITAL_PAYMENT]]);

        $this->assertSame(['status' => 0, 'note' => 'cards and wallets'], $this->settingIn('KW', StorefrontPaymentMethods::DIGITAL_PAYMENT));
        $this->assertSame(['status' => '1', 'note' => 'cards and wallets'], $this->settingIn('SA', StorefrontPaymentMethods::DIGITAL_PAYMENT));
    }

    // ---- everything is as before while nothing is switched off -------------------------------------------------------------------

    /** @return array<string, array{?string, mixed}> what the settings table holds and what getWebConfig() has always answered for it */
    public static function storedSettings(): array
    {
        return [
            'on, as the settings screen saves it' => ['{"status":"1"}', ['status' => '1']],
            'on, as a number' => ['{"status":1}', ['status' => 1]],
            'off, as the settings screen saves it' => ['{"status":0}', ['status' => 0]],
            'off, as text' => ['{"status":"0"}', ['status' => '0']],
            'no row at all' => [null, null],
        ];
    }

    #[DataProvider('storedSettings')]
    public function test_while_the_switch_is_off_every_storefront_reads_the_platform_setting_untouched(?string $stored, mixed $asAlways): void
    {
        config(['gcc.storefronts.payment_methods.enabled' => false]);
        $this->switchOff(['SA' => StorefrontPaymentMethods::METHODS, 'KW' => StorefrontPaymentMethods::METHODS]);

        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->storeSetting($method, $stored);

            $this->assertSame($asAlways, $this->settingIn('SA', $method), "{$method} in the default storefront");
            $this->assertSame($asAlways, $this->settingIn('KW', $method), "{$method} in Kuwait");
            $this->assertSame($asAlways, getWebConfig(name: $method), "{$method} outside a storefront");
            $this->assertFalse($this->inStorefront('KW', fn () => $this->paymentMethods()->isSwitchedOff(method: $method)));
        }
    }

    #[DataProvider('storedSettings')]
    public function test_a_country_that_lists_nothing_reads_the_platform_setting_untouched(?string $stored, mixed $asAlways): void
    {
        $this->switchOff(['KW' => StorefrontPaymentMethods::METHODS]);

        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->storeSetting($method, $stored);

            $this->assertSame($asAlways, $this->settingIn('SA', $method), "{$method} in the default storefront");
            $this->assertSame($asAlways, $this->settingIn('AE', $method), "{$method} in the Emirates");
        }
    }

    public function test_while_storefronts_are_off_as_a_whole_nothing_is_switched_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->switchOff(['SA' => StorefrontPaymentMethods::METHODS, 'KW' => StorefrontPaymentMethods::METHODS]);

        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->assertSame(self::ON, $this->settingIn('KW', $method));
            $this->assertSame(self::ON, getWebConfig(name: $method));
        }
    }

    public function test_a_country_that_is_not_active_is_served_as_the_default_storefront_and_its_list_is_not_used(): void
    {
        $this->switchOff(['BH' => StorefrontPaymentMethods::METHODS]);

        $this->assertSame(self::ON, $this->settingIn('BH', StorefrontPaymentMethods::CASH_ON_DELIVERY));
    }

    public function test_the_settings_a_storefront_cannot_switch_off_are_not_touched(): void
    {
        $this->storeSetting('order_verification', '{"status":1}');
        $this->switchOff(['KW' => StorefrontPaymentMethods::METHODS]);

        $this->assertSame(self::ON, $this->settingIn('KW', 'order_verification'));
    }

    public function test_reading_a_setting_in_a_storefront_never_changes_the_platform_row(): void
    {
        $this->switchOff(['KW' => StorefrontPaymentMethods::METHODS]);

        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->settingIn('KW', $method);

            $this->assertSame('{"status":1}', DB::table('business_settings')->where('type', $method)->value('value'));
            $this->assertSame(self::ON, getWebConfig(name: $method), 'and the next reader outside a storefront still gets the platform setting');
        }
    }

    // ---- the requests that are served from no storefront -------------------------------------------------------------------------

    public function test_a_queue_a_console_command_and_a_test_read_the_platform_setting(): void
    {
        $this->switchOff(['SA' => StorefrontPaymentMethods::METHODS, 'AE' => StorefrontPaymentMethods::METHODS, 'KW' => StorefrontPaymentMethods::METHODS]);

        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->assertSame(self::ON, getWebConfig(name: $method));
        }
    }

    /** @return array<string, array{string}> */
    public static function panelAddresses(): array
    {
        return [
            'the payment settings screen of the admin panel' => ['/admin/third-party/payment-method'],
            'the business settings of the admin panel' => ['/admin/business-settings/web-config'],
            'the vendor panel' => ['/vendor/orders/list/all'],
            'the seller center' => ['/seller/orders'],
            'the seller app' => ['/api/v3/seller/seller-info'],
            'the delivery app' => ['/api/v2/delivery-man/info'],
        ];
    }

    #[DataProvider('panelAddresses')]
    public function test_a_panel_reads_the_platform_setting_whatever_any_storefront_switched_off(string $address): void
    {
        $this->switchOff(['SA' => StorefrontPaymentMethods::METHODS, 'AE' => StorefrontPaymentMethods::METHODS, 'KW' => StorefrontPaymentMethods::METHODS]);
        $this->serveAsHttpRequest($address, ['X-Storefront' => 'KW']);

        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->assertSame(self::ON, getWebConfig(name: $method), $method);
        }
    }

    public function test_a_request_of_an_app_that_no_middleware_bound_is_judged_by_the_storefront_it_names(): void
    {
        $this->switchOff(['KW' => [StorefrontPaymentMethods::OFFLINE_PAYMENT], 'SA' => [StorefrontPaymentMethods::CASH_ON_DELIVERY]]);

        $this->serveAsHttpRequest('/api/v1/config', ['X-Storefront' => 'KW']);
        $this->assertSame(self::OFF, getWebConfig(name: StorefrontPaymentMethods::OFFLINE_PAYMENT));
        $this->assertSame(self::ON, getWebConfig(name: StorefrontPaymentMethods::CASH_ON_DELIVERY));

        $this->serveAsHttpRequest('/api/v1/config');
        $this->assertSame(self::ON, getWebConfig(name: StorefrontPaymentMethods::OFFLINE_PAYMENT));
        $this->assertSame(self::OFF, getWebConfig(name: StorefrontPaymentMethods::CASH_ON_DELIVERY), 'a request that names no storefront is the default storefront');
    }

    // ---- the platform's own switch -----------------------------------------------------------------------------------------------

    #[DataProvider('methods')]
    public function test_the_platform_setting_can_be_asked_for_inside_a_storefront_that_switched_the_method_off(string $method): void
    {
        $this->switchOff(['KW' => [$method]]);

        $answers = $this->inStorefront('KW', fn () => [
            $this->paymentMethods()->platformSetting(method: $method),
            getWebConfig(name: $method),
        ]);

        $this->assertSame([self::ON, self::OFF], $answers, 'the platform setting, and after it the storefront again');
    }

    public function test_the_storefront_answers_again_after_reading_the_platform_setting_failed(): void
    {
        $this->switchOff(['KW' => [StorefrontPaymentMethods::CASH_ON_DELIVERY]]);
        Schema::drop('business_settings');
        clearWebConfigCacheKeys();

        try {
            $this->inStorefront('KW', fn () => $this->paymentMethods()->platformSetting(method: StorefrontPaymentMethods::CASH_ON_DELIVERY));
            $this->fail('Reading a table that is not there has to fail.');
        } catch (QueryException) {
            $this->createSettingsTable();
            $this->storeSetting(StorefrontPaymentMethods::CASH_ON_DELIVERY, '{"status":1}');
        }

        $this->assertSame(self::OFF, $this->settingIn('KW', StorefrontPaymentMethods::CASH_ON_DELIVERY));
    }

    // ---- a list that cannot be read ----------------------------------------------------------------------------------------------

    public function test_a_name_that_is_not_one_of_the_three_fails_loudly_and_only_for_the_storefront_that_lists_it(): void
    {
        $this->switchOff(['KW' => ['offline']]);

        $this->assertSame(self::ON, $this->settingIn('SA', StorefrontPaymentMethods::OFFLINE_PAYMENT), 'the mistake of one country never reaches another storefront');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Storefront [KW] switches off payment methods that do not exist: offline.');
        $this->settingIn('KW', StorefrontPaymentMethods::OFFLINE_PAYMENT);
    }

    public function test_a_list_cannot_say_on(): void
    {
        $this->storeSetting(StorefrontPaymentMethods::CASH_ON_DELIVERY, '{"status":0}');
        $this->switchOff(['KW' => [StorefrontPaymentMethods::CASH_ON_DELIVERY => true]]);

        $this->expectException(InvalidArgumentException::class);
        $this->settingIn('KW', StorefrontPaymentMethods::CASH_ON_DELIVERY);
    }

    public function test_asking_about_a_way_to_pay_that_is_not_one_of_the_three_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->paymentMethods()->isSwitchedOff(method: 'pay_by_wallet');
    }

    // ---- the refusal -------------------------------------------------------------------------------------------------------------

    #[DataProvider('methods')]
    public function test_a_method_the_storefront_switched_off_is_refused_there_and_nowhere_else(string $method): void
    {
        $this->switchOff(['KW' => [$method]]);
        $this->inStorefront('SA', fn () => $this->paymentMethods()->assertNotSwitchedOff(method: $method));
        $this->paymentMethods()->assertNotSwitchedOff(method: $method);

        try {
            $this->inStorefront('KW', fn () => $this->paymentMethods()->assertNotSwitchedOff(method: $method));
            $this->fail('Kuwait switched the method off.');
        } catch (StorefrontPaymentMethodNotOfferedException $refusal) {
            $this->assertSame([$method, 'KW'], [$refusal->method, $refusal->countryCode]);
            $this->assertSame(['storefront' => 'KW', 'payment_method' => $method], $refusal->context());
            $this->assertSame(translate('this_payment_method_is_not_available_in_this_storefront'), $refusal->getMessage());
        }
    }

    #[DataProvider('methods')]
    public function test_a_method_the_platform_has_off_is_not_refused_by_a_storefront_that_did_not_switch_it_off(string $method): void
    {
        $this->storeSetting($method, '{"status":0}');
        $this->switchOff(['KW' => array_values(array_diff(StorefrontPaymentMethods::METHODS, [$method]))]);
        $this->expectNotToPerformAssertions();

        $this->inStorefront('KW', fn () => $this->paymentMethods()->assertNotSwitchedOff(method: $method));
    }

    public function test_the_middleware_refuses_before_the_handler_is_reached(): void
    {
        $this->switchOff(['KW' => [StorefrontPaymentMethods::OFFLINE_PAYMENT]]);
        $handlerReached = 0;
        $next = function () use (&$handlerReached) {
            $handlerReached++;

            return response('handler');
        };
        $request = Request::create('/api/v1/customer/order/place-by-offline-payment', 'POST');
        $middleware = new EnsureStorefrontOffersPaymentMethod();

        $this->inStorefront('SA', fn () => $middleware->handle($request, $next, StorefrontPaymentMethods::OFFLINE_PAYMENT));
        $this->inStorefront('KW', fn () => $middleware->handle($request, $next, StorefrontPaymentMethods::CASH_ON_DELIVERY));
        $this->assertSame(2, $handlerReached);

        try {
            $this->inStorefront('KW', fn () => $middleware->handle($request, $next, StorefrontPaymentMethods::OFFLINE_PAYMENT));
            $this->fail('Kuwait switched offline payment off.');
        } catch (StorefrontPaymentMethodNotOfferedException) {
            $this->assertSame(2, $handlerReached, 'the handler is never reached');
        }
    }

    public function test_an_app_is_answered_with_422_a_stable_code_and_the_message(): void
    {
        $refusal = new StorefrontPaymentMethodNotOfferedException(method: StorefrontPaymentMethods::OFFLINE_PAYMENT, countryCode: 'KW');

        foreach ([
            'an app that asks for JSON' => Request::create('/api/v1/customer/order/place', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
            'an older app that does not say what it accepts' => Request::create('/api/v1/customer/order/place', 'GET'),
        ] as $who => $request) {
            $response = $refusal->render($request);

            $this->assertSame(422, $response->getStatusCode(), $who);
            $this->assertSame(
                ['code' => 'storefront_payment_method_not_offered', 'message' => 'This payment method is not available in this storefront.'],
                $response->getData(true),
                $who,
            );
        }
    }

    public function test_a_browser_is_sent_back_with_the_message_where_the_page_shows_it(): void
    {
        $refusal = new StorefrontPaymentMethodNotOfferedException(method: StorefrontPaymentMethods::CASH_ON_DELIVERY, countryCode: 'KW');

        $response = $refusal->render(Request::create('/checkout-complete', 'POST'));

        $this->assertTrue($response->isRedirect());
        $this->assertSame('This payment method is not available in this storefront.', session('error'));
        $this->assertStringContainsString('This payment method is not available in this storefront.', json_encode(session('toastr::messages')));
    }

    // ---- the words ---------------------------------------------------------------------------------------------------------------

    public function test_the_message_exists_in_english_and_in_arabic(): void
    {
        $key = 'this_payment_method_is_not_available_in_this_storefront';
        $placeholder = 'This payment method is not available in this storefront';

        $this->assertSame('This payment method is not available in this storefront.', (include base_path('resources/lang/en/new-messages.php'))[$key]);
        foreach (['sa', 'ae'] as $arabicFile) {
            $text = (include base_path("resources/lang/{$arabicFile}/new-messages.php"))[$key] ?? null;

            $this->assertNotNull($text, $arabicFile);
            $this->assertNotSame($placeholder, $text, $arabicFile);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, $arabicFile);
        }
    }

    public function test_the_message_follows_the_language_of_the_page(): void
    {
        session(['local' => 'sa']);
        $arabic = (new StorefrontPaymentMethodNotOfferedException(method: StorefrontPaymentMethods::CASH_ON_DELIVERY, countryCode: 'KW'))->getMessage();

        session(['local' => 'en']);
        $english = (new StorefrontPaymentMethodNotOfferedException(method: StorefrontPaymentMethods::CASH_ON_DELIVERY, countryCode: 'KW'))->getMessage();

        $this->assertSame('طريقة الدفع هذه غير متاحة في هذا المتجر.', $arabic);
        $this->assertSame('This payment method is not available in this storefront.', $english);
    }

    // ---- what ships --------------------------------------------------------------------------------------------------------------

    public function test_the_shipped_configuration_is_switched_off_and_lists_only_what_exists(): void
    {
        $shipped = (require config_path('gcc.php'))['storefronts'];

        $this->assertFalse($shipped['payment_methods']['enabled']);
        foreach ($shipped['payment_methods']['switched_off'] as $country => $methods) {
            $this->assertArrayHasKey($country, $shipped['countries'], "[{$country}] is not a configured storefront.");
            $this->assertSame([], array_diff((array) $methods, StorefrontPaymentMethods::METHODS), "[{$country}] lists a way to pay that does not exist.");
        }
    }
}
