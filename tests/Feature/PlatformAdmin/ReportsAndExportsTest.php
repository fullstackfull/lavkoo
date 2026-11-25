<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\PlatformAdminGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\EnrolsPlatformAdminFactors;
use Tests\Support\SeedsPlatformMarketData;

/**
 * One market's report, and the file an operator may take off the platform (L-14).
 *
 * A report is ONE market over a bounded window, with the money in it: what it sold and what was settled with its shops, each by the company that did
 * it and the currency, never one figure across two. An export is the same market's orders as a file, and what guards it is the point of the
 * requirement: a capability no existing grant holds, a second factor proved minutes ago, one market at a time, a few per hour, a watermark naming who
 * took it, a line in the trail saying how many rows left, no customer in it and no copy left on the server.
 */
class ReportsAndExportsTest extends PlatformAdminTestCase
{
    use EnrolsPlatformAdminFactors;
    use SeedsPlatformMarketData;

    private const REPORTS = self::PREFIX . '/reports';

    private const EXPORT = self::PREFIX . '/exports/orders';

    private const SAUDI_COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
        $this->runOrderSnapshotMigrations();
        DB::table('legal_entities')->insert([
            ['id' => self::SAUDI_COMPANY, 'country' => 'SA', 'legal_name' => 'Lavkoo Trading Company', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_a_report_of_one_market_gives_its_orders_its_returns_and_what_it_sold_by_company_and_currency(): void
    {
        $this->sell(orderId: 201, market: 'SA', currency: 'SAR', amount: '100.00');
        $this->sell(orderId: 202, market: 'SA', currency: 'SAR', amount: '25.50');

        $report = $this->report(market: 'SA')->assertOk()->json('data');

        $this->assertSame('SA', $report['market']);
        $this->assertSame(['id' => self::SAUDI_COMPANY, 'name' => 'Lavkoo Trading Company', 'trade_name' => null], $report['legal_entity']);
        // Only the statuses that occurred, by name. The overview pads every status the platform knows with a zero because its cards are a fixed
        // shape; a report is read by a person asking what happened, and a column of zeros is not an answer to that.
        $this->assertSame(['confirmed' => 1, 'delivered' => 2, 'pending' => 1, 'processing' => 1], $report['orders']['by_status']);
        $this->assertSame(5, $report['orders']['total'], 'the orders of the market, counted the way the overview counts them');
        $this->assertSame(['refund_requests' => 2, 'pending' => 1], $report['returns']);
        $this->assertSame(
            [['currency' => 'SAR', 'legal_entity_id' => self::SAUDI_COMPANY, 'legal_entity' => 'Lavkoo Trading Company', 'orders' => 2, 'gross' => '125.50']],
            $report['sales']['by_currency'],
        );
        $this->assertSame('no_conversion_rule_recorded', $report['sales']['reason'], 'no figure across currencies without a rate, a source and a date');
        $this->assertSame(['from' => '2026-10-19', 'to' => '2026-11-17', 'days' => 30, 'clamped' => false, 'timezone' => date_default_timezone_get()], $report['window']);
    }

    public function test_a_report_of_every_market_at_once_is_refused_because_a_report_is_of_one(): void
    {
        $this->getJson(self::REPORTS, $this->bearer($this->tokenFor(['reports'])))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'one_market_only');
    }

    public function test_a_report_says_what_was_settled_with_the_market_by_company_and_currency(): void
    {
        $this->buildSettlementLedger();
        $this->settle(reference: 'S-1', entityId: self::SAUDI_COMPANY, currency: 'SAR', net: '900.00', status: 'paid');
        $this->settle(reference: 'S-2', entityId: self::SAUDI_COMPANY, currency: 'SAR', net: '100.00', status: 'approved');
        $this->settle(reference: 'S-3', entityId: self::SAUDI_COMPANY, currency: 'SAR', net: '50.00', status: 'draft');
        $this->settle(reference: 'S-4', entityId: null, currency: 'SAR', net: '70.00', status: 'paid');

        $settlements = $this->report(market: 'SA')->assertOk()->json('data.settlements');

        $this->assertNull($settlements['reason']);
        $this->assertSame(
            [['legal_entity_id' => self::SAUDI_COMPANY, 'legal_entity' => 'Lavkoo Trading Company', 'currency' => 'SAR', 'settlements' => 2, 'net' => '1000.00']],
            $settlements['by_currency'],
            'a draft is a calculation and not a settlement, and a settlement with no company belongs to no market',
        );
    }

    public function test_without_the_settlement_ledger_the_report_says_so_rather_than_reporting_a_zero(): void
    {
        Schema::dropIfExists('vendor_settlements');
        $settlements = $this->report(market: 'SA')->assertOk()->json('data.settlements');

        $this->assertNull($settlements['by_currency']);
        $this->assertSame('settlement_ledger_not_present', $settlements['reason']);
    }

    public function test_a_report_and_an_export_need_their_own_capability_which_no_existing_grant_holds(): void
    {
        $this->assertContains('reports', PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT);
        $this->assertContains('export', PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT);

        $named = $this->mintToken($this->platformAdmin());

        $this->getJson(self::REPORTS . '?market=SA', $this->bearer($named))->assertStatus(403)->assertJsonPath('errors.0.capability', 'reports');
        $this->getJson(self::EXPORT . '?market=SA', $this->bearer($named))->assertStatus(403)->assertJsonPath('errors.0.capability', 'export');
    }

    public function test_a_viewer_of_one_market_cannot_report_or_export_another(): void
    {
        $token = $this->tokenFor(['reports', 'export'], countries: ['KW']);

        $this->getJson(self::REPORTS . '?market=SA', $this->bearer($token))->assertStatus(403)->assertJsonPath('errors.0.code', 'market_not_allowed');
        $this->getJson(self::EXPORT . '?market=SA', $this->bearer($token))->assertStatus(403)->assertJsonPath('errors.0.code', 'market_not_allowed');
        $this->getJson(self::REPORTS . '?market=KW', $this->bearer($token))->assertOk();
    }

    public function test_the_file_holds_one_market_its_watermark_and_no_customer_and_is_not_kept_anywhere(): void
    {
        $this->sell(orderId: 211, market: 'SA', currency: 'SAR', amount: '100.00', customerId: 77);
        $this->sell(orderId: 212, market: 'KW', currency: 'KWD', amount: '9.000', customerId: 78);
        $admin = $this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['export'])]);
        $filesBefore = $this->filesUnderStorage();

