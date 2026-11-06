<?php

namespace Tests\Feature\SalesChannels;

use App\Console\ScheduleDefinition;
use App\Models\ExternalOrderMapping;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

class SalesChannelsCommandsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const TY = 'https://apigw.trendyol.com/integration';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => false]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
    }

    public function test_with_the_global_switch_off_nothing_is_read_or_written(): void
    {
        config(['sales_channels.enabled' => false]);
        $this->trendyolAccount();
        Http::fake();

        $this->artisan('channels:sync')->expectsOutputToContain('switched off')->assertSuccessful();
        $this->artisan('channels:publish')->expectsOutputToContain('switched off')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_sync_reads_the_chosen_resource_of_the_chosen_account(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([self::TY . '/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);

        $this->artisan('channels:sync', ['account' => $account->id, '--only' => 'orders'])->expectsOutputToContain('orders: ok')->assertSuccessful();

        $this->assertSame(1, ExternalOrderMapping::count());
    }

    public function test_without_a_named_resource_every_resource_the_account_can_read_is_read_returns_included(): void
    {
        $account = $this->trendyolAccount(['connection_test', 'read_only_sync', 'order_import'], writes: false);
        Http::fake([
            self::TY . '/order/sellers/1234567/claims*' => Http::response(Trendyol::claimsPage([])),
            self::TY . '/*' => Http::response(Trendyol::stream([Trendyol::package()])),
        ]);

        $this->artisan('channels:sync', ['account' => $account->id])
            ->expectsOutputToContain('orders: ok')
            ->expectsOutputToContain('returns: ok')
            ->assertSuccessful();

        $this->assertSame(1, ExternalOrderMapping::count());
    }

    public function test_a_resource_named_but_not_readable_is_reported_as_skipped_and_an_unknown_name_is_refused(): void
    {
        $amazon = $this->amazonAccount();
        Http::fake();

        $this->artisan('channels:sync', ['account' => $amazon->id, '--only' => 'returns'])->expectsOutputToContain('returns: skipped (not_supported_by_channel)')->assertSuccessful();
        $this->artisan('channels:sync', ['--only' => 'orderz'])->expectsOutputToContain('Unknown resource: orderz')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_a_failing_channel_makes_the_command_fail_loudly(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([self::TY . '/*' => Http::response('', 500)]);

        $this->artisan('channels:sync', ['account' => $account->id, '--only' => 'orders'])->expectsOutputToContain('orders: failed')->assertFailed();
    }

    public function test_the_connection_test_command_shows_and_records_what_was_found(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([
            self::TY . '/product/*' => Http::response(Trendyol::approvedPage([])),
            self::TY . '/order/*' => Http::response(Trendyol::stream([])),
            self::TY . '/finance/*' => Http::response(Trendyol::statement([])),
        ]);

        $this->artisan('channels:test-connection', ['account' => $account->id])->expectsOutputToContain('Granted: ')->assertSuccessful();

        $this->assertSame('ok', $account->fresh()->connection_status);
        $this->assertNotNull($account->fresh()->connection_checked_at);
    }

    public function test_an_account_that_is_off_cannot_be_tested_and_nothing_is_sent(): void
    {
        $account = $this->trendyolAccount(['connection_test']);
        $account->forceFill(['enabled' => false])->save();
        Http::fake();

        $this->artisan('channels:test-connection', ['account' => $account->id])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_status_lists_the_accounts_and_the_switches(): void
    {
        $this->trendyolAccount();

        $this->artisan('channels:status')->expectsOutputToContain('Global switch')->expectsOutputToContain('Trendyol KSA')->assertSuccessful();
    }

    public function test_publish_on_a_dry_run_account_only_rehearses(): void
    {
        $account = $this->trendyolAccount(writes: false);
        Http::fake();

        $this->artisan('channels:publish', ['account' => $account->id, '--only' => 'stock,price,settle'])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_cost_commands_run(): void
    {
        $this->artisan('costs:refresh', ['--days' => 2])->expectsOutputToContain('sales checked')->assertSuccessful();
        $this->artisan('costs:accrue-storage')->expectsOutputToContain('storage cost entries')->assertSuccessful();
    }

    public function test_everything_is_on_the_schedule_and_nothing_is_a_write_without_its_own_command(): void
    {
        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);
        $commands = array_map(static fn ($event): string => (string) $event->command, $schedule->events());

        foreach (['channels:sync --due', 'channels:publish --only=stock,price,shipments', 'channels:publish --only=settle', 'costs:refresh --days=3', 'costs:accrue-storage'] as $expected) {
            $this->assertNotEmpty(array_filter($commands, static fn (string $command): bool => str_contains($command, $expected)), "{$expected} is not scheduled.");
        }
        $this->assertEmpty(array_filter($commands, static fn (string $command): bool => str_contains($command, 'channels:sync') && str_contains($command, 'publish')), 'Reading and writing are separate commands.');
    }
}
