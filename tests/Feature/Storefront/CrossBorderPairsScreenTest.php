<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Shipping\CrossBorderPairs;
use App\Services\Shipping\CrossBorderPolicy;
use App\Services\Shipping\PairNotRecorded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Who may open a border, and what is written down when they do (H1-01, criterion 3).
 *
 * Opening a pair is a commercial and legal act: the storefront begins selling into a country it has to be registered in, whose duty rules
 * apply to its parcels and whose consumer law applies to its customers. So two things have to hold -- only staff of the SOURCE market may
 * change what it sells into, and every change leaves a line with a name on it.
 *
 * The REAL route is driven and not the controller, because the narrowing reads `StaffCountryScope::boundCountries()` and a test that sets
 * that attribute by hand cannot tell a guard from a route where nothing binds. That is exactly how the audit centre shipped a guard which
 * looked narrowed and read every market's trail.
 *
 * Every refusal here has its unrestricted twin, because a refusal test alone cannot tell a guard from a wrong URL.
 */
class CrossBorderPairsScreenTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const MIGRATION = '2027_01_02_100100_create_storefront_destination_pairs_table.php';

    /** A catalog employee, which is the module the shipping-permission group asks for. */
    private const STAFF = 2;

    private const MASTER = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class,
            NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffAdminTables();
        // The scope is enforced only where the products carry their country, which is what StaffCountryScope::enabled() asks.
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();
        $this->switchStaffScopesOn();

        Schema::dropIfExists(CrossBorderPolicy::TABLE);
        (require database_path('migrations/' . self::MIGRATION))->up();
        CrossBorderPolicy::forget();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.default_country' => 'SA',
            // Set here and never in `config/gcc.php`: which markets are active is the owner's G0 decision and never a test's.
            'gcc.storefronts.countries' => [
                'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true],
                'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => true],
            ],
            'gcc.shipping.cross_border.enabled' => true,
            'gcc.shipping.cross_border.pairs' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(CrossBorderPolicy::TABLE);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_an_unrestricted_employee_opens_a_pair_and_the_change_is_audited(): void
    {
        $this->signInAsStaff(self::MASTER);

        $response = $this->post(route('admin.shipping.cross-border-pairs.record'), [
            'source_country' => 'SA',
            'destination_country' => 'KW',
            'open' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        CrossBorderPolicy::forget();
        $this->assertTrue(CrossBorderPolicy::allows('SA', 'KW'));

        $line = DB::table('audit_logs')->where('action', 'cross_border_pair_opened')->first();
        $this->assertNotNull($line, 'a border opened with nothing saying who opened it is the one state this screen may not reach');
        $this->assertSame('SA', $line->storefront_country, 'the market the line concerns is the storefront whose selling changed');
        $this->assertSame(false, json_decode($line->before, true)['open'], 'and what it was before, so the change can be read');
        $this->assertSame(true, json_decode($line->after, true)['open']);
    }

    public function test_the_row_records_who_opened_the_pair_and_when(): void
    {
        $this->signInAsStaff(self::MASTER);

        $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'SA', 'destination_country' => 'KW', 'open' => 1]);

        $row = DB::table(CrossBorderPolicy::TABLE)->first();
        $this->assertSame(self::MASTER, (int) $row->recorded_by);
        $this->assertNotNull($row->recorded_at, 'the answer the engine reads carries its own provenance');
    }

    public function test_an_employee_of_the_source_market_may_open_what_that_market_sells_into(): void
    {
        $this->giveCountries(self::STAFF, ['SA']);
        $this->signInAsStaff(self::STAFF);

        $response = $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'SA', 'destination_country' => 'KW', 'open' => 1]);

        $response->assertSessionHasNoErrors();
        CrossBorderPolicy::forget();
        $this->assertTrue(CrossBorderPolicy::allows('SA', 'KW'));
    }

    /** The refusal, and the twin above is what makes it a refusal of the employee rather than of the URL. */
    public function test_an_employee_of_another_market_may_not_open_what_this_market_sells_into(): void
    {
        $this->giveCountries(self::STAFF, ['KW']);
        $this->signInAsStaff(self::STAFF);

        $response = $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'SA', 'destination_country' => 'KW', 'open' => 1]);

        $response->assertSessionHasErrors('source_country');
        CrossBorderPolicy::forget();
        $this->assertFalse(CrossBorderPolicy::allows('SA', 'KW'), 'nothing was written');
        $this->assertSame(0, DB::table('audit_logs')->count(), 'and nothing was audited, because nothing happened');
    }

    public function test_that_same_employee_may_open_what_their_own_market_sells_into(): void
    {
        $this->giveCountries(self::STAFF, ['KW']);
        $this->signInAsStaff(self::STAFF);

        $response = $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'KW', 'destination_country' => 'SA', 'open' => 1]);

        $response->assertSessionHasNoErrors();
        CrossBorderPolicy::forget();
        $this->assertTrue(CrossBorderPolicy::allows('KW', 'SA'));
        $this->assertFalse(CrossBorderPolicy::allows('SA', 'KW'), 'and the other direction is a pair of its own');
    }

    /** A recorded close beats a declared open, which is why `is_open` is a column and not the row's existence. */
    public function test_closing_a_pair_the_declared_list_opens_is_recorded_rather_than_lost(): void
    {
        config(['gcc.shipping.cross_border.pairs' => ['SA' => ['KW']]]);
        CrossBorderPolicy::forget();
        $this->assertTrue(CrossBorderPolicy::allows('SA', 'KW'), 'the declared list opens it');
        $this->signInAsStaff(self::MASTER);

        $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'SA', 'destination_country' => 'KW']);

        CrossBorderPolicy::forget();
        $this->assertFalse(CrossBorderPolicy::allows('SA', 'KW'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'cross_border_pair_closed')->count());
        $this->assertSame(0, (int) DB::table(CrossBorderPolicy::TABLE)->value('is_open'), 'the close is a row and not the absence of one');
    }

    public function test_a_market_the_platform_does_not_serve_is_refused_rather_than_stored(): void
    {
        $this->signInAsStaff(self::MASTER);

        $response = $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'SA', 'destination_country' => 'TR', 'open' => 1]);

        $response->assertSessionHasErrors('source_country');
        $this->assertSame(0, DB::table(CrossBorderPolicy::TABLE)->count(), 'a row that will be read one day must not name a market nobody sells in');
    }

    public function test_a_market_selling_into_itself_is_not_a_pair(): void
    {
        $this->signInAsStaff(self::MASTER);

        $response = $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'SA', 'destination_country' => 'SA', 'open' => 1]);

        $response->assertSessionHasErrors('source_country');
        $this->assertSame(0, DB::table(CrossBorderPolicy::TABLE)->count());
    }

    public function test_the_screen_offers_only_the_markets_the_employee_may_change(): void
    {
        $this->giveCountries(self::STAFF, ['KW']);
        $this->signInAsStaff(self::STAFF);

        $response = $this->get(route('admin.shipping.cross-border-pairs.index'));

        $response->assertOk();
        $response->assertViewHas('sources', ['KW']);
        $response->assertViewHas('destinations', ['SA', 'KW']);
    }

    public function test_the_screen_lists_only_the_pairs_of_the_markets_the_employee_works_with(): void
    {
        $this->signInAsStaff(self::MASTER);
        $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'SA', 'destination_country' => 'KW', 'open' => 1]);
        $this->post(route('admin.shipping.cross-border-pairs.record'), ['source_country' => 'KW', 'destination_country' => 'SA', 'open' => 1]);
        $this->giveCountries(self::STAFF, ['KW']);
        $this->signInAsStaff(self::STAFF);

        $response = $this->get(route('admin.shipping.cross-border-pairs.index'));

        $sources = array_map(static fn (object $pair): string => $pair->source_country, $response->viewData('recorded'));
        $this->assertSame(['KW'], $sources, 'another market is not even shown, so holding one is not something to find out by asking');
    }

    /** With the table absent the screen says so rather than failing, and nothing can be recorded. */
    public function test_nothing_can_be_recorded_before_the_migration_runs(): void
    {
        Schema::dropIfExists(CrossBorderPolicy::TABLE);
        CrossBorderPolicy::forget();
        $pairs = app(CrossBorderPairs::class);

        $this->assertFalse($pairs->available());
        $this->assertSame([], $pairs->recorded());

        $this->expectException(PairNotRecorded::class);
        $pairs->record(source: 'SA', destination: 'KW', open: true);
    }
}
