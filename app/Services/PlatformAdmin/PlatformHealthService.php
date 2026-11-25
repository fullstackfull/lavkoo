<?php

namespace App\Services\PlatformAdmin;

use App\Models\OperationalCostReconciliation;
use App\Services\Fulfillment\Contracts\ProviderIdentity;
use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\ProviderStorefrontCountry;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational health per market: the payment attempts that never came back, and the sales-channel integrations that have stopped syncing (L-09).
 *
 * The overview answers what a market SOLD. This answers whether the machinery of that market is working, and it answers in COUNTS AND TIMESTAMPS ONLY --
 * no credential, no customer, no amount, no gateway reference. That is a deliberate limit, not an omission: the capability that opens this endpoint is
 * meant to be safe to give an operator who is not trusted with money or with customers, and a monitor read on a phone over somebody else's network is the
 * worst place to put either.
 *
 * **What a figure means.** A payment attempt is STUCK when it was started, is not marked paid, and is older than half an hour -- long enough that a
 * shopper still typing their card number is not counted, short enough to see a gateway that has stopped calling back. A shopper who simply closed the tab
 * leaves exactly the same row, so this is a number to watch for a CHANGE in, never a count of failures; the endpoint says so in `means`. A sync resource
 * is FAILING when it has failed three times in a row, and LATE when it has not succeeded within the window asked for: the two are different questions and
 * a resource can be neither, either or both.
 *
 * **How a market is decided.** A payment attempt belongs to the market of the order it was for (`orders.storefront_country`), which is the market whose
 * gateway took it; a sales-channel account carries its own market (`sales_channel_accounts.market`). Anything whose market is not recorded is counted
 * apart as unattributed and NEVER as Saudi Arabia -- the mistake that would make a market look busy because another one is. An attempt that is not for an
 * order at all (a wallet top-up) has no market here and is left out rather than guessed at.
 *
 * Every figure is a grouped aggregate over a bounded range, in the shape `PlatformMarketFigures` established, and a table that has not been created yet
 * answers zero rather than failing -- so a Saudi installation, whose sales-channel tables are empty, reads zeros, which is the truth about it.
 */
#[Scoped]
final class PlatformHealthService
{
    /** A payment attempt older than this that is still unpaid is stuck. Shorter would count shoppers who are still typing. */
    public const STUCK_AFTER_MINUTES = 30;

    /** A sync resource that has failed this many times in a row is failing, not merely late. */
    public const FAILING_AFTER_ATTEMPTS = 3;

    /** What `attribute` says of a payment attempt that was made for an order. */
    private const FOR_AN_ORDER = 'order';

    /** The column an order keeps its market in. */
    private const MARKET_COLUMN = 'storefront_country';

    /** The reconciliation states somebody has reached, none of which is a break. A dispute is open with the supplier, not on this platform's desk. */
    private const SETTLED_RECONCILIATIONS = [
        OperationalCostReconciliation::MATCHED,
        OperationalCostReconciliation::RESOLVED,
        OperationalCostReconciliation::DISPUTED,
    ];

    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly ProviderRegistry $carriers,
        private readonly ProviderStorefrontCountry $carrierMarket,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(MarketSelection $selection, PlatformWindow $window): array
    {
        $stuck = $this->stuckPaymentsByMarket(window: $window);
        $breaks = $this->reconciliationBreaksByMarket();
        $syncs = $this->channelSyncByMarket(window: $window);
        $carriers = $this->carrierSyncByMarket(window: $window);

        return [
            'scope' => $selection->scope,
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'window' => $window->toArray(),
            'freshness' => ['mode' => 'live', 'cached' => false],
            'means' => $this->whatTheFiguresMean(),
            'markets' => array_map(
                fn (string $market): array => $this->healthOf(market: $market, stuck: $stuck, breaks: $breaks, syncs: $syncs, carriers: $carriers),
                $selection->markets,
            ),
            'unattributed' => $selection->includesUnattributed
                ? $this->healthOf(market: null, stuck: $stuck, breaks: $breaks, syncs: $syncs, carriers: $carriers) + ['reason' => 'no_market_recorded']
                : null,
        ];
    }

