<?php

namespace App\Services\PlatformAdmin;

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

    public function __construct(private readonly PlatformMarkets $markets)
    {
    }

    /** @return array<string, mixed> */
    public function build(MarketSelection $selection, PlatformWindow $window): array
    {
        $stuck = $this->stuckPaymentsByMarket(window: $window);
        $syncs = $this->channelSyncByMarket(window: $window);

        return [
            'scope' => $selection->scope,
            'market' => $selection->requested,
            'generated_at' => PlatformAdminPresenter::timestamp(moment: now()),
            'window' => $window->toArray(),
            'freshness' => ['mode' => 'live', 'cached' => false],
            'means' => $this->whatTheFiguresMean(),
            'markets' => array_map(
                fn (string $market): array => $this->healthOf(market: $market, stuck: $stuck, syncs: $syncs),
                $selection->markets,
            ),
            'unattributed' => $selection->includesUnattributed
                ? $this->healthOf(market: null, stuck: $stuck, syncs: $syncs) + ['reason' => 'no_market_recorded']
                : null,
        ];
    }

    /**
     * @param array<string, int> $stuck
     * @param array<string, array{accounts: int, failing: int, late: int, last_success_at: ?string}> $syncs
     *
     * @return array<string, mixed>
     */
    private function healthOf(?string $market, array $stuck, array $syncs): array
    {
        $key = $market ?? '';
        $sync = $syncs[$key] ?? ['accounts' => 0, 'failing' => 0, 'late' => 0, 'last_success_at' => null];

        return [
            'market' => $market,
            'legal_entity' => $market === null ? null : $this->markets->legalEntity(),
            'payments' => ['stuck_attempts' => $stuck[$key] ?? 0],
            'integrations' => [
                'channel_accounts' => $sync['accounts'],
                'failing_resources' => $sync['failing'],
                'late_resources' => $sync['late'],
                'last_success_at' => $sync['last_success_at'],
            ],
        ];
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
            'payments.stuck_attempts' => 'started, never confirmed, and older than ' . self::STUCK_AFTER_MINUTES . ' minutes; a shopper who closed the tab leaves the same row, so watch it for a change rather than reading it as failures',
            'integrations.failing_resources' => 'resources that have failed ' . self::FAILING_AFTER_ATTEMPTS . ' times in a row',
            'integrations.late_resources' => 'resources with no success inside this window',
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
