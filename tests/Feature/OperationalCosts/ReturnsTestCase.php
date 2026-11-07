<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\ExternalOrderLine;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Returns\InvalidReturnDecision;
use App\Services\OperationalCosts\Returns\ReturnDecisionResult;
use App\Services\OperationalCosts\Returns\ReturnDecisionService;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\CreatesOperationalCostData;
use Tests\Support\CreatesReturnableSales;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/**
 * What the tests of returned units share: a Trendyol account, an own warehouse, a shipped order line of 3 units sold for 300.00 from stock bought at 10.00 each (so its cost of
 * goods is 30.00), and the means to decide about its returns and to look at the shelf and the ledger. The figures are invented.
 */
abstract class ReturnsTestCase extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;
    use CreatesOperationalCostData;
    use CreatesReturnableSales;

    protected const TY = 'https://apigw.trendyol.com/integration';

    protected SalesChannelAccount $account;

    protected InventoryLocation $warehouse;

    protected ReturnDecisionService $returns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildAuditLogTable();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR', 'sales_channels.enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
        $this->account = $this->trendyolAccount();
        $this->costChannelAccounts['trendyol'] = $this->account;
        $this->warehouse = $this->stockLocation('Own warehouse', type: InventoryLocation::TYPE_OWN, provider: null);
        $this->returns = app(ReturnDecisionService::class);
    }

    protected function soldLine(int $stock = 10, string $status = 'shipped'): ExternalOrderLine
    {
        return $this->returnableSale($this->account, $this->warehouse, $stock, $status);
    }

    protected function cogsNet(ExternalOrderLine $line): string
    {
        return Money::sum(OperationalCostEntry::where('subject_type', 'channel_order_line')->where('subject_id', $line->id)->where('cost_component', 'cogs')->where('is_effective', true)->pluck('amount'))->toStorage();
    }

    protected function onShelf(): int
    {
        return (int) InventoryCostLayer::where('status', 'open')->sum('quantity_remaining');
    }

    protected function decide(ExternalOrderLine $line, string $event, int $restock, int $writeOff = 0, ?int $location = null, ?int $admin = 1): ReturnDecisionResult
    {
        return $this->returns->decide($line, $event, $restock, $writeOff, $location ?? $this->warehouse->id, $admin, 'checked at the counter');
    }

    /** @param callable(): mixed $action */
    protected function assertRefused(string $reason, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected the decision to be refused: {$reason}.");
        } catch (InvalidReturnDecision $refused) {
            $this->assertSame($reason, $refused->reason);
        }
    }

    /**
     * What the channel answers each successive reading of the claims with (the last answer goes on being given): one claim, `claim-1`, whose items have the statuses given.
     *
     * @param string|list<string> ...$readings
     */
    protected function claimsAre(string|array ...$readings): void
    {
        $readings = array_map(static fn (string|array $statuses): array => is_array($statuses) ? $statuses : [$statuses, $statuses], $readings);
        $page = static fn (array $statuses): array => Trendyol::claimsPage([Trendyol::claim($statuses, 'claim-1')]);
        $sequence = Http::sequence();
        foreach ($readings as $statuses) {
            $sequence->push($page($statuses));
        }
        Http::fake([self::TY . '/order/sellers/1234567/claims*' => $sequence->whenEmpty(Http::response($page(end($readings))))]);
    }

    /** The channel accepts, or not, the two items of its claim. */
    protected function claimIsAccepted(string $status = 'Accepted'): void
    {
        $this->claimsAre($status);
    }

    protected function autoRestock(bool $on): void
    {
        $this->account->forceFill(['settings' => array_replace((array) $this->account->settings, ['auto_restock_accepted_returns' => $on])])->save();
        $this->account = $this->account->fresh();
    }
}
