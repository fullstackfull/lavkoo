<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontPaymentMethods;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsApiConfigWorld;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * Requirement D2-V2, what an app offers: GET /api/v1/config sends digital_payment, cash_on_delivery and offline_payment, and the customer app shows a way to pay only
 * while its flag says so. The app asks again after a storefront switch and names its storefront (X-Storefront), so the three flags it gets are that storefront's.
 * The controller is not changed: it reads the three settings through getWebConfig(), which is where a storefront's switch-off is known. With nothing switched off the
 * whole answer is, byte for byte, what it was.
 */
class StorefrontPaymentMethodConfigAnswerTest extends ApiAddressTestCase
{
    use BuildsApiConfigWorld;
    use BuildsStorefrontCatalog;

    private const CONFIG = '/api/v1/config';

    private const OFFLINE_PAYMENT_OFFERED = ['name' => 'offline_payment', 'image' => 'http://localhost/assets/back-end/img/pay-offline.png'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildApiConfigWorld();
        $this->switchAGatewayOn();
        foreach (StorefrontPaymentMethods::METHODS as $method) {
            $this->storeSetting($method, '{"status":"1"}');
        }
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.payment_methods.enabled' => true,
            'gcc.storefronts.payment_methods.switched_off' => [],
        ]);
        $this->serveAsHttpRequest(self::CONFIG);
    }

    private function storeSetting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value]);
        clearWebConfigCacheKeys();
    }

    /** @param array<string, list<string>> $byCountry */
    private function switchOff(array $byCountry): void
    {
        config(['gcc.storefronts.payment_methods.switched_off' => $byCountry]);
    }

    /** The answer an app gets when it names the storefront, or names none. */
    private function configOf(?string $country): string
    {
        $response = $country === null ? $this->getJson(self::CONFIG) : $this->getJson(self::CONFIG, ['X-Storefront' => $country]);

        return $response->assertOk()->getContent();
    }

    /** @return array{digital_payment: mixed, cash_on_delivery: mixed, offline_payment: mixed} the three flags of the answer */
    private function flagsOf(?string $country): array
    {
        $config = json_decode($this->configOf($country), true);

        return ['digital_payment' => $config['digital_payment'], 'cash_on_delivery' => $config['cash_on_delivery'], 'offline_payment' => $config['offline_payment']];
    }

    private const EVERYTHING_OFFERED = ['digital_payment' => true, 'cash_on_delivery' => true, 'offline_payment' => self::OFFLINE_PAYMENT_OFFERED];

    /** @return array<string, array{string, mixed}> the way to pay and what its flag says when it is not offered */
    public static function methodsAndTheirFlagWhenOff(): array
    {
        return [
            'cash on delivery' => [StorefrontPaymentMethods::CASH_ON_DELIVERY, false],
            'digital payment' => [StorefrontPaymentMethods::DIGITAL_PAYMENT, false],
            'offline payment' => [StorefrontPaymentMethods::OFFLINE_PAYMENT, null],
        ];
    }

    #[DataProvider('methodsAndTheirFlagWhenOff')]
    public function test_an_app_of_the_storefront_that_switched_a_method_off_is_told_it_is_off_and_the_other_two_are_on(string $method, mixed $whenOff): void
    {
        $this->switchOff(['KW' => [$method]]);

        $this->assertSame(array_replace(self::EVERYTHING_OFFERED, [$method => $whenOff]), $this->flagsOf('KW'));
    }

    #[DataProvider('methodsAndTheirFlagWhenOff')]
    public function test_an_app_of_another_storefront_is_still_told_everything_is_on(string $method): void
    {
        $this->switchOff(['KW' => [$method]]);

        $this->assertSame(self::EVERYTHING_OFFERED, $this->flagsOf('SA'));
        $this->assertSame(self::EVERYTHING_OFFERED, $this->flagsOf('AE'));
        $this->assertSame(self::EVERYTHING_OFFERED, $this->flagsOf(null), 'an app that names no storefront is served the default one');
    }

    #[DataProvider('methodsAndTheirFlagWhenOff')]
    public function test_a_method_the_platform_has_off_is_off_for_every_app_whatever_is_listed(string $method, mixed $whenOff): void
    {
        $this->storeSetting($method, '{"status":0}');
        $this->switchOff(['KW' => array_values(array_diff(StorefrontPaymentMethods::METHODS, [$method])), 'AE' => [$method]]);

        foreach (['SA', 'AE', 'KW', null] as $country) {
            $this->assertSame($whenOff, $this->flagsOf($country)[$method], (string) $country);
        }
    }

    public function test_the_default_storefront_can_be_the_one_that_does_not_offer_a_method(): void
    {
        $this->switchOff(['SA' => [StorefrontPaymentMethods::OFFLINE_PAYMENT]]);

        $this->assertNull($this->flagsOf('SA')['offline_payment']);
        $this->assertNull($this->flagsOf(null)['offline_payment']);
        $this->assertSame(self::OFFLINE_PAYMENT_OFFERED, $this->flagsOf('KW')['offline_payment']);
    }

    public function test_the_gateways_the_app_is_sent_are_not_what_tells_it_whether_to_offer_them(): void
    {
        $this->switchOff(['KW' => [StorefrontPaymentMethods::DIGITAL_PAYMENT]]);

        $config = json_decode($this->configOf('KW'), true);

        $this->assertFalse($config['digital_payment'], 'the flag the app shows the gateways by');
        $this->assertSame(['ssl_commerz'], array_column($config['payment_methods'], 'key_name'), 'the list itself is the platform\'s, as when the platform has digital payment off');
    }

    // ---- byte for byte -----------------------------------------------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> the states in which nothing is switched off for the storefront that is asked for */
    public static function statesInWhichNothingIsSwitchedOff(): array
    {
        $everything = StorefrontPaymentMethods::METHODS;

        return [
            'the switch is off, whatever is listed' => [['gcc.storefronts.payment_methods.enabled' => false, 'gcc.storefronts.payment_methods.switched_off' => ['SA' => $everything, 'AE' => $everything, 'KW' => $everything]]],
            'the switch is on and nobody lists anything' => [['gcc.storefronts.payment_methods.switched_off' => []]],
            'storefronts are off as a whole, whatever is listed' => [['gcc.storefronts.enabled' => false, 'gcc.storefronts.payment_methods.switched_off' => ['SA' => $everything, 'KW' => $everything]]],
        ];
    }

    #[DataProvider('statesInWhichNothingIsSwitchedOff')]
    public function test_with_nothing_switched_off_the_whole_answer_is_byte_for_byte_what_the_platform_alone_answers(array $state): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.payment_methods.enabled' => false]);
        $asAlways = $this->configOf(null);
        $this->assertSame(self::EVERYTHING_OFFERED, array_intersect_key(json_decode($asAlways, true), self::EVERYTHING_OFFERED));

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.payment_methods.enabled' => true]);
        config($state);

        foreach ([null, 'SA', 'AE', 'KW'] as $country) {
            $this->assertSame($asAlways, $this->configOf($country), (string) $country);
        }
    }

    public function test_a_storefront_that_lists_nothing_gets_the_same_bytes_while_another_one_switches_everything_off(): void
    {
        $asAlways = $this->configOf('AE');
        $this->switchOff(['KW' => StorefrontPaymentMethods::METHODS]);

        $this->assertSame($asAlways, $this->configOf('AE'));
        $this->assertSame($asAlways, $this->configOf('SA'));
        $this->assertNotSame($asAlways, $this->configOf('KW'));
    }

    #[DataProvider('methodsAndTheirFlagWhenOff')]
    public function test_the_answer_of_a_storefront_that_switched_a_method_off_is_the_answer_of_a_platform_that_has_it_off(string $method): void
    {
        $this->switchOff(['KW' => [$method]]);
        $inKuwait = $this->configOf('KW');

        $this->switchOff([]);
        $this->storeSetting($method, '{"status":0}');

        $this->assertSame($this->configOf('KW'), $inKuwait);
    }

    public function test_the_controller_is_not_what_knows_about_storefronts(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/RestAPI/v1/ConfigController.php'));

        $this->assertStringContainsString("getWebConfig(name: 'offline_payment')['status']", $source);
        $this->assertStringContainsString("'digital_payment' => (boolean)getWebConfig(name: 'digital_payment')['status']", $source);
        $this->assertStringContainsString("'cash_on_delivery' => (boolean)getWebConfig(name: 'cash_on_delivery')['status']", $source);
    }
}
