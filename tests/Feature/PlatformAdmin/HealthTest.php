<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPaymentRequestsTable;

/**
 * L-09: operational health per market -- the payment attempts that never came back, and the integrations that have stopped syncing.
 *
 * Three things are pinned here that are easy to get wrong and expensive to get wrong:
 *
 *  - a row whose market is not recorded is reported APART and never counted as Saudi Arabia, which would make one market look busy because another one is;
 *  - the answer carries counts and timestamps only. The test reads the whole body and asserts that no amount, customer, gateway reference or credential
 *    is anywhere in it, because this endpoint is meant to be safe to give an operator who is not trusted with money;
 *  - the capability is its own, and it is NOT given by a grant that names no capability. Every other capability works the other way round, so this is the
 *    one place where "names none" does not mean "all", and a grant made before this endpoint existed must not have acquired it.
 */
class HealthTest extends PlatformAdminTestCase
{
    use BuildsPaymentRequestsTable;

    private const URL = self::PREFIX . '/health';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildHealthWorld();
    }

    public function test_the_capability_is_not_given_by_a_grant_that_names_none(): void
    {
        $everything = $this->mintToken($this->platformAdmin());

        $this->getJson(self::URL, $this->bearer($everything))->assertForbidden();
    }

    public function test_an_operator_granted_the_capability_is_let_in(): void
    {
        $this->health()->assertOk();
    }

    public function test_each_market_gets_its_own_figures_and_nothing_is_added_across_markets(): void
    {
        $markets = collect($this->health()->json('data.markets'))->keyBy('market');

        $this->assertSame(2, $markets['SA']['payments']['stuck_attempts'], 'two Saudi attempts are old enough to be stuck');
        $this->assertSame(1, $markets['KW']['payments']['stuck_attempts']);
        $this->assertSame(0, $markets['AE']['payments']['stuck_attempts'], 'a market with nothing says zero');
    }

    public function test_an_attempt_that_is_not_old_enough_yet_is_not_called_stuck(): void
    {
        $markets = collect($this->health()->json('data.markets'))->keyBy('market');

        $this->assertSame(
            2,
            $markets['SA']['payments']['stuck_attempts'],
            'the third Saudi attempt was started a minute ago: a shopper still typing their card number is not an incident',
        );
    }

    public function test_a_paid_attempt_is_not_stuck_however_old_it_is(): void
    {
        DB::table('payment_requests')->where('id', 'sa-old-1')->update(['is_paid' => true]);

        $markets = collect($this->health()->json('data.markets'))->keyBy('market');

        $this->assertSame(1, $markets['SA']['payments']['stuck_attempts']);
    }

    public function test_an_attempt_whose_market_is_not_recorded_is_reported_apart_and_never_as_the_default(): void
    {
        $body = $this->health('?market=all')->json('data');
        $markets = collect($body['markets'])->keyBy('market');

        $this->assertSame(1, $body['unattributed']['payments']['stuck_attempts'], 'the order with no market recorded');
        $this->assertSame('no_market_recorded', $body['unattributed']['reason']);
        $this->assertSame(2, $markets['SA']['payments']['stuck_attempts'], 'and Saudi Arabia did not absorb it');
    }

    public function test_an_attempt_that_was_not_for_an_order_is_left_out_rather_than_guessed_at(): void
    {
        $body = $this->health()->json('data');
        $total = collect($body['markets'])->sum(fn (array $market): int => $market['payments']['stuck_attempts'])
            + ($body['unattributed']['payments']['stuck_attempts'] ?? 0);

        $this->assertSame(4, $total, 'the wallet top-up is not counted in any market, nor in the unattributed bucket');
    }

    public function test_the_integrations_of_each_market_report_their_accounts_failures_and_lateness(): void
    {
        $markets = collect($this->health()->json('data.markets'))->keyBy('market');

        $this->assertSame(1, $markets['SA']['integrations']['channels']['accounts']);
        $this->assertSame(1, $markets['SA']['integrations']['channels']['failing_resources'], 'three failures in a row is failing');
        $this->assertSame(1, $markets['KW']['integrations']['channels']['late_resources'], 'no success inside the window is late');
        $this->assertSame(0, $markets['AE']['integrations']['channels']['accounts'], 'a market with no account says zero');
    }

    public function test_failing_and_late_are_different_questions(): void
    {
        $markets = collect($this->health()->json('data.markets'))->keyBy('market');

        $this->assertSame(1, $markets['SA']['integrations']['channels']['failing_resources']);
        $this->assertSame(0, $markets['SA']['integrations']['channels']['late_resources'], 'it failed three times AND succeeded inside the window');
    }

    /**
     * The carriers of a market, told apart from its sales channels.
     *
     * Both answer "is this integration working", and they answer it from different tables with different columns, so the two figures are reported
     * separately and the sentence beside each says how it was worked out. A single "failing integrations" number would have added a count of three
     * failures in a row to a count of last-event-was-a-failure.
     */
    public function test_the_carriers_of_a_market_are_reported_apart_from_its_channels(): void
    {
        $markets = collect($this->health()->json('data.markets'))->keyBy('market');
        $body = $this->health()->json('data');

        $this->assertArrayHasKey('carriers', $markets['SA']['integrations']);
        $this->assertArrayHasKey('channels', $markets['SA']['integrations']);
        $this->assertSame(
            0,
            $markets['SA']['integrations']['carriers']['connections'],
            'this platform records no market for a carrier until storefronts are on, so its carriers are unattributed',
        );
        $this->assertSame(2, $body['unattributed']['integrations']['carriers']['connections'], 'the two shipped carriers');
        $this->assertStringContainsString(
            'different test',
            $this->health()->json('data.means')['integrations.carriers.failing_capabilities'],
            'the answer must say that this figure is not made the same way as the channels one',
        );
    }

    public function test_a_carriers_last_event_decides_whether_it_is_failing(): void
    {
        DB::table('provider_sync_states')->insert([
            ['provider' => 'torod_point', 'capability' => 'orders', 'last_success_at' => now()->subHour(), 'last_failure_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['provider' => 'torod_point', 'capability' => 'tracking', 'last_success_at' => now(), 'last_failure_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
            ['provider' => 'm5azn', 'capability' => 'orders', 'last_success_at' => null, 'last_failure_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $carriers = $this->health()->json('data.unattributed.integrations.carriers');

        $this->assertSame(2, $carriers['failing_capabilities'], 'the one that failed after succeeding, and the one that never succeeded');
        $this->assertSame(
            1,
            $carriers['late_capabilities'],
            'only the one that never succeeded: the other two succeeded inside the window, and failing is not the same question as late',
        );
    }

    public function test_the_answer_says_what_each_figure_means(): void
    {
        $means = $this->health()->json('data.means');

        $this->assertStringContainsString('never confirmed', $means['payments.stuck_attempts']);
        $this->assertStringContainsString('closed the tab', $means['payments.stuck_attempts'], 'the figure is not a count of failures and must not be read as one');
        $this->assertStringContainsString('in a row', $means['integrations.channels.failing_resources']);
        $this->assertStringContainsString('never counted as the default market', $means['unattributed']);
    }

    /** The whole body, read for anything that is not a count or a timestamp. */
    public function test_the_answer_carries_no_amount_no_customer_no_reference_and_no_credential(): void
    {
        $body = $this->health()->json();
        $text = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach (['199.50', '45.000', 'alharbi@example.test', 'txn-sa-old-1', 'a-secret-token', 'Visa'] as $mustNotAppear) {
            $this->assertStringNotContainsString($mustNotAppear, (string) $text, "the answer must not carry {$mustNotAppear}");
        }
    }

    public function test_a_market_an_operator_is_not_granted_is_not_in_their_answer(): void
    {
        $kuwaitOnly = $this->mintToken($this->platformAdmin(
            countries: ['KW'],
            grantOverrides: ['capabilities' => json_encode([PlatformAdminGrant::CAPABILITY_HEALTH])],
        ));

        $markets = collect($this->getJson(self::URL, $this->bearer($kuwaitOnly))->json('data.markets'))->pluck('market')->all();

        $this->assertSame(['KW'], $markets);
    }

    private function health(string $query = ''): TestResponse
    {
        return $this->getJson(self::URL . $query, $this->bearer($this->healthToken()));
    }

    private function healthToken(): string
    {
        return $this->mintToken($this->platformAdmin(
            grantOverrides: ['capabilities' => json_encode([PlatformAdminGrant::CAPABILITY_HEALTH])],
        ));
    }

    /**
     * Four orders in three states of market, four stuck payment attempts, one attempt that is not old enough, one paid, one wallet top-up, and two
     * sales-channel accounts whose sync resources are failing, late and healthy.
     */
    private function buildHealthWorld(): void
    {
        $this->createPaymentRequestsTable();
        $this->createSalesChannelTables();
        $this->makeOrdersWithMarkets();
        $this->makePaymentAttempts();
        $this->makeChannelAccounts();
    }

    /** The sales-channel and provider-fulfilment tables have migrations of their own; the platform-admin world does not run them. */
    private function createSalesChannelTables(): void
    {
        if (!Schema::hasTable('sales_channel_accounts')) {
            (require database_path('migrations/2026_11_07_100100_create_sales_channel_tables.php'))->up();
        }

        if (!Schema::hasTable('provider_sync_states')) {
            (require database_path('migrations/2026_11_05_100000_create_provider_fulfillment_tables.php'))->up();
        }
    }

    private function makeOrdersWithMarkets(): void
    {
        if (!Schema::hasColumn('orders', 'storefront_country')) {
            (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        }

        foreach ([[901, 'SA'], [902, 'SA'], [903, 'SA'], [904, 'KW'], [905, null]] as [$id, $market]) {
            DB::table('orders')->updateOrInsert(
                ['id' => $id],
                ['customer_id' => 1, 'order_status' => 'pending', 'payment_status' => 'unpaid', 'storefront_country' => $market, 'order_amount' => 100, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function makePaymentAttempts(): void
    {
        $old = now()->subHours(2);
        $recent = now()->subMinute();

        foreach ([
            ['sa-old-1', 'order', '901', $old, false],
            ['sa-old-2', 'order', '902', $old, false],
            ['sa-new-1', 'order', '903', $recent, false],
            ['kw-old-1', 'order', '904', $old, false],
            ['no-market-1', 'order', '905', $old, false],
            ['wallet-1', 'wallet', '1', $old, false],
        ] as [$id, $attribute, $attributeId, $when, $paid]) {
            DB::table('payment_requests')->updateOrInsert(
                ['id' => $id],
                [
                    'attribute' => $attribute, 'attribute_id' => $attributeId, 'is_paid' => $paid,
                    'payment_amount' => 199.50, 'currency_code' => 'SAR', 'payment_method' => 'Visa',
                    'transaction_id' => 'txn-' . $id, 'created_at' => $when, 'updated_at' => $when,
                ],
            );
        }
    }

    private function makeChannelAccounts(): void
    {
        $saudi = DB::table('sales_channel_accounts')->insertGetId([
            'channel' => 'amazon', 'name' => 'Amazon SA', 'market' => 'SA', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $kuwait = DB::table('sales_channel_accounts')->insertGetId([
            'channel' => 'noon', 'name' => 'Noon KW', 'market' => 'KW', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sales_channel_sync_states')->insert([
            ['account_id' => $saudi, 'resource' => 'orders', 'consecutive_failures' => 3, 'last_success_at' => now()->subHour(), 'last_failure_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['account_id' => $kuwait, 'resource' => 'orders', 'consecutive_failures' => 0, 'last_success_at' => now()->subYear(), 'last_failure_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
