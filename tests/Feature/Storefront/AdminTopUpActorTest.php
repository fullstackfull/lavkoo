<?php

namespace Tests\Feature\Storefront;

use App\Events\AddFundToWalletEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsLedgerWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * E-05 criterion 2, through the real route: a ledger row of a manual top-up in the admin panel records the administrator who made it. The administrator is whoever is signed
 * in under the admin guard. Nothing the request carries can name another one, and the amount, the balance and the reference of the top-up are what they always were.
 */
class AdminTopUpActorTest extends TestCase
{
    use BuildsLedgerWorld;
    use RestoresLanguageFiles;

    private const ROUTE = 'admin.customer.wallet.add-fund';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildLedgerWorld();
        $this->ledgerCustomer(id: 1, wallet: 50.0);
        Event::fake([AddFundToWalletEvent::class]);
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, Localization::class, DetectMobile::class,
            ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropLedgerWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function signInAdmin(int $id): void
    {
        $this->actingAs((new Admin())->forceFill(['id' => $id]), 'admin');
    }

    /** @param array<string, mixed> $input */
    private function topUp(array $input = []): void
    {
        $this->postJson(route(self::ROUTE), $input + ['customer_id' => 1, 'amount' => 25])->assertOk()->assertJsonPath('message', translate('transaction_successful'));
    }

    /** @return array<string, array{int}> */
    public static function administrators(): array
    {
        return ['the first administrator' => [4], 'another one' => [17]];
    }

    #[DataProvider('administrators')]
    public function test_the_ledger_row_of_a_top_up_names_the_administrator_signed_in(int $administrator): void
    {
        $this->signInAdmin(id: $administrator);

        $this->topUp();

        $row = $this->newestLedgerRow();
        $this->assertSame(['admin', $administrator], [$row['actor_type'], $row['actor_id']]);
        $this->assertEqualsWithDelta(75.0, (float) DB::table('users')->where('id', 1)->value('wallet_balance'), 0.0001);
        $this->assertSame([25.0, 0.0, 75.0, 'add_fund_by_admin', 'add_fund_by_admin'], [$row['credit'], $row['debit'], $row['balance'], $row['transaction_type'], $row['reference']]);
    }

    public function test_nothing_the_request_carries_can_name_another_administrator_or_another_kind_of_actor(): void
    {
        $this->signInAdmin(id: 4);

        $this->topUp(input: ['admin_id' => 99, 'actor_id' => 99, 'actor_type' => 'seller', 'created_by' => 99, 'user_id' => 99, 'edited_user_id' => 99, 'source_type' => 'order', 'source_id' => '1', 'storefront_country' => 'KW', 'currency_code' => 'KWD', 'legal_entity_id' => 9]);

        $attribution = array_intersect_key($this->newestLedgerRow(), $this->emptyAttribution());
        $expected = ['actor_type' => 'admin', 'actor_id' => 4] + $this->emptyAttribution();
        ksort($attribution);
        ksort($expected);
        $this->assertSame($expected, $attribution, 'the guard names the actor and the request names nothing else');
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    public function test_another_guard_that_somebody_is_signed_in_under_is_not_the_actor(): void
    {
        $this->signInAdmin(id: 4);
        $this->actingAs((new Seller())->forceFill(['id' => 9]), 'seller');

        $this->topUp();

        $row = $this->newestLedgerRow();
        $this->assertSame(['admin', 4], [$row['actor_type'], $row['actor_id']]);
    }

    public function test_the_reference_the_administrator_typed_is_still_the_reference_and_the_actor_is_still_the_guard(): void
    {
        $this->signInAdmin(id: 4);

        $this->topUp(input: ['reference' => 'goodwill for order 100201']);

        $row = $this->newestLedgerRow();
        $this->assertSame(['goodwill for order 100201', 'admin', 4], [$row['reference'], $row['actor_type'], $row['actor_id']]);
    }

    public function test_nobody_signed_in_means_no_actor_is_recorded_and_the_top_up_is_made_all_the_same(): void
    {
        $this->topUp();

        $row = $this->newestLedgerRow();
        $this->assertSame([null, null], [$row['actor_type'], $row['actor_id']]);
        $this->assertSame(75.0, (float) DB::table('users')->where('id', 1)->value('wallet_balance'));
    }

    public function test_a_top_up_belongs_to_no_storefront_even_with_every_market_switch_on(): void
    {
        $this->registerEntity(country: 'SA');
        $this->switchMarketsOn(currency: true, entities: true);
        $this->signInAdmin(id: 4);

        $this->topUp();

        $row = $this->newestLedgerRow();
        $this->assertSame([null, null, null, null, null], [$row['source_type'], $row['source_id'], $row['storefront_country'], $row['currency_code'], $row['legal_entity_id']]);
    }

    public function test_before_the_migration_the_top_up_is_made_exactly_as_it_always_was(): void
    {
        $this->dropLedgerWorld();
        $this->buildLedgerWorld(attributed: false);
        $this->ledgerCustomer(id: 1, wallet: 50.0);
        $this->signInAdmin(id: 4);

        $this->topUp();

        $this->assertSame(
            ['user_id' => 1, 'transaction_id' => '<uuid>', 'credit' => 25.0, 'debit' => 0.0, 'admin_bonus' => 0.0, 'balance' => 75.0, 'transaction_type' => 'add_fund_by_admin', 'payment_method' => null, 'reference' => 'add_fund_by_admin', 'created_at' => '2026-12-05 10:00:00', 'updated_at' => '2026-12-05 10:00:00'],
            $this->newestLedgerRow(),
        );
        $this->assertSame(75.0, (float) DB::table('users')->where('id', 1)->value('wallet_balance'));
    }
}