        $response = $this->get(self::EXPORT . '?market=SA', $this->bearer($this->mintToken($admin)));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('# LAVKOO platform export', $csv);
        $this->assertStringContainsString('# operator: ' . $admin['id'] . ' ' . $admin['name'], $csv);
        $this->assertStringContainsString('# market: SA', $csv);
        $this->assertStringContainsString('# window: 2026-10-19 to 2026-11-17', $csv);
        $this->assertStringContainsString('order_id,placed_on,status,market,legal_entity_id,currency,amount', $csv);
        $this->assertStringContainsString('211,', $csv);
        $this->assertStringNotContainsString('212,', $csv, 'the file holds the market that was asked for and no other');
        $this->assertSame(
            ['order_id', 'placed_on', 'status', 'market', 'legal_entity_id', 'currency', 'amount'],
            str_getcsv((string) preg_split('/\n/', trim(preg_replace('/^#[^\n]*\n/m', '', $csv) ?? ''))[0]),
            'the whole of what a file holds: no customer, no address, no telephone number, no e-mail',
        );
        foreach (['customer', 'address', 'phone', 'email'] as $person) {
            $this->assertStringNotContainsStringIgnoringCase($person, $csv, 'a file that leaves the platform names no person');
        }
        $this->assertSame('attachment; filename="lavkoo-orders-sa-2026-10-19-to-2026-11-17.csv"', $response->headers->get('Content-Disposition'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'), 'not stored, and not a copy a proxy may keep');
        $this->assertSame($filesBefore, $this->filesUnderStorage(), 'an export leaves no file of a market\'s orders on the server');
    }

    public function test_an_export_writes_one_line_of_the_trail_saying_how_many_rows_left_the_platform(): void
    {
        $this->sell(orderId: 221, market: 'SA', currency: 'SAR', amount: '1.00');
        $this->sell(orderId: 222, market: 'SA', currency: 'SAR', amount: '2.00');

        $this->get(self::EXPORT . '?market=SA', $this->bearer($this->tokenFor(['export'])))->assertOk();

        $line = DB::table('platform_admin_audit_logs')->where('action', 'exports.orders')->orderByDesc('id')->first();

        $this->assertNotNull($line, 'what left the platform is a recorded fact');
        $this->assertSame('SA', (string) $line->market);
        $this->assertSame([
            'status' => 200,
            'params' => ['market' => 'SA'],
            'rows' => 2,
            'file' => 'lavkoo-orders-sa-2026-10-19-to-2026-11-17.csv',
            'from' => '2026-10-19',
            'to' => '2026-11-17',
        ], json_decode((string) $line->context, true));
    }

    public function test_an_export_needs_the_second_factor_proved_again_and_not_merely_at_the_sign_in(): void
    {
        config(['gcc.platform_admin.mfa.required' => true]);
        $admin = $this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['export'])]);
        $this->enrolFactor(adminId: $admin['id']);
        $token = $this->mintToken($admin, ['mfa_method' => 'totp']);

        $this->get(self::EXPORT . '?market=SA', $this->bearer($token))
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'step_up_required');

        DB::table('platform_admin_tokens')->where('token_hash', hash('sha256', $token))->update(['stepped_up_at' => now()]);

        $this->get(self::EXPORT . '?market=SA', $this->bearer($token))->assertOk();
    }

    public function test_an_export_of_every_market_at_once_is_refused(): void
    {
        $this->get(self::EXPORT, $this->bearer($this->tokenFor(['export'])))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'one_market_only');
    }

    public function test_an_operator_may_take_only_so_many_files_in_an_hour(): void
    {
        config(['gcc.platform_admin.exports_per_hour' => 2]);
        $token = $this->tokenFor(['export']);

        $this->get(self::EXPORT . '?market=SA', $this->bearer($token))->assertOk();
        $this->get(self::EXPORT . '?market=SA', $this->bearer($token))->assertOk();

        $refused = $this->get(self::EXPORT . '?market=SA', $this->bearer($token));
        $refused->assertStatus(429)->assertJsonPath('errors.0.code', 'platform_admin_throttled');
        $this->assertNotNull($refused->headers->get('Retry-After'));
    }

    public function test_on_an_installation_whose_orders_record_no_market_an_export_says_so(): void
    {
        Schema::table('orders', fn ($table) => $table->dropColumn('storefront_country'));

        $this->get(self::EXPORT . '?market=SA', $this->bearer($this->tokenFor(['export'])))
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'order_market_not_recorded');

        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
    }

    private function report(string $market): TestResponse
    {
        return $this->getJson(self::REPORTS . '?market=' . $market, $this->bearer($this->tokenFor(['reports'])));
    }

    /** @param list<string> $capabilities */
    private function tokenFor(array $capabilities, ?array $countries = null): string
    {
        return $this->mintToken($this->platformAdmin($countries, grantOverrides: ['capabilities' => json_encode($capabilities)]));
    }

    private function sell(int $orderId, string $market, string $currency, string $amount, ?int $customerId = null): void
    {
        DB::table('orders')->insert([
            'id' => $orderId,
            'customer_id' => $customerId,
            'order_status' => 'delivered',
            'order_amount' => $amount,
            'storefront_country' => $market,
            'currency_code' => $currency,
            'currency_rate' => '1',
            'legal_entity_id' => $market === 'SA' ? self::SAUDI_COMPANY : null,
            'created_at' => '2026-11-16 10:00:00',
            'updated_at' => '2026-11-16 10:00:00',
        ]);
    }

    private function settle(string $reference, ?int $entityId, string $currency, string $net, string $status): void
    {
        DB::table('vendor_settlements')->insert([
            'reference' => $reference,
            'legal_entity_id' => $entityId,
            'currency' => $currency,
            'net_amount' => $net,
            'status' => $status,
            'created_at' => '2026-11-16 10:00:00',
            'updated_at' => '2026-11-16 10:00:00',
        ]);
    }

    /** The columns the platform reads of the settlement ledger. The live table came from the installation dump and has no migration here. */
    private function buildSettlementLedger(): void
    {
        Schema::dropIfExists('vendor_settlements');
        Schema::create('vendor_settlements', function ($table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->string('currency', 20)->nullable();
            $table->decimal('net_amount', 24, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });
    }

    private function runOrderSnapshotMigrations(): void
    {
        foreach (['2026_11_26_100000_add_storefront_snapshot_to_orders_table', '2026_11_27_100100_add_legal_entity_to_orders_table', '2026_11_27_100000_create_legal_entities_table'] as $migration) {
            (require database_path("migrations/{$migration}.php"))->up();
        }
    }

    /**
     * Every file of a market's orders anywhere a web server could serve one from, so that "nothing is kept" is measured rather than assumed. The log
     * and the framework cache are left out on purpose: they are written by any request and are not a copy of the file.
     *
     * @return list<string>
     */
    private function filesUnderStorage(): array
    {
        $files = [];

        foreach ([storage_path('app'), public_path(), sys_get_temp_dir()] as $path) {
            foreach (is_dir($path) ? (array) glob($path . '/*{.csv,orders,export}*', GLOB_BRACE) : [] as $file) {
                $files[] = (string) $file;
            }
        }
        sort($files);

        return $files;
    }
}
