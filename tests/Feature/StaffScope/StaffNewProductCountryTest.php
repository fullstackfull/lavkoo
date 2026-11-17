<?php

namespace Tests\Feature\StaffScope;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\ProductCountry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\Rule;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The country of a new product (GCC-14c, slice 1): a restricted employee can only add a product in a country they work with, and is told so with a validation
 * message. What is checked is what is saved: the country is worked out by the code that builds the row, so a request that names another country, none, or a code that
 * is not one cannot slip a product into a country the employee does not see.
 */
class StaffNewProductCountryTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const REFUSAL = 'the_country_of_this_product_is_not_one_of_your_countries';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();
        $this->primeTaxSystemCache();
        $this->switchStaffScopesOn();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function storefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true]);
    }

    /** Binds a request of employee 2, who works with the given countries, the way the middleware of the product screens does. */
    private function restrictedTo(array $countries): void
    {
        DB::table('admin_country_scopes')->where('admin_id', 2)->delete();
        $this->giveCountries(adminId: 2, countries: $countries);
        $request = Request::create('/admin/products/add', 'POST');
        $this->app->instance('request', $request);
        app(StaffCountryScope::class)->bind(request: $request, admin: \App\Models\Admin::query()->findOrFail(2));
    }

    /** @return list<string> the messages the validation gives the country of a new product */
    private function refusalsOf(array $data): array
    {
        return Validator::make($data, ProductCountry::newProductRules())->errors()->get('storefront_country');
    }

    // ---- the rule ------------------------------------------------------------------------------------------------------------------

    public function test_the_rules_are_exactly_what_they_were_for_everyone_who_is_not_restricted(): void
    {
        $this->assertSame([], ProductCountry::newProductRules(), 'storefronts off: no rule');

        $this->storefrontsOn();
        $this->assertEquals(['storefront_country' => ['nullable', Rule::in(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'])]], ProductCountry::newProductRules());
    }

    public function test_a_restricted_employee_may_add_a_product_in_a_country_they_work_with(): void
    {
        $this->storefrontsOn();
        $this->restrictedTo(['KW']);

        $this->assertSame([], $this->refusalsOf(['storefront_country' => 'KW']));
    }

    public function test_a_restricted_employee_is_refused_a_country_they_do_not_work_with_with_one_message(): void
    {
        $this->storefrontsOn();
        $this->restrictedTo(['KW']);

        foreach (['SA', 'AE', 'ZZ', 'kw', 'Kw', '', ' KW', 'KW ', 'KWT', 1, true] as $country) {
            $this->assertSame([self::REFUSAL], $this->refusalsOf(['storefront_country' => $country]), json_encode($country));
        }
    }

    public function test_a_choice_that_is_missing_or_null_falls_back_to_the_default_storefront_and_is_refused_when_that_is_not_theirs(): void
    {
        $this->storefrontsOn();
        $this->restrictedTo(['KW']);

        $this->assertSame([self::REFUSAL], $this->refusalsOf([]), 'missing');
        $this->assertSame([self::REFUSAL], $this->refusalsOf(['storefront_country' => null]), 'null');
    }

    public function test_a_choice_that_is_missing_is_accepted_when_the_default_storefront_is_one_of_their_countries(): void
    {
        $this->storefrontsOn();
        $this->restrictedTo(['KW', 'SA']);

        $this->assertSame([], $this->refusalsOf([]));
        $this->assertSame([], $this->refusalsOf(['storefront_country' => 'KW']));
        $this->assertSame([self::REFUSAL], $this->refusalsOf(['storefront_country' => 'AE']));
    }

    public function test_a_value_that_is_not_a_country_at_all_is_refused_even_when_the_default_storefront_is_one_of_theirs(): void
    {
        $this->storefrontsOn();
        $this->restrictedTo(['KW', 'SA']);

        foreach (['ZZ', 'kw', 'KWT'] as $country) {
            $this->assertNotSame([], $this->refusalsOf(['storefront_country' => $country]), $country . ' would fall back to the default storefront if the list rule did not refuse it');
        }
    }

    public function test_the_default_storefront_is_the_one_that_is_configured_not_always_saudi_arabia(): void
    {
        $this->storefrontsOn();
        config(['gcc.storefronts.countries.AE.active' => true, 'gcc.storefronts.default_country' => 'AE']);
        $this->restrictedTo(['KW']);

        $this->assertSame([self::REFUSAL], $this->refusalsOf([]));

        $this->restrictedTo(['KW', 'AE']);
        $this->assertSame([], $this->refusalsOf([]), 'with the default storefront among the countries, a missing choice is theirs');
    }

    public function test_an_employee_restricted_to_nothing_may_add_no_product(): void
    {
        $this->storefrontsOn();
        $this->restrictedTo(['zz']);

        $this->assertSame([self::REFUSAL], $this->refusalsOf(['storefront_country' => 'SA']));
        $this->assertSame([self::REFUSAL], $this->refusalsOf([]));
    }

    public function test_while_countries_are_not_tracked_the_product_is_saved_in_the_default_of_the_table_and_that_is_what_is_checked(): void
    {
        $this->restrictedTo(['KW']);
        $this->assertSame([self::REFUSAL], $this->refusalsOf(['storefront_country' => 'KW']), 'the form has no country, the save ignores one, and the row takes SA');
        $this->assertSame([self::REFUSAL], $this->refusalsOf([]));

        $this->restrictedTo(['KW', StaffCountryScope::TABLE_DEFAULT_COUNTRY]);
        $this->assertSame([], $this->refusalsOf([]));
        $this->assertSame([], $this->refusalsOf(['storefront_country' => 'KW']), 'an ignored choice cannot make the check fail either');
    }

    public function test_the_country_of_the_new_row_is_the_one_the_check_was_made_for(): void
    {
        $this->storefrontsOn();
        $this->restrictedTo(['KW']);

        foreach (['KW', 'SA', null, 'ZZ'] as $requested) {
            $saved = ProductCountry::newProductAttributes(requestedCountry: $requested)['storefront_country'];

            $this->assertSame(app(StaffCountryScope::class)->allows(country: $saved), $this->refusalsOf(['storefront_country' => $requested]) === [], json_encode($requested));
        }
    }

    public function test_the_countries_offered_by_the_form_and_the_filter_are_the_ones_of_the_employee(): void
    {
        $this->storefrontsOn();
        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], ProductCountry::options());

        $this->restrictedTo(['QA', 'AE']);

        $this->assertSame(['AE', 'QA'], ProductCountry::options());
        $this->assertSame(['QA'], ProductCountry::filterOf(requestedCountries: ['QA', 'KW', 'SA']));
        $this->assertSame([], ProductCountry::filterOf(requestedCountries: ['KW']));
    }

    // ---- the form request and the whole request --------------------------------------------------------------------------------------

    public function test_the_add_form_request_carries_the_rule(): void
    {
        $this->storefrontsOn();
        $this->restrictedTo(['KW']);

        $rules = (new \App\Http\Requests\ProductAddRequest())->rules();

        $this->assertArrayHasKey('storefront_country', $rules);
        $this->assertSame(self::REFUSAL, Validator::make(['storefront_country' => 'SA'], ['storefront_country' => $rules['storefront_country']])->errors()->first('storefront_country'));
    }

    /** @return list<string> the fields the add request of an employee failed on */
    private function fieldsRefused(string $country): array
    {
        $response = $this->postJson('/admin/products/add', ['storefront_country' => $country, 'lang' => ['EN'], 'name' => ['Probe'], 'description' => ['Probe'], 'product_type' => 'physical', 'unit_price' => 10, 'discount' => 0, 'discount_type' => 'flat'], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        return array_column((array) $response->json('errors'), 'error_code');
    }

    public function test_the_add_route_refuses_a_country_outside_the_scope_and_says_which_field_it_is(): void
    {
        $this->storefrontsOn();
        $this->giveCountries(adminId: 5, countries: ['KW']);
        $this->signInAsStaff(adminId: 5);

        $this->assertContains('storefront_country', $this->fieldsRefused('SA'));
        $this->assertNotContains('storefront_country', $this->fieldsRefused('KW'));
    }

    public function test_the_add_route_checks_nothing_for_the_master_admin_or_an_employee_without_countries_or_a_switch_that_is_off(): void
    {
        $this->storefrontsOn();
        $this->giveCountries(adminId: 5, countries: ['KW']);
        $this->giveCountries(adminId: 1, countries: ['KW']);

        $this->signInAsStaff(adminId: 1);
        $this->assertNotContains('storefront_country', $this->fieldsRefused('SA'));

        $this->signInAsStaff(adminId: 2);
        $this->assertNotContains('storefront_country', $this->fieldsRefused('SA'));

        config(['gcc.staff.country_scopes' => false]);
        $this->signInAsStaff(adminId: 5);
        $this->assertNotContains('storefront_country', $this->fieldsRefused('SA'));
    }

    public function test_the_message_is_a_language_key_every_language_file_holds(): void
    {
        foreach (['en', 'sa', 'ae'] as $locale) {
            $messages = require base_path("resources/lang/{$locale}/new-messages.php");

            $this->assertArrayHasKey(self::REFUSAL, $messages, $locale);
            $this->assertNotSame('', trim((string) $messages[self::REFUSAL]), $locale);
        }
    }
}
