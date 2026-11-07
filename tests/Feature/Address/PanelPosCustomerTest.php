<?php

namespace Tests\Feature\Address;

use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Adding a customer from the point of sale, in the admin panel and in the vendor panel: the old form while the store has not switched to
 * the structured address, region / city / National Address once it has, the ZIP never invented and the one the form sends no longer lost.
 */
class PanelPosCustomerTest extends PanelAddressTestCase
{
    /** @return array<string, array{string}> */
    public static function panels(): array
    {
        return ['admin panel' => ['admin.customer.add'], 'vendor panel' => ['vendor.customer.add']];
    }

    /** What the add-customer form sends for an old-style address. */
    private function legacyCustomer(array $overrides = []): array
    {
        return $overrides + [
            'f_name' => 'Huda', 'l_name' => 'Alharbi', 'email' => 'huda@example.test', 'phone' => '0559876543', 'country' => 'Saudi Arabia',
            'city' => 'جدة', 'zip_code' => '21589', 'address' => 'Tahlia St 4',
        ];
    }

    /** What it sends for a Saudi customer once the structured form is in use: the old city and ZIP boxes are switched off. */
    private function structuredCustomer(array $overrides = []): array
    {
        return $overrides + [
            'f_name' => 'Huda', 'l_name' => 'Alharbi', 'email' => 'huda@example.test', 'phone' => '0559876543', 'country' => 'Saudi Arabia',
            'region_id' => $this->ids['region_MAK'], 'city_id' => $this->ids['jeddah'], 'district' => 'Al Rawdah', 'national_address' => 'jedd 4321',
            'address' => 'Tahlia St 4',
        ];
    }

    private function nothingWasCreated(): void
    {
        $this->assertSame([0, 0, 0], [User::count(), ShippingAddress::count(), DB::table('password_resets')->count()]);
    }

    #[DataProvider('panels')]
    public function test_while_the_mode_is_off_the_customer_is_created_with_the_old_fields_and_the_zip_the_form_sent(string $route): void
    {
        $this->postJson(route($route), $this->legacyCustomer())->assertOk()->assertJson(['success' => true]);

        $customer = User::sole();
        $address = ShippingAddress::sole();
        $this->assertSame(['جدة', '21589', 'Saudi Arabia', 'Tahlia St 4'], [$customer->city, $customer->zip, $customer->country, $customer->street_address]);
        $this->assertSame(
            [$customer->id, 'home', 'Huda Alharbi', 'جدة', '21589', 'Saudi Arabia', 'Tahlia St 4'],
            [(int) $address->customer_id, $address->address_type, $address->contact_person_name, $address->city, $address->zip, $address->country, $address->address],
        );
        $this->assertSame([null, null, null, null], [$address->region_id, $address->city_id, $address->district, $address->national_address]);
    }

    #[DataProvider('panels')]
    public function test_while_the_mode_is_off_structured_fields_in_the_request_are_ignored(string $route): void
    {
        $this->postJson(route($route), $this->legacyCustomer(['region_id' => $this->ids['region_MAK'], 'city_id' => $this->ids['jeddah'], 'national_address' => 'JEDD4321']))->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame(['جدة', null, null], [$address->city, $address->city_id, $address->national_address]);
    }

    #[DataProvider('panels')]
    public function test_a_customer_without_a_zip_gets_none_made_up(string $route): void
    {
        $this->postJson(route($route), $this->legacyCustomer(['zip_code' => null]))->assertOk();

        $this->assertNull(ShippingAddress::sole()->zip);
        $this->assertNull(User::sole()->zip);
    }

    #[DataProvider('panels')]
    public function test_in_soft_mode_a_saudi_customer_gets_the_structured_address_and_the_old_columns_follow_it(string $route): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $this->postJson(route($route), $this->structuredCustomer())->assertOk()->assertJson(['success' => true]);

