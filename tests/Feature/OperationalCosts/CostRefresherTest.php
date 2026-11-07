<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Contributors\CostContributor;
use App\Services\OperationalCosts\CostRefresher;
use App\Services\OperationalCosts\Sales\SaleDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/** Which sales the scheduled refresh looks at, and that one sale it cannot handle never hides the others. */
class CostRefresherTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00'));
        RecordingContributor::$seen = [];
        RecordingContributor::$failOn = null;
        config(['operational_costs.contributors' => [RecordingContributor::class]]);
    }

    private function channelOrder(string $externalId, string $purchasedAt, string $changedAt): ExternalOrderMapping
    {
        $account = SalesChannelAccount::first() ?? SalesChannelAccount::create(['channel' => 'trendyol', 'name' => 'T']);
        $mapping = ExternalOrderMapping::create([
            'account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => $externalId, 'external_package_id' => '1', 'status' => 'new',
            'currency' => 'SAR', 'purchased_at' => $purchasedAt,
        ]);
        DB::table('external_order_mappings')->where('id', $mapping->id)->update(['updated_at' => $changedAt]);

        return $mapping;
    }

    private function storeOrder(int $id, string $createdAt, string $updatedAt): void
    {
        DB::table('orders')->insert(['id' => $id, 'order_status' => 'pending', 'payment_status' => 'paid', 'seller_is' => 'admin', 'created_at' => $createdAt, 'updated_at' => $updatedAt]);
        DB::table('order_details')->insert(['order_id' => $id, 'product_id' => 50, 'qty' => 1, 'price' => 10]);
    }

    public function test_a_sale_that_changed_today_is_looked_at_again_however_long_ago_it_was_placed(): void
    {
        $this->channelOrder('untouched', '2026-10-01 10:00:00', '2026-10-01 10:00:00');
        $this->channelOrder('delivered-today', '2026-11-01 10:00:00', '2026-11-10 09:00:00');
        $this->channelOrder('placed-today', '2026-11-10 08:00:00', '2026-11-10 08:00:00');
        $this->storeOrder(1, '2026-10-01 10:00:00', '2026-10-01 10:00:00');
        $this->storeOrder(2, '2026-11-02 10:00:00', '2026-11-10 10:00:00');

        $result = app(CostRefresher::class)->refreshChangedSince(CarbonImmutable::parse('2026-11-07'));

        $this->assertEqualsCanonicalizing(['order:2', 'channel_order:' . $this->idOf('delivered-today'), 'channel_order:' . $this->idOf('placed-today')], RecordingContributor::$seen);
        $this->assertSame(['documents' => 3, 'written' => 0, 'failed' => 0], $result);
    }

    public function test_a_period_still_means_the_sales_placed_in_it(): void
    {
        $this->channelOrder('old-but-changed', '2026-10-01 10:00:00', '2026-11-10 09:00:00');
        $this->channelOrder('placed-in-period', '2026-11-08 10:00:00', '2026-11-08 10:00:00');

        app(CostRefresher::class)->refreshPeriod(CarbonImmutable::parse('2026-11-07'), CarbonImmutable::parse('2026-11-10'));

        $this->assertSame(['channel_order:' . $this->idOf('placed-in-period')], RecordingContributor::$seen);
    }

    public function test_one_sale_that_cannot_be_brought_up_to_date_does_not_hide_the_ones_behind_it(): void
    {
        $first = $this->channelOrder('poisoned', '2026-11-10 08:00:00', '2026-11-10 08:00:00');
        $this->channelOrder('fine-1', '2026-11-10 08:30:00', '2026-11-10 08:30:00');
        $this->channelOrder('fine-2', '2026-11-10 09:00:00', '2026-11-10 09:00:00');
        RecordingContributor::$failOn = 'channel_order:' . $first->id;

        $result = app(CostRefresher::class)->refreshChangedSince(CarbonImmutable::parse('2026-11-07'));

        $this->assertSame(['documents' => 3, 'written' => 0, 'failed' => 1], $result);
        $this->assertCount(3, RecordingContributor::$seen, 'The sales after the failing one were all looked at.');
    }

    public function test_the_command_says_so_and_fails_when_a_sale_could_not_be_brought_up_to_date(): void
    {
        $first = $this->channelOrder('poisoned', '2026-11-10 08:00:00', '2026-11-10 08:00:00');
        $this->channelOrder('fine', '2026-11-10 09:00:00', '2026-11-10 09:00:00');
        RecordingContributor::$failOn = 'channel_order:' . $first->id;

        $this->artisan('costs:refresh', ['--days' => 3])->expectsOutputToContain('1 sales could not be brought up to date')->assertFailed();
        $this->assertCount(2, RecordingContributor::$seen);
    }

    private function idOf(string $externalId): int
    {
        return (int) ExternalOrderMapping::where('external_order_id', $externalId)->value('id');
    }
}

/** Remembers which sales it was asked about, and can be told to fail on one. */
final class RecordingContributor implements CostContributor
{
    /** @var list<string> */
    public static array $seen = [];

    public static ?string $failOn = null;

    public function contribute(SaleDocument $document): array
    {
        $key = $document->subjectType . ':' . $document->subjectId;
        self::$seen[] = $key;
        if ($key === self::$failOn) {
            throw new QueryException('mysql', 'insert into operational_cost_entries', [], new \Exception('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded'));
        }

        return [];
    }
}