    /**
     * @param array<string, int> $stuck
     * @param array<string, int> $breaks
     * @param array<string, array{accounts: int, failing: int, late: int, last_success_at: ?string}> $syncs
     * @param array<string, array{connections: int, failing: int, late: int, last_success_at: ?string}> $carriers
     *
     * @return array<string, mixed>
     */
    private function healthOf(?string $market, array $stuck, array $breaks, array $syncs, array $carriers): array
    {
        $key = $market ?? '';
        $sync = $syncs[$key] ?? ['accounts' => 0, 'failing' => 0, 'late' => 0, 'last_success_at' => null];
        $carrier = $carriers[$key] ?? ['connections' => 0, 'failing' => 0, 'late' => 0, 'last_success_at' => null];

        return [
            'market' => $market,
            'legal_entity' => $this->legalEntityOf(market: $market),
            'payments' => [
                'stuck_attempts' => $stuck[$key] ?? 0,
                'reconciliation_breaks' => $breaks[$key] ?? 0,
            ],
            'integrations' => [
                'channels' => [
                    'accounts' => $sync['accounts'],
                    'failing_resources' => $sync['failing'],
                    'late_resources' => $sync['late'],
                    'last_success_at' => $sync['last_success_at'],
                ],
                'carriers' => [
                    'connections' => $carrier['connections'],
                    'failing_capabilities' => $carrier['failing'],
                    'late_capabilities' => $carrier['late'],
                    'last_success_at' => $carrier['last_success_at'],
                ],
            ],
        ];
    }

    /**
     * Carrier sync health by market: how many of this installation's carriers belong to the market, how many of their capabilities last FAILED rather than
     * succeeded, how many have not succeeded inside the window, and the most recent success of any of them.
     *
     * `provider_sync_states` keeps no failure counter, only the moments, so "failing" here means the last thing that happened to that capability was a
     * failure -- a different test from the sales channels' three failures in a row, and said so in `means` rather than left for a reader to assume the two
     * numbers were made the same way.
     *
     * A carrier's market is the one its connection serves (`ProviderStorefrontCountry`), which answers nothing at all while the platform records no
     * markets -- so on a one-country installation every carrier is unattributed, which is the truth about it.
     *
     * @return array<string, array{connections: int, failing: int, late: int, last_success_at: ?string}>
     */
    private function carrierSyncByMarket(PlatformWindow $window): array
    {
        // A carrier this installation knows is a connection to report whether or not a sync has ever been recorded for it. The table being absent means
        // nothing has synced, not that there are no carriers, so the connections are counted either way and their figures read zero.
        $recorded = Schema::hasTable('provider_sync_states');
        $health = [];

        foreach ($this->carriers->identities() as $carrier) {
            $key = $this->marketKeyOf($this->carrierMarket->of($carrier->code()));
            $health[$key] ??= ['connections' => 0, 'failing' => 0, 'late' => 0, 'last_success_at' => null];
            $health[$key]['connections']++;

            if (!$recorded) {
                continue;
            }

            foreach (DB::table('provider_sync_states')->where('provider', $carrier->code())->get() as $state) {
                $health[$key] = $this->withTheStateOf(state: $state, window: $window, health: $health[$key]);
            }
        }

        return $health;
    }

    /**
     * @param array{connections: int, failing: int, late: int, last_success_at: ?string} $health
     *
     * @return array{connections: int, failing: int, late: int, last_success_at: ?string}
     */
    private function withTheStateOf(object $state, PlatformWindow $window, array $health): array
    {
        $succeeded = $state->last_success_at === null ? null : CarbonImmutable::parse($state->last_success_at);
        $failed = $state->last_failure_at === null ? null : CarbonImmutable::parse($state->last_failure_at);

        if ($failed !== null && ($succeeded === null || $failed->greaterThan($succeeded))) {
            $health['failing']++;
        }

        if ($succeeded === null || $succeeded->lessThan($window->startsAt())) {
            $health['late']++;
        }

        if ($succeeded !== null && ($health['last_success_at'] === null || $succeeded->greaterThan(CarbonImmutable::parse($health['last_success_at'])))) {
            $health['last_success_at'] = PlatformAdminPresenter::timestamp(moment: $succeeded);
        }

        return $health;
    }

