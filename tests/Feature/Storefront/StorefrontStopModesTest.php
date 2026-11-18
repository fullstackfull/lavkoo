<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * I2-13 to I2-16: what a stop does to the requests of a storefront. In maintenance the requests that browse it are refused with its message and the end of the stop, in browse-only
 * and in redirect they are served and every new sale is refused, with the storefront's own message when it has one, and the list an app reads says how each storefront is
 * stopped. Another storefront is never touched, and while storefronts are off nothing is read. That the routes which serve an earlier order do not carry either gate is pinned in
 * StorefrontSalesRoutesTest.
 */
class StorefrontStopModesTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;

    private const PERSON = 'The head of the Kuwait market';

    private const REASON = 'Decision of the operations meeting';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_lifecycle_');
        DB::table('audit_logs')->delete();
        DB::table('business_settings')->where('type', 'like', 'storefront_lifecycle_%')->delete();
        clearWebConfigCacheKeys();

        Schema::dropIfExists('guest_users');
        Schema::create('guest_users', function ($table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->publishRequiredLegalDocuments(country: 'KW');
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->approveNoTaxFor('KW', 'AE');

        Route::middleware(['storefront', 'storefront.available'])->get('/__browse-probe', fn () => response()->json(['ok' => true]));
    }

    private function pause(string $mode, string $country = 'KW', ?string $message = null, ?string $until = null): void
    {
        app(StorefrontLifecycle::class)->pause(country: $country, mode: $mode, by: self::PERSON, reason: self::REASON, message: $message, until: $until);
    }

    private function browse(string $country = 'KW')
    {
        return $this->getJson('/__browse-probe', ['X-Storefront' => $country]);
    }

    private function startAPayment(string $country = 'KW')
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn (RoutingRoute $candidate) => str_ends_with((string) $candidate->getActionName(), '\\Customer\\PaymentController@payment'));
        $this->assertNotNull($route);

        return $this->postJson('/' . $route->uri(), ['guest_id' => 'guest-1'], ['X-Storefront' => $country]);
    }

    /** @return array<string, mixed> the entry of one storefront in the list an app reads */
    private function listedEntry(string $country): array
    {
        $listed = collect($this->getJson('/api/v1/storefronts')->assertOk()->json('storefronts'))->firstWhere('country', $country);
        $this->assertNotNull($listed, "{$country} is not listed.");

        return $listed;
    }

    // ---- maintenance ------------------------------------------------------------------------------------------------------------------------

    public function test_an_active_storefront_is_browsed(): void
    {
        $this->browse()->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_storefront_in_maintenance_refuses_what_browses_it_with_its_message_and_the_end_of_the_stop(): void
    {
        $until = now()->utc()->addDays(2)->format('Y-m-d H:i');
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE, message: 'We are back on Sunday, with our thanks.', until: $until);

        $this->browse()
            ->assertStatus(503)
            ->assertJsonPath('code', 'storefront_maintenance')
            ->assertJsonPath('message', 'We are back on Sunday, with our thanks.')
            ->assertJsonPath('until', $until);
    }

    public function test_a_maintenance_with_no_message_says_the_default_one_and_no_end(): void
    {
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);

        $this->browse()
            ->assertStatus(503)
            ->assertJsonPath('code', 'storefront_maintenance')
            ->assertJsonPath('message', translate('storefront_is_in_maintenance'))
            ->assertJsonPath('until', null);
    }

    public function test_a_request_that_does_not_ask_for_json_is_refused_with_the_same_answer(): void
    {
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE, message: 'Back soon.');

        $this->get('/__browse-probe?storefront=KW')
            ->assertStatus(503)
            ->assertJsonPath('code', 'storefront_maintenance')
            ->assertJsonPath('message', 'Back soon.');
    }

    public function test_one_storefront_in_maintenance_leaves_the_others_browsed(): void
    {
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);

        $this->browse('KW')->assertStatus(503);
        $this->browse('AE')->assertOk();
        $this->getJson('/__browse-probe')->assertOk();
        $this->browse('SA')->assertOk();
    }

    public function test_a_storefront_that_is_resumed_is_browsed_again(): void
    {
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);
        $this->browse()->assertStatus(503);

        app(StorefrontLifecycle::class)->resume(country: 'KW', by: self::PERSON, reason: 'The work is done');

        $this->browse()->assertOk();
    }

    public function test_the_maintenance_survives_a_flush_of_the_cache(): void
    {
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);

        cache()->flush();

        $this->browse()->assertStatus(503);
    }

    public function test_nothing_is_refused_while_storefronts_are_off(): void
    {
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);
        config(['gcc.storefronts.enabled' => false]);

        $this->browse()->assertOk();
    }

    #[DataProvider('modesThatStillServeTheStorefront')]
    public function test_browse_only_and_redirect_still_serve_what_browses_the_storefront(string $mode): void
    {
        $this->pause(mode: $mode);

        $this->browse()->assertOk();
    }

    public static function modesThatStillServeTheStorefront(): array
    {
        return [
            'browse only' => [StorefrontLifecycle::BROWSE_ONLY],
            'redirect' => [StorefrontLifecycle::REDIRECT],
        ];
    }

    // ---- a new sale -------------------------------------------------------------------------------------------------------------------------

    public function test_an_active_storefront_that_meets_what_it_needs_starts_a_payment_past_the_gate(): void
    {
        $this->assertNotSame(422, $this->startAPayment()->getStatusCode());
    }

    #[DataProvider('everyMode')]
    public function test_every_stop_mode_refuses_a_new_sale_with_the_message_of_the_storefront(string $mode): void
    {
        $this->pause(mode: $mode, message: 'Orders are closed until Sunday.');

        $this->startAPayment()
            ->assertStatus(422)
            ->assertJsonPath('code', 'storefront_not_sellable')
            ->assertJsonPath('message', 'Orders are closed until Sunday.');
    }

    #[DataProvider('everyMode')]
    public function test_every_stop_mode_refuses_a_new_sale_with_the_default_message_when_it_has_none(string $mode): void
    {
        $this->pause(mode: $mode);

        $this->startAPayment()
            ->assertStatus(422)
            ->assertJsonPath('code', 'storefront_not_sellable')
            ->assertJsonPath('message', translate('storefront_not_open_for_checkout_yet'));
    }

    public static function everyMode(): array
    {
        return [
            'browse only' => [StorefrontLifecycle::BROWSE_ONLY],
            'maintenance' => [StorefrontLifecycle::MAINTENANCE],
            'redirect' => [StorefrontLifecycle::REDIRECT],
        ];
    }

    public function test_a_stopped_storefront_refuses_only_its_own_sales(): void
    {
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);

        $this->startAPayment('KW')->assertStatus(422);
        $this->assertNotSame(422, $this->startAPayment('AE')->getStatusCode());
    }

    // ---- the list an app reads --------------------------------------------------------------------------------------------------------------

    public function test_the_list_says_the_state_of_each_storefront(): void
    {
        $this->assertEquals(['state' => 'active', 'sellable' => true], collect($this->listedEntry('KW'))->only(['state', 'sellable'])->all());
        $this->assertEquals(['state' => 'active', 'sellable' => true], collect($this->listedEntry('SA'))->only(['state', 'sellable'])->all());
        $this->assertArrayNotHasKey('stop_mode', $this->listedEntry('KW'));
    }

    public function test_a_storefront_nobody_has_prepared_is_listed_as_a_draft_that_cannot_sell(): void
    {
        config(['gcc.storefronts.countries.QA.active' => true]);

        $this->assertEquals(['state' => 'draft', 'sellable' => false], collect($this->listedEntry('QA'))->only(['state', 'sellable'])->all());
    }

    #[DataProvider('everyMode')]
    public function test_a_stopped_storefront_is_listed_with_its_mode_message_and_end(string $mode): void
    {
        $until = now()->utc()->addDay()->format('Y-m-d H:i');
        $this->pause(mode: $mode, message: 'Back soon.', until: $until);

        $listed = $this->listedEntry('KW');

        $this->assertSame(
            ['paused', false, $mode, 'Back soon.', $until, $mode === StorefrontLifecycle::REDIRECT ? 'SA' : null],
            [$listed['state'], $listed['sellable'], $listed['stop_mode'], $listed['stop_message'], $listed['stop_until'], $listed['redirect_to']],
        );
        $this->assertSame('active', $this->listedEntry('AE')['state']);
    }

    public function test_a_stopped_storefront_with_no_message_is_listed_with_the_default_one(): void
    {
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);
        $this->assertSame(translate('storefront_is_in_maintenance'), $this->listedEntry('KW')['stop_message']);

        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);
        $this->assertSame(translate('storefront_not_open_for_checkout_yet'), $this->listedEntry('KW')['stop_message']);
    }

    public function test_the_list_keeps_its_original_shape_while_storefronts_are_off(): void
    {
        $this->pause(mode: StorefrontLifecycle::MAINTENANCE);
        config(['gcc.storefronts.enabled' => false]);

        $this->getJson('/api/v1/storefronts')->assertOk()->assertExactJson([
            'enabled' => false,
            'default_country' => 'SA',
            'storefronts' => [['country' => 'SA', 'path' => 'sa', 'currency' => 'SAR', 'is_default' => true]],
        ]);
    }
}