        $address = ShippingAddress::sole();
        $this->assertSame(
            [$this->ids['region_MAK'], $this->ids['jeddah'], 'Al Rawdah', 'JEDD4321', 'جدة', 'منطقة مكة المكرمة', 'Saudi Arabia'],
            [$address->region_id, $address->city_id, $address->district, $address->national_address, $address->city, $address->state, $address->country],
        );
        $this->assertNull($address->zip, 'no ZIP is made up from the National Address or anything else');
        $customer = User::sole();
        $this->assertSame(['جدة', 'Saudi Arabia', null], [$customer->city, $customer->country, $customer->zip], 'the customer record keeps a city the point-of-sale panels can show');
        $this->assertSame((int) $address->customer_id, $customer->id);
    }

    #[DataProvider('panels')]
    public function test_in_strict_mode_a_saudi_customer_without_the_structured_fields_is_refused_before_anything_is_created(string $route): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);

        $response = $this->postJson(route($route), $this->legacyCustomer())->assertStatus(422);

        $codes = array_column($response->json('errors'), 'error_code');
        $this->assertEqualsCanonicalizing(['region_id', 'city_id', 'national_address'], $codes);
        $this->assertNotEmpty($response->json('errors.0.message'));
        $this->nothingWasCreated();
    }

    #[DataProvider('panels')]
    public function test_the_errors_name_the_inputs_the_forms_script_marks(string $route): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $errors = $this->postJson(route($route), $this->structuredCustomer(['national_address' => 'RAG29', 'phone' => '12345']))->assertStatus(422)->json('errors');

        $this->assertEqualsCanonicalizing(['national_address', 'phone'], array_column($errors, 'error_code'));
        foreach ($errors as $error) {
            $this->assertSame(['error_code', 'message'], array_keys($error));
        }
        $this->nothingWasCreated();
    }

    #[DataProvider('panels')]
    public function test_tampered_ids_are_refused_and_no_customer_is_created(string $route): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        foreach ([['region_id' => $this->ids['region_EAS']], ['city_id' => 36189], ['region_id' => '1e3', 'city_id' => '-5'], ['city_id' => [1]]] as $tampered) {
            $this->postJson(route($route), $this->structuredCustomer($tampered))->assertStatus(422);
        }

        $this->nothingWasCreated();
    }

    #[DataProvider('panels')]
    public function test_a_customer_in_another_country_keeps_the_old_form_in_every_mode(string $route): void
    {
        $this->setAddressMode(AddressSchema::MODE_STRICT);

        $this->postJson(route($route), $this->legacyCustomer(['country' => 'Egypt', 'city' => 'Cairo', 'zip_code' => '11511']))->assertOk();

        $address = ShippingAddress::sole();
        $this->assertSame(['Egypt', 'Cairo', '11511', null], [$address->country, $address->city, $address->zip, $address->city_id]);
    }

    #[DataProvider('panels')]
    public function test_the_registration_mail_is_still_sent_for_a_structured_customer(string $route): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $this->postJson(route($route), $this->structuredCustomer())->assertOk();

        Event::assertDispatched(\App\Events\CustomerRegistrationEvent::class);
    }

    /** @return array<string, array{string}> the four forms: the modal and the off-canvas of each panel */
    public static function forms(): array
    {
        return [
            'admin off-canvas' => ['admin-views.pos.partials.offcanvas._add-new-customer-offcanvas'],
            'admin modal' => ['admin-views.pos.partials.modals._add-customer'],
            'vendor off-canvas' => ['vendor-views.pos.partials.offcanvas._add-new-customer-offcanvas'],
            'vendor modal' => ['vendor-views.pos.partials.modals._add-customer'],
        ];
    }

    private function renderForm(string $view, array|int $zipCodes = 0): string
    {
        return View::make($view, ['countries' => [['code' => 'EG', 'name' => 'Egypt'], ['code' => 'SA', 'name' => 'Saudi Arabia']], 'zipCodes' => $zipCodes])->render();
    }

    #[DataProvider('forms')]
    public function test_the_form_is_the_old_one_while_the_mode_is_off(string $view): void
    {
        $html = $this->renderForm($view);

        $this->assertStringNotContainsString('data-unified-address', $html);
        $this->assertStringNotContainsString('data-ua-other', $html);
        $this->assertStringContainsString('name="city"', $html);
        $this->assertStringContainsString('name="zip_code"', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="Egypt"[^>]*selected/', $html);
    }

    #[DataProvider('forms')]
    public function test_the_form_asks_for_the_structured_address_once_the_mode_is_on(string $view): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);

        $html = $this->renderForm($view);

        foreach (['name="region_id"', 'name="city_id"', 'name="district"', 'name="national_address"', 'data-ua-other'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('window.unifiedAddress', $html, 'the picker script travels with the form');
        $this->assertMatchesRegularExpression('/<option value="Saudi Arabia"[^>]*selected/', $html, 'Saudi Arabia is the country a Saudi store starts on');
        $this->assertStringContainsString('name="city"', $html, 'the old city box stays for other countries');
        $this->assertStringContainsString('name="zip_code"', $html, 'and so does the ZIP box');
    }

    #[DataProvider('forms')]
    public function test_the_zip_list_is_posted_under_the_name_the_server_reads(string $view): void
    {
        $zipCodes = [(object) ['zipcode' => '12211'], (object) ['zipcode' => '21589']];

        $html = $this->renderForm($view, $zipCodes);

        $this->assertMatchesRegularExpression('/<select[^>]*name="zip_code"/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="zip"/', $html);
    }
}