    /**
     * The cost reconciliations that are not settled, by the market the cost belongs to.
     *
     * A BREAK is a reconciliation whose state is not one somebody has closed: a variance nobody has accepted, an invoice line with no estimate behind it,
     * an estimate with no invoice line against it. `matched`, `resolved` and `disputed` are all states a person has reached -- a dispute is open with the
     * supplier, not open on this platform's desk -- so none of them is a break.
     *
     * The window does NOT bound this one, and that is on purpose: a reconciliation break does not age out of being a break. A variance from last month that
     * nobody has accepted is still money this platform cannot account for, and dropping it out of the figure when the window moved would make the number
     * fall by itself. Every other figure here is about a window; this one is about a backlog, and the sentence beside it says so.
     *
     * The market comes from the cost entry the reconciliation is for: its sales-channel account carries one, and its order carries one. An entry with
     * neither is counted apart, never as the default market.
     *
     * @return array<string, int>
     */
    private function reconciliationBreaksByMarket(): array
    {
        if (!Schema::hasTable('operational_cost_reconciliations') || !Schema::hasTable('operational_cost_entries')) {
            return [];
        }

        $counts = [];

        $rows = DB::table('operational_cost_reconciliations as r')
            ->leftJoin('operational_cost_entries as e', 'e.id', '=', 'r.estimated_entry_id')
            ->whereNotIn('r.status', self::SETTLED_RECONCILIATIONS)
            ->select(['e.account_id', 'e.order_id'])
            ->get();

        foreach ($rows as $row) {
            $key = $this->marketKeyOf($this->marketOfCostEntry($row));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The legal company that sells in this market, as `legal_entities` records it (D1-03).
     *
     * Named here rather than taken from `gcc.storefronts.legal.legal_entity`, which is ONE value for the whole platform: repeating it under every market
     * would say that six markets are sold by one company, which is the opposite of the plan's own decision that each country has a selling company of
     * its own. A market with no entity recorded answers null, and so does the unattributed row -- rows nobody attributed belong to no company.
     */
    private function legalEntityOf(?string $market): ?string
    {
        if ($market === null || !Schema::hasTable('legal_entities')) {
            return null;
        }

        $name = DB::table('legal_entities')->where('country', $market)->value('legal_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** The market a cost entry belongs to: its sales-channel account's, or its order's, or none. */
    private function marketOfCostEntry(object $entry): ?string
    {
        $account = $entry->account_id ?? null;

        if ($account !== null && Schema::hasTable('sales_channel_accounts')) {
            $market = DB::table('sales_channel_accounts')->where('id', $account)->value('market');

            if ($market !== null && trim((string) $market) !== '') {
                return (string) $market;
            }
        }

        $order = $entry->order_id ?? null;

        if ($order !== null && Schema::hasTable('orders') && Schema::hasColumn('orders', self::MARKET_COLUMN)) {
            return DB::table('orders')->where('id', $order)->value(self::MARKET_COLUMN);
        }

        return null;
    }

    /**
     * Payment attempts started in the window, never marked paid, and older than the grace period, by the market of the order they were for.
     *
     * The window is what bounds this: `payment_requests` carries no index on `created_at`, so a caller that asked for all of time would scan the table,
     * and `PlatformWindow` is what stops it. The join is on the orders primary key.
     *
     * @return array<string, int>
     */
    private function stuckPaymentsByMarket(PlatformWindow $window): array
    {
        if (!Schema::hasTable('payment_requests') || !Schema::hasTable('orders')) {
            return [];
        }

        $attempts = DB::table('payment_requests')
            ->join('orders', 'orders.id', '=', 'payment_requests.attribute_id')
            ->where('payment_requests.attribute', self::FOR_AN_ORDER)
            ->where('payment_requests.is_paid', false)
            ->where('payment_requests.created_at', '>=', $window->startsAt())
            ->where('payment_requests.created_at', '<', $this->stuckBefore($window));

        // Before the storefront migration no order records a market, so every attempt is unattributed. Said in one
        // branch rather than by building a query with NULL in its GROUP BY, which each engine reads its own way.
        if (!Schema::hasColumn('orders', self::MARKET_COLUMN)) {
            $total = (int) $attempts->count();

            return $total === 0 ? [] : ['' => $total];
        }

        $counts = [];

        foreach ($attempts->groupBy('orders.' . self::MARKET_COLUMN)->selectRaw('orders.' . self::MARKET_COLUMN . ' as market, COUNT(*) as attempts')->get() as $row) {
            $counts[$this->marketKeyOf($row->market ?? null)] = (int) $row->attempts;
        }

        return $counts;
    }

    /**
     * Sales-channel accounts and their sync resources by market: how many accounts, how many resources are failing, how many are late, and the most recent
     * success of any of them.
     *
     * @return array<string, array{accounts: int, failing: int, late: int, last_success_at: ?string}>
     */
    private function channelSyncByMarket(PlatformWindow $window): array
    {
        if (!Schema::hasTable('sales_channel_accounts')) {
            return [];
        }

        $health = [];

        foreach (DB::table('sales_channel_accounts')->select(['id', 'market'])->get() as $account) {
            $key = $this->marketKeyOf($account->market ?? null);
            $health[$key] ??= ['accounts' => 0, 'failing' => 0, 'late' => 0, 'last_success_at' => null];
            $health[$key]['accounts']++;
        }

        if ($health === [] || !Schema::hasTable('sales_channel_sync_states')) {
            return $health;
        }

        $rows = DB::table('sales_channel_sync_states')
            ->join('sales_channel_accounts', 'sales_channel_accounts.id', '=', 'sales_channel_sync_states.account_id')
            ->groupBy('sales_channel_accounts.market')
            ->selectRaw('sales_channel_accounts.market as market')
            ->selectRaw('SUM(CASE WHEN sales_channel_sync_states.consecutive_failures >= ? THEN 1 ELSE 0 END) as failing', [self::FAILING_AFTER_ATTEMPTS])
            ->selectRaw('SUM(CASE WHEN sales_channel_sync_states.last_success_at IS NULL OR sales_channel_sync_states.last_success_at < ? THEN 1 ELSE 0 END) as late', [$window->startsAt()])
            ->selectRaw('MAX(sales_channel_sync_states.last_success_at) as last_success_at')
            ->get();

        foreach ($rows as $row) {
            $key = $this->marketKeyOf($row->market ?? null);
            $health[$key] ??= ['accounts' => 0, 'failing' => 0, 'late' => 0, 'last_success_at' => null];
            $health[$key]['failing'] = (int) $row->failing;
            $health[$key]['late'] = (int) $row->late;
            $health[$key]['last_success_at'] = $row->last_success_at === null
                ? null
                : PlatformAdminPresenter::timestamp(moment: CarbonImmutable::parse($row->last_success_at));
        }

        return $health;
    }

    /** What each figure means, sent with the figures so that a screen cannot invent a meaning for them. */
    private function whatTheFiguresMean(): array
    {
        return [
            'payments.reconciliation_breaks' => 'cost reconciliations nobody has settled -- a variance not accepted, an invoice line with no estimate, an estimate with no invoice line. A BACKLOG and not a window: one from last month is still money this platform cannot account for, so this figure does not fall when the window moves',
            'payments.stuck_attempts' => 'started, never confirmed, and older than ' . self::STUCK_AFTER_MINUTES . ' minutes; a shopper who closed the tab leaves the same row, so watch it for a change rather than reading it as failures',
            'integrations.channels.failing_resources' => 'resources that have failed ' . self::FAILING_AFTER_ATTEMPTS . ' times in a row',
            'integrations.channels.late_resources' => 'resources with no success inside this window',
            'integrations.carriers.failing_capabilities' => 'capabilities whose LAST event was a failure; the carrier table keeps no failure counter, so this is a different test from the channels above',
            'integrations.carriers.late_capabilities' => 'capabilities with no success inside this window',
            'unattributed' => 'rows whose market is not recorded; never counted as the default market',
        ];
    }

    private function stuckBefore(PlatformWindow $window): CarbonImmutable
    {
        $grace = CarbonImmutable::instance(now())->subMinutes(self::STUCK_AFTER_MINUTES);

        return $grace->lessThan($window->endsBefore()) ? $grace : CarbonImmutable::instance($window->endsBefore());
    }

    /** The key a market's figures are kept under: its code, or the empty string for "no market recorded". */
    private function marketKeyOf(mixed $value): string
    {
        return (string) ($this->markets->normalise($value) ?? '');
    }
}
