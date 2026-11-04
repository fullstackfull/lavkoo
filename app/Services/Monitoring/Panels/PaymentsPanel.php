<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Analytics\Support\AnalyticsPolicy;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payments: what the shop recorded taking, and everywhere the record contradicts itself.
 *
 * Two halves that must never be read as one. The top half is VOLUME — the payment events analytics
 * writes, folded by gateway. The bottom half is INTEGRITY — nine reconciliations between the
 * gateway ledger, the orders table and the settlement ledger, each of which is a defect when it
 * returns a row. They are separated because they answer different questions and, more importantly,
 * because only one of them has a trustworthy denominator.
 *
 * The denominator is the reason this page is shaped the way it is. Until the change that ships with
 * it, `digital_payment_fail()` in app/Utils/module-helper.php had an EMPTY BODY: a declined card, a
 * timed-out gateway and a shopper who closed the tab all left one row in payment_requests with
 * is_paid = 0 and nothing else, indistinguishable from each other and from an attempt that never
 * happened. Failures are recorded from now on. They were not recorded before. So a success rate has
 * no denominator for any window that reaches back past the first recorded failure, and this panel
 * refuses to print one there rather than dividing by a number that does not exist — a 100% success
 * rate computed over zero recorded failures is the single most dangerous figure this section could
 * carry. `payment_started` is still emitted by nothing at all, so abandonment before the gateway
 * answered remains invisible and is declared rather than assumed to be zero.
 *
 * Cost is the other constraint that shows on the surface. payment_requests carries NO INDEX AND NO
 * PRIMARY KEY, and order_transactions, paytabs_invoices, refund_transactions and offline_payments
 * are indexed on their own id and nothing else — every read of them is a full table scan. So this
 * page reconciles ONE bounded sample: the orders created inside the selected window, newest first,
 * up to a fixed cap, read on idx_orders_created_at. Everything else is looked up by that sample's
 * ids and references, one scan per table per page load, and only when the table is small enough to
 * scan safely. A table over the ceiling is not read at all: the block says so and names the index
 * that would make it readable, because an admin page must not be able to take the shop down.
 */
class PaymentsPanel implements Panel
{
    /**
     * The orders this page reconciles, newest first.
     *
     * The sample is the page. Every finding below is a statement about these orders and no others,
     * which is stated once at the top rather than qualified nine times — and it is what keeps the
     * unindexed lookups bounded, since each of them is keyed on this set.
     */
    private const MAX_ORDERS = 500;

    /** Rows listed under a finding. The count above the list is the real one, up to the sample. */
    private const MAX_SAMPLE_ROWS = 20;

    /** Distinct gateways in the volume table. A shop with more than this has a naming problem. */
    private const MAX_GATEWAY_ROWS = 40;

    /** addon_settings rows read. It is a settings table of fixed size, not a growing one. */
    private const MAX_GATEWAY_SETTINGS = 200;

    /** Captured gateway rows folded when looking for money that never became an order. */
    private const MAX_CAPTURES = 500;

    /** PayTabs attempt rows folded into the approve/decline counts. */
    private const MAX_DECLINE_ROWS = 2000;

    /** Refund rows read out of the window. */
    private const MAX_REFUND_ROWS = 500;

    /** Points on the volume chart, counted in buckets rather than rows. */
    private const MAX_TIMELINE_POINTS = 120;

    /**
     * The largest table this page will scan without an index.
     *
     * Chosen so the worst case stays inside an admin page's budget: six unindexed tables at this
     * ceiling is a few hundred thousand row reads, tens of milliseconds on InnoDB. Above it the
     * read is refused and the block names the CREATE INDEX that would make it cheap. The estimate
     * itself comes from information_schema and is approximate for InnoDB, which is why the ceiling
     * is well under anything that would hurt rather than at the edge of it.
     */
    private const MAX_UNINDEXED_ROWS = 50000;

    /**
     * How long a captured payment is allowed to take to become an order.
     *
     * The order is written by the success hook after the gateway callback returns, so a capture
     * seconds old with no order is a request in flight rather than lost money.
     */
    private const CAPTURE_GRACE_MINUTES = 15;

    /** Money is compared to the cent. Below this two decimal(40,20) columns are the same amount. */
    private const MONEY_TOLERANCE = 0.01;

    /** orders.transaction_ref is varchar(30); payment_requests.transaction_id is varchar(100). */
    private const REFERENCE_WIDTH = 30;

    /** The payment methods that never travel through a gateway, so they can never fail through one. */
    private const OFFLINE_METHODS = ['cash_on_delivery', 'offline_payment', 'wallet_payment', 'wallet'];

    /** The three events Analytics::paymentAttempted() can write. */
    private const EVENT_NAMES = ['payment_started', 'payment_succeeded', 'payment_failed'];

    /** The order statuses that mean the goods reached the customer. */
    private const DELIVERED_STATUSES = ['delivered'];

    /** order_transactions.status only ever holds one of these two. */
    private const SETTLEMENT_STATUSES = ['hold', 'disburse'];

    /** PayTabs calls this approved. Everything else it returns is a decline or an error. */
    private const PAYTABS_APPROVED = 100;

    /** What a finding row points at. Panel-authored, so the view may translate it. */
    private const FINDING_KINDS = ['order', 'payment_request', 'settlement', 'refund'];

    /** The nine reconciliations, in the order the page draws them. */
    private const FINDINGS = [
        'captured_without_order',
        'paid_without_gateway_record',
        'gateway_amount_mismatch',
        'duplicate_capture',
        'duplicate_settlement_rows',
        'paid_without_settlement',
        'delivered_cod_not_disbursed',
        'commission_does_not_reconcile',
        'refund_without_payment',
    ];

    private const ORDERS_SOURCE = 'MySQL orders';

    private const GATEWAY_SOURCE = 'MySQL payment_requests';

    private const SETTLEMENT_SOURCE = 'MySQL order_transactions';

    private const EVENTS_SOURCE = 'analytics_events (payment_started, payment_succeeded, payment_failed)';

    /** One size verdict per table per request, so the guard is never paid for twice. */
    private array $scans = [];

    /**
     * The configured gateway list, read once.
     *
     * Every payment method on the page is classified against it, so without this the settings
     * table would be read once per gateway per fold — the shape of slow page this section is
     * supposed to be watching for.
     *
     * @var array<string, mixed>|null
     */
    private ?array $configured = null;

    /**
     * The exception the volume read threw, kept because a Metric wants the throwable itself.
     *
     * The read hands its failure to the tables as a note. Re-wrapping that note to build a failed
     * card would print the word RuntimeException in front of the QueryException the operator has
     * to act on, so the original is carried across instead.
     */
    private ?\Throwable $volumeFailure = null;

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $sample = $this->sample($range);
        $ledger = $this->ledger($range, $sample);
        $recording = $this->recording($range);
        $volume = $this->volume($range);
        $gateways = $this->gateways($volume);
        $rate = $this->rate($volume, $recording);
        $findings = $this->findings($range, $sample, $ledger);
        $declines = $this->declines($range);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
                // Orders, payments and analytics events are written by the shop with Carbon::now(),
                // which runs on app.timezone — not on monitoring's UTC axis. The bounds of every
                // query on this page are converted into that clock before they are bound, and the
                // clock is published so a reading three hours out has somewhere to be traced to.
                'shop_timezone' => date_default_timezone_get(),
            ],
            'scope' => $sample['scope'],
            'recording' => $recording,
            'headline' => $this->headline($sample, $volume, $recording, $rate, $findings),
            'gateways' => $gateways,
            'volume' => $volume,
            'timeline' => $this->timeline($range, $window),
            'rate' => $rate,
            'findings' => $findings,
            'declines' => $declines,
            'unrecorded' => $this->unrecorded(),
            'scans' => $this->scanReport(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The bounded sample every finding is a statement about

    /**
     * The orders created inside the window, newest first.
     *
     * Rides idx_orders_created_at (created_at). This is the only unbounded-in-principle table the
     * page touches and it is the one with an index for the job, so it is the driver: every other
     * read is keyed on the ids and references this returns.
     *
     * @return array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}
     */
    private function sample(string $range): array
    {
        try {
            $rows = $this->shop()->table('orders')
                ->where('created_at', '>=', $this->shopSince($range))
                ->orderByDesc('created_at')
                ->limit(self::MAX_ORDERS + 1)
                ->get([
                    'id', 'payment_status', 'order_status', 'payment_method', 'transaction_ref',
                    'order_amount', 'paid_amount', 'admin_commission', 'created_at', 'updated_at',
                ]);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing the sample costs the nine
            // reconciliations, while letting it escape would blank the recorded volume above them,
            // which is read from a different table on a different connection and is perfectly fine.
            return [
                'scope' => [
                    'state' => 'failed',
                    'note' => $this->failureNote($exception),
                    'remedy' => null,
                    'source' => self::ORDERS_SOURCE,
                    'orders_examined' => null,
                    'limit' => self::MAX_ORDERS,
                    'truncated' => false,
                    'oldest_order_at' => null,
                    'newest_order_at' => null,
                    'index' => 'idx_orders_created_at',
                ],
                'rows' => [],
            ];
        }

        $truncated = $rows->count() > self::MAX_ORDERS;
        $orders = [];
        foreach ($rows->take(self::MAX_ORDERS) as $row) {
            $reference = trim((string) ($row->transaction_ref ?? ''));
            $method = trim((string) ($row->payment_method ?? ''));

            $orders[] = [
                'id' => (int) $row->id,
                'payment_status' => (string) $row->payment_status,
                'order_status' => (string) $row->order_status,
                'payment_method' => $method === '' ? null : mb_substr($method, 0, 100),
                'offline_method' => in_array(mb_strtolower($method), self::OFFLINE_METHODS, true),
                'reference' => $reference === '' ? null : mb_substr($reference, 0, self::REFERENCE_WIDTH),
                'order_amount' => (float) $row->order_amount,
                'paid_amount' => (float) $row->paid_amount,
                'admin_commission' => (float) $row->admin_commission,
                'created_at' => $this->shopDisplay($row->created_at),
                'updated_at' => $this->shopDisplay($row->updated_at),
            ];
        }

        $stamps = array_values(array_filter(array_column($orders, 'created_at')));

        return [
            'scope' => [
                'state' => $orders === [] ? 'no_data' : 'ok',
                'note' => $orders === []
                    ? 'No order was created inside this window, so there is nothing on this page to reconcile. That is a statement about this window only — it is not a reading of zero defects.'
                    : null,
                'remedy' => $orders === [] ? 'Widen the range. Every reconciliation below covers the orders created inside the selected window and no others.' : null,
                'source' => self::ORDERS_SOURCE,
                'orders_examined' => count($orders),
                'limit' => self::MAX_ORDERS,
                'truncated' => $truncated,
                'oldest_order_at' => $stamps === [] ? null : min($stamps),
                'newest_order_at' => $stamps === [] ? null : max($stamps),
                'index' => 'idx_orders_created_at',
            ],
            'rows' => $orders,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Everything the sample has to be reconciled against

    /**
     * The gateway, settlement, offline, commission and refund rows belonging to the sample.
     *
     * One read per table, each keyed on the sample rather than on a date, because none of these
     * tables has an index on a date. Four of the five are full scans and are refused outright when
     * the table is too big to scan; the fifth rides oic_order_index.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @return array<string, mixed>
     */
    private function ledger(string $range, array $sample): array
    {
        $ids = array_column($sample['rows'], 'id');
        $references = array_values(array_filter(array_column($sample['rows'], 'reference')));

        return [
            'captures' => $this->capturedInWindow($range),
            'requests' => $this->paymentRequestsFor($references),
            'offline' => $this->offlinePaymentsFor($ids),
            'settlements' => $this->settlementsFor($ids),
            'commissions' => $this->commissionsFor($ids),
            'refunds' => $this->refundsInWindow($range),
        ];
    }

    /**
     * Gateway rows marked paid inside the window, whether or not an order exists for them.
     *
     * The one read on this page that is driven by payment_requests rather than by orders, because
     * the finding it feeds is precisely the money that never reached the orders table.
     *
     * @return array<string, mixed>
     */
    private function capturedInWindow(string $range): array
    {
        $guard = $this->scannable('payment_requests');
        if ($guard['state'] !== 'ok') {
            return $this->blockedRead($guard);
        }

        try {
            $rows = $this->shop()->table('payment_requests')
                ->where('is_paid', 1)
                ->where('attribute', 'order')
                ->where('created_at', '>=', $this->shopSince($range))
                ->where('created_at', '<', $this->shopStamp(Clock::minutesAgo(self::CAPTURE_GRACE_MINUTES)))
                ->limit(self::MAX_CAPTURES + 1)
                ->get(['transaction_id', 'payment_method', 'payment_amount', 'currency_code', 'created_at', 'payment_platform']);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $captures = [];
        foreach ($rows->take(self::MAX_CAPTURES) as $row) {
            $captures[] = $this->presentRequest($row);
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $captures,
            'truncated' => $rows->count() > self::MAX_CAPTURES,
        ];
    }

    /**
     * Gateway rows carrying one of the sample's references.
     *
     * @param  array<int, string>  $references
     * @return array<string, mixed>
     */
    private function paymentRequestsFor(array $references): array
    {
        if ($references === []) {
            // 'ok' with no rows, not 'no_data'. Nothing to look up IS the answer, and treating it
            // as an unread measurement would silence the finding it feeds: a paid order carrying no
            // gateway reference at all is exactly what the reconciliation below is looking for.
            return ['state' => 'ok', 'note' => 'No order in this window carries a gateway reference, so no gateway row could be looked up.', 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $guard = $this->scannable('payment_requests');
        if ($guard['state'] !== 'ok') {
            return $this->blockedRead($guard);
        }

        try {
            $rows = $this->shop()->table('payment_requests')
                ->whereIn('transaction_id', $references)
                ->limit(self::MAX_ORDERS * 4 + 1)
                ->get(['transaction_id', 'payment_method', 'payment_amount', 'currency_code', 'is_paid', 'created_at', 'payment_platform']);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $limit = self::MAX_ORDERS * 4;
        $requests = [];
        foreach ($rows->take($limit) as $row) {
            $requests[] = $this->presentRequest($row);
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $requests,
            'truncated' => $rows->count() > $limit,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRequest(object $row): array
    {
        $reference = trim((string) ($row->transaction_id ?? ''));
        $method = trim((string) ($row->payment_method ?? ''));

        return [
            'reference' => $reference === '' ? null : mb_substr($reference, 0, 100),
            // A reference longer than orders.transaction_ref can hold could never have been written
            // there in full, so a missing order for it is a column-width artefact rather than a
            // finding. Flagged here so the reconciliation can exclude it instead of reporting it.
            'reference_too_long' => mb_strlen($reference) > self::REFERENCE_WIDTH,
            'gateway' => $method === '' ? null : mb_substr($method, 0, 50),
            'amount' => (float) $row->payment_amount,
            'currency' => $this->shortText($row->currency_code ?? null, 20),
            'paid' => property_exists($row, 'is_paid') ? (bool) $row->is_paid : true,
            'platform' => $this->shortText($row->payment_platform ?? null, 40),
            'created_at' => $this->shopDisplay($row->created_at),
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    private function offlinePaymentsFor(array $ids): array
    {
        if ($ids === []) {
            return ['state' => 'ok', 'note' => 'No order in this window to look an offline payment up for.', 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $guard = $this->scannable('offline_payments');
        if ($guard['state'] !== 'ok') {
            return $this->blockedRead($guard);
        }

        try {
            $rows = $this->shop()->table('offline_payments')
                ->whereIn('order_id', $ids)
                ->limit(self::MAX_ORDERS * 2 + 1)
                ->get(['order_id']);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $limit = self::MAX_ORDERS * 2;
        $orders = [];
        foreach ($rows->take($limit) as $row) {
            $orders[] = (int) $row->order_id;
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => array_values(array_unique($orders)),
            'truncated' => $rows->count() > $limit,
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    private function settlementsFor(array $ids): array
    {
        if ($ids === []) {
            return ['state' => 'ok', 'note' => 'No order in this window to look a settlement row up for.', 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $guard = $this->scannable('order_transactions');
        if ($guard['state'] !== 'ok') {
            return $this->blockedRead($guard);
        }

        try {
            $rows = $this->shop()->table('order_transactions')
                ->whereIn('order_id', $ids)
                ->limit(self::MAX_ORDERS * 4 + 1)
                ->get(['order_id', 'status', 'order_amount', 'seller_amount', 'admin_commission', 'received_by', 'delivered_by', 'created_at']);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $limit = self::MAX_ORDERS * 4;
        $settlements = [];
        foreach ($rows->take($limit) as $row) {
            $status = trim((string) ($row->status ?? ''));

            $settlements[] = [
                'order_id' => (int) $row->order_id,
                'status' => $status === '' ? null : mb_substr($status, 0, 40),
                'status_known' => in_array($status, self::SETTLEMENT_STATUSES, true),
                'order_amount' => (float) $row->order_amount,
                'seller_amount' => (float) $row->seller_amount,
                'admin_commission' => (float) $row->admin_commission,
                'created_at' => $this->shopDisplay($row->created_at),
            ];
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $settlements,
            'truncated' => $rows->count() > $limit,
        ];
    }

    /**
     * The frozen per-line commission for the sample's orders.
     *
     * The one auxiliary read on this page that rides an index: oic_order_index (order_id).
     *
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    private function commissionsFor(array $ids): array
    {
        if ($ids === []) {
            return ['state' => 'ok', 'note' => 'No order in this window to look a commission line up for.', 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        try {
            $connection = $this->shop();
            $rows = $connection->table('order_item_commissions')
                ->whereIn('order_id', $ids)
                ->groupBy('order_id')
                ->limit(self::MAX_ORDERS + 1)
                ->get([
                    'order_id',
                    $connection->raw('SUM(commission_amount) AS commission_total'),
                    $connection->raw('SUM(reversed_amount) AS reversed_total'),
                    // Aliased line_count, not lines: LINES is a reserved word in MariaDB and an
                    // unquoted alias of it is a syntax error rather than a column.
                    $connection->raw('COUNT(*) AS line_count'),
                ]);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $commissions = [];
        foreach ($rows->take(self::MAX_ORDERS) as $row) {
            $commissions[(int) $row->order_id] = [
                'commission_total' => (float) $row->commission_total,
                'reversed_total' => (float) $row->reversed_total,
                'lines' => (int) $row->line_count,
            ];
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $commissions,
            'truncated' => $rows->count() > self::MAX_ORDERS,
        ];
    }

    /**
     * Refunds recorded inside the window.
     *
     * @return array<string, mixed>
     */
    private function refundsInWindow(string $range): array
    {
        $guard = $this->scannable('refund_transactions');
        if ($guard['state'] !== 'ok') {
            return $this->blockedRead($guard);
        }

        try {
            $rows = $this->shop()->table('refund_transactions')
                ->where('created_at', '>=', $this->shopSince($range))
                ->limit(self::MAX_REFUND_ROWS + 1)
                ->get(['id', 'order_id', 'amount', 'payment_method', 'payment_status', 'created_at']);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'rows' => [], 'truncated' => false];
        }

        $refunds = [];
        foreach ($rows->take(self::MAX_REFUND_ROWS) as $row) {
            $refunds[] = [
                'id' => (int) $row->id,
                'order_id' => $row->order_id === null ? null : (int) $row->order_id,
                'amount' => (float) $row->amount,
                'gateway' => $this->shortText($row->payment_method ?? null, 50),
                'payment_status' => $this->shortText($row->payment_status ?? null, 40),
                'created_at' => $this->shopDisplay($row->created_at),
            ];
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $refunds,
            'truncated' => $rows->count() > self::MAX_REFUND_ROWS,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Whether a rate has a denominator at all

    /**
     * What is and is not being recorded about a payment attempt, and since when.
     *
     * This block is the reason the rate below can be trusted or is withheld, so it is assembled
     * before anything is divided. Three separate facts, kept apart: whether analytics is collecting
     * at all, when the first failure was ever recorded, and whether anything emits a start.
     *
     * @return array<string, mixed>
     */
    private function recording(string $range): array
    {
        $base = [
            'source' => self::EVENTS_SOURCE,
            'failure_hook' => 'app/Utils/module-helper.php::digital_payment_fail()',
            'can_compute_rate' => null,
            'first_failure_at' => null,
            'first_success_at' => null,
            'first_start_at' => null,
            'starts_recorded' => null,
            'window_since' => Clock::display($this->reader->since($range))->toDateTimeString(),
            'retention_days' => app(AnalyticsPolicy::class)->retentionDays('event_days'),
            'window_days' => (int) round($this->reader->window($range)['minutes'] / 1440, 0),
        ];

        if (!app(AnalyticsPolicy::class)->enabled()) {
            return array_merge($base, [
                'state' => 'not_configured',
                'can_compute_rate' => false,
                'note' => 'Analytics collection is switched off, so no payment event of any kind is being written. Every count on this page that comes from analytics is a reading of that setting, not of the shop.',
                'remedy' => 'Set ANALYTICS_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ]);
        }

        try {
            $connection = $this->analytics();
            // MIN() on the leading columns of analytics_event_name_time (name, occurred_at) is an
            // index seek, not a scan: the first row of each name's range IS the answer.
            $firstFailure = $connection->table('analytics_events')->where('name', 'payment_failed')->min('occurred_at');
            $firstSuccess = $connection->table('analytics_events')->where('name', 'payment_succeeded')->min('occurred_at');
            $firstStart = $connection->table('analytics_events')->where('name', 'payment_started')->min('occurred_at');
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The analytics tables are created by `php artisan migrate`. Check the analytics connection is reachable and migrated.',
            ]);
        }

        $base = array_merge($base, [
            'first_failure_at' => $this->shopDisplay($firstFailure),
            'first_success_at' => $this->shopDisplay($firstSuccess),
            'first_start_at' => $this->shopDisplay($firstStart),
            'starts_recorded' => $firstStart !== null,
        ]);

        if ($firstFailure === null) {
            return array_merge($base, [
                'state' => 'no_data',
                'can_compute_rate' => false,
                'note' => 'Not one failed payment has ever been recorded on this deployment. Until the change that ships with this page, digital_payment_fail() had an empty body and a declined card left nothing behind at all, so this is not a reading of zero failures — it is the absence of the measurement. A success rate has no denominator and none is shown.',
                'remedy' => 'Nothing to enable: the hook is implemented in app/Utils/module-helper.php. The first real decline will record itself, and from that moment a rate becomes computable for windows that start after it.',
            ]);
        }

        $recordingSince = $this->shopMoment($firstFailure);
        $windowSince = $this->reader->since($range);

        if ($recordingSince === null) {
            return array_merge($base, [
                'state' => 'failed',
                'can_compute_rate' => null,
                'note' => 'The first recorded payment failure carries a timestamp that cannot be read, so whether this window is covered by failure recording cannot be established.',
                'remedy' => null,
            ]);
        }

        if ($recordingSince->greaterThan($windowSince)) {
            return array_merge($base, [
                'state' => 'no_data',
                'can_compute_rate' => false,
                'note' => 'Failed payments began being recorded inside this window, not before it. Dividing by the failures in the whole window would use a denominator that does not exist for the earlier part of it, so no rate is shown for this range.',
                'remedy' => 'Select a range that begins after ' . $this->shopDisplay($firstFailure) . ' (' . Clock::displayTimezone() . '), the first recorded failure on this deployment.',
            ]);
        }

        return array_merge($base, [
            'state' => 'ok',
            'can_compute_rate' => true,
            'note' => $firstStart === null
                ? 'Failure recording covers this whole window, so a settled-attempt rate is computable. It is not a checkout completion rate: nothing emits payment_started, so a shopper who left before the gateway answered is in neither half of the fraction.'
                : null,
            'remedy' => null,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Volume

    /**
     * The payment events recorded in the window, folded by gateway.
     *
     * Rides analytics_event_name_time (name, occurred_at): three index ranges, one per event name.
     *
     * @return array<string, mixed>
     */
    private function volume(string $range): array
    {
        if (!app(AnalyticsPolicy::class)->enabled()) {
            return [
                'state' => 'not_configured',
                'note' => 'Analytics collection is switched off, so no payment event is being written.',
                'remedy' => 'Set ANALYTICS_ENABLED=true in .env, then run `php artisan optimize:clear`.',
                'source' => self::EVENTS_SOURCE,
                'rows' => [],
                'totals' => $this->emptyTotals(),
                'truncated' => false,
            ];
        }

        try {
            $connection = $this->analytics();
            $limit = self::MAX_GATEWAY_ROWS * count(self::EVENT_NAMES) * 2;
            $rows = $connection->table('analytics_events')
                ->whereIn('name', self::EVENT_NAMES)
                ->where('occurred_at', '>=', $this->shopSince($range))
                ->groupBy('name', 'entity_id', 'currency')
                ->limit($limit + 1)
                ->get([
                    'name',
                    'entity_id',
                    'currency',
                    $connection->raw('COUNT(*) AS events'),
                    $connection->raw('SUM(value) AS amount'),
                ]);
        } catch (\Throwable $exception) {
            $this->volumeFailure = $exception;

            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The analytics tables are created by `php artisan migrate`. Check the analytics connection is reachable and migrated.',
                'source' => self::EVENTS_SOURCE,
                'rows' => [],
                'totals' => $this->emptyTotals(),
                'truncated' => false,
            ];
        }

        $limit = self::MAX_GATEWAY_ROWS * count(self::EVENT_NAMES) * 2;
        $byGateway = [];
        foreach ($rows->take($limit) as $row) {
            $gateway = $this->shortText($row->entity_id ?? null, 64) ?? '';
            $name = (string) $row->name;
            $events = (int) $row->events;
            $amount = $row->amount === null ? null : (float) $row->amount;
            $currency = $this->shortText($row->currency ?? null, 8);

            if (!isset($byGateway[$gateway])) {
                $byGateway[$gateway] = [
                    'gateway' => $gateway === '' ? null : $gateway,
                    'kind' => $this->gatewayKind($gateway),
                    'started' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'settled' => 0,
                    'captured' => [],
                    'declined_value' => [],
                ];
            }

            if ($name === 'payment_started') {
                $byGateway[$gateway]['started'] += $events;
            } elseif ($name === 'payment_succeeded') {
                $byGateway[$gateway]['succeeded'] += $events;
                $byGateway[$gateway]['captured'] = $this->addMoney($byGateway[$gateway]['captured'], $currency, $amount);
            } elseif ($name === 'payment_failed') {
                $byGateway[$gateway]['failed'] += $events;
                $byGateway[$gateway]['declined_value'] = $this->addMoney($byGateway[$gateway]['declined_value'], $currency, $amount);
            }
        }

        $totals = $this->emptyTotals();
        foreach ($byGateway as $key => $gateway) {
            $settled = $gateway['succeeded'] + $gateway['failed'];
            $byGateway[$key]['settled'] = $settled;
            $totals['started'] += $gateway['started'];
            $totals['succeeded'] += $gateway['succeeded'];
            $totals['failed'] += $gateway['failed'];
            $totals['settled'] += $settled;
            $totals['captured'] = $this->mergeMoney($totals['captured'], $gateway['captured']);
            if ($gateway['kind'] === 'gateway') {
                $totals['gateway_succeeded'] += $gateway['succeeded'];
                $totals['gateway_failed'] += $gateway['failed'];
            } elseif ($gateway['kind'] === 'offline') {
                $totals['offline_succeeded'] += $gateway['succeeded'];
            } else {
                $totals['unclassified_succeeded'] += $gateway['succeeded'];
            }
        }

        uasort($byGateway, static fn (array $a, array $b) => [$b['succeeded'], $b['failed']] <=> [$a['succeeded'], $a['failed']]);

        return [
            'state' => $byGateway === [] ? 'no_data' : 'ok',
            'note' => $byGateway === []
                ? 'No payment event was recorded inside this window. Payment events are written when an order is paid, when a payment status changes to paid, and — from this release onward — when a gateway declines.'
                : null,
            'remedy' => $byGateway === [] ? 'Widen the range. If the shop is taking orders and this stays empty, check ANALYTICS_ENABLED and that `php artisan schedule:run` is firing.' : null,
            'source' => self::EVENTS_SOURCE,
            'rows' => array_values($byGateway),
            'totals' => $totals,
            'truncated' => $rows->count() > $limit,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyTotals(): array
    {
        return [
            'started' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'settled' => 0,
            'gateway_succeeded' => 0,
            'gateway_failed' => 0,
            'offline_succeeded' => 0,
            'unclassified_succeeded' => 0,
            'captured' => [],
        ];
    }

    /**
     * Money summed per currency, never across them.
     *
     * @param  array<int, array{currency: string|null, amount: float}>  $money
     * @return array<int, array{currency: string|null, amount: float}>
     */
    private function addMoney(array $money, ?string $currency, ?float $amount): array
    {
        if ($amount === null) {
            return $money;
        }

        foreach ($money as $index => $entry) {
            if ($entry['currency'] === $currency) {
                $money[$index]['amount'] = round($entry['amount'] + $amount, 2);

                return $money;
            }
        }

        $money[] = ['currency' => $currency, 'amount' => round($amount, 2)];

        return $money;
    }

    /**
     * @param  array<int, array{currency: string|null, amount: float}>  $into
     * @param  array<int, array{currency: string|null, amount: float}>  $from
     * @return array<int, array{currency: string|null, amount: float}>
     */
    private function mergeMoney(array $into, array $from): array
    {
        foreach ($from as $entry) {
            $into = $this->addMoney($into, $entry['currency'], $entry['amount']);
        }

        return $into;
    }

    /**
     * Succeeded and failed over the window, drawn as a line.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $window): array
    {
        if (!app(AnalyticsPolicy::class)->enabled()) {
            return [
                'state' => 'not_configured',
                'note' => 'Analytics collection is switched off, so there is no series to draw.',
                'source' => self::EVENTS_SOURCE,
                'points' => [],
                'truncated' => false,
            ];
        }

        // The format is chosen from the reader's own resolution vocabulary, never from input.
        $format = match ($window['resolution']) {
            'day' => '%Y-%m-%d 00:00:00',
            'hour' => '%Y-%m-%d %H:00:00',
            default => '%Y-%m-%d %H:%i:00',
        };

        try {
            $connection = $this->analytics();
            $rows = $connection->table('analytics_events')
                ->whereIn('name', ['payment_succeeded', 'payment_failed'])
                ->where('occurred_at', '>=', $this->shopSince($range))
                ->groupBy('bucket', 'name')
                ->orderBy('bucket')
                ->limit(self::MAX_TIMELINE_POINTS * 2 + 1)
                ->get([
                    $connection->raw("DATE_FORMAT(occurred_at, '" . $format . "') AS bucket"),
                    'name',
                    $connection->raw('COUNT(*) AS events'),
                ]);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'source' => self::EVENTS_SOURCE,
                'points' => [],
                'truncated' => false,
            ];
        }

        $limit = self::MAX_TIMELINE_POINTS * 2;
        $buckets = [];
        foreach ($rows->take($limit) as $row) {
            $bucket = (string) $row->bucket;
            $buckets[$bucket] ??= ['hits' => 0, 'errors' => 0];
            if ((string) $row->name === 'payment_succeeded') {
                $buckets[$bucket]['hits'] += (int) $row->events;
            } else {
                $buckets[$bucket]['errors'] += (int) $row->events;
            }
        }

        $points = [];
        foreach ($buckets as $bucket => $counts) {
            $moment = $this->shopMoment($bucket);
            if ($moment === null) {
                continue;
            }

            $points[] = [
                't' => $moment->copy()->setTimezone(Clock::TIMEZONE)->toIso8601String(),
                'hits' => $counts['hits'],
                'errors' => $counts['errors'],
            ];
        }

        return [
            'state' => count($points) >= 2 ? 'ok' : 'no_data',
            'note' => count($points) >= 2
                ? null
                : ($points === []
                    ? 'No payment event was recorded inside this window, so there is no line to draw.'
                    : 'Only one bucket in this window holds a payment event. One sample is a reading, not a line.'),
            'source' => self::EVENTS_SOURCE,
            'points' => $points,
            'truncated' => $rows->count() > $limit,
        ];
    }

    /**
     * The success rate, or an explanation instead of one.
     *
     * Computed over settled attempts on GATEWAY methods only. Counting cash on delivery and wallet
     * payments in the numerator would be arithmetic on two different populations: they are recorded
     * as succeeded like any other payment and can never fail through the gateway failure hook, so
     * every one of them would push the rate towards 100% without ever being able to lower it.
     *
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $recording
     * @return array<string, mixed>
     */
    private function rate(array $volume, array $recording): array
    {
        $base = [
            'source' => self::EVENTS_SOURCE,
            'succeeded' => null,
            'failed' => null,
            'settled' => null,
            'rate' => null,
            'excluded_offline' => null,
            'excluded_unclassified' => null,
            'starts_recorded' => $recording['starts_recorded'] ?? null,
            'basis' => 'Settled gateway attempts only: succeeded / (succeeded + failed), over payment methods this deployment configures as gateways.',
            'caveat' => 'Nothing emits payment_started on this deployment, so a shopper who abandoned the gateway before it answered is in neither half of this fraction. This is not a checkout completion rate.',
        ];

        if ($volume['state'] === 'failed') {
            return array_merge($base, ['state' => 'failed', 'note' => $volume['note'], 'remedy' => null, 'rows' => []]);
        }

        if (($recording['can_compute_rate'] ?? null) !== true) {
            return array_merge($base, [
                'state' => $recording['state'] === 'ok' ? 'no_data' : $recording['state'],
                'note' => $recording['note'],
                'remedy' => $recording['remedy'],
                'rows' => [],
            ]);
        }

        $rows = [];
        $succeeded = 0;
        $failed = 0;
        $offline = 0;
        $unclassified = 0;

        foreach ($volume['rows'] as $gateway) {
            if ($gateway['kind'] === 'offline') {
                $offline += $gateway['succeeded'];

                continue;
            }
            if ($gateway['kind'] !== 'gateway') {
                $unclassified += $gateway['succeeded'];

                continue;
            }

            $settled = $gateway['succeeded'] + $gateway['failed'];
            $succeeded += $gateway['succeeded'];
            $failed += $gateway['failed'];

            $rows[] = [
                'gateway' => $gateway['gateway'],
                'succeeded' => $gateway['succeeded'],
                'failed' => $gateway['failed'],
                'settled' => $settled,
                // Zero settled attempts is not a zero rate. It is no rate.
                'success_rate' => $settled > 0 ? round(100 * $gateway['succeeded'] / $settled, 1) : null,
            ];
        }

        $settled = $succeeded + $failed;

        return array_merge($base, [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === []
                ? 'No payment on a configured gateway was recorded inside this window. Cash on delivery, wallet and offline payments are excluded from this rate by design, and they are the only ones this window holds.'
                : null,
            'remedy' => null,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'settled' => $settled,
            'rate' => $settled > 0 ? round(100 * $succeeded / $settled, 1) : null,
            'excluded_offline' => $offline,
            'excluded_unclassified' => $unclassified,
            'rows' => $rows,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Which gateways this shop has switched on

    /**
     * The configured payment gateways, with the mode each is running in.
     *
     * addon_settings is a settings table of fixed size — one row per gateway the build ships — so
     * it is read whole rather than sampled. It is also the only DB-backed answer to "is this
     * payment method a gateway", which is what keeps the success rate from mixing populations.
     *
     * @param  array<string, mixed>  $volume
     * @return array<string, mixed>
     */
    private function gateways(array $volume): array
    {
        $configured = $this->configuredGateways();

        if ($configured['state'] !== 'ok') {
            return array_merge($configured, ['rows' => [], 'live_count' => null, 'test_active' => []]);
        }

        $activity = [];
        foreach ($volume['rows'] as $row) {
            if ($row['gateway'] !== null) {
                $activity[mb_strtolower($row['gateway'])] = $row;
            }
        }

        // Whether this window's payment events were read at all. A read that threw and a deployment
        // that writes no payment event both leave nothing known about a gateway's traffic, which is
        // a different statement from "it took no payments" — so neither may be folded into a zero.
        $counted = in_array($volume['state'], ['ok', 'no_data'], true);

        $rows = [];
        $testActive = [];
        $liveCount = 0;
        foreach ($configured['rows'] as $key => $gateway) {
            $seen = $activity[$key] ?? null;

            if ($gateway['active'] && $gateway['mode'] === 'test') {
                $testActive[] = $gateway['gateway'];
            }
            if ($gateway['active'] && $gateway['mode'] === 'live') {
                $liveCount++;
            }

            $rows[] = array_merge($gateway, [
                'succeeded' => $counted ? (int) ($seen['succeeded'] ?? 0) : null,
                'failed' => $counted ? (int) ($seen['failed'] ?? 0) : null,
            ]);
        }

        usort($rows, static fn (array $a, array $b) => [$b['active'] ? 1 : 0, (int) ($b['succeeded'] ?? 0)]
            <=> [$a['active'] ? 1 : 0, (int) ($a['succeeded'] ?? 0)]);

        return array_merge($configured, [
            'rows' => $rows,
            'live_count' => $liveCount,
            'test_active' => $testActive,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function configuredGateways(): array
    {
        if ($this->configured !== null) {
            return $this->configured;
        }

        $source = 'MySQL addon_settings (settings_type=payment_config)';

        try {
            $rows = $this->shop()->table('addon_settings')
                ->where('settings_type', 'payment_config')
                ->limit(self::MAX_GATEWAY_SETTINGS + 1)
                ->get(['key_name', 'mode', 'is_active']);
        } catch (\Throwable $exception) {
            return $this->configured = [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'source' => $source,
                'rows' => [],
                'truncated' => false,
            ];
        }

        $gateways = [];
        foreach ($rows->take(self::MAX_GATEWAY_SETTINGS) as $row) {
            $key = mb_strtolower(trim((string) ($row->key_name ?? '')));
            if ($key === '') {
                continue;
            }

            $mode = mb_strtolower(trim((string) ($row->mode ?? '')));

            $gateways[$key] = [
                'gateway' => mb_substr($key, 0, 64),
                'active' => (bool) $row->is_active,
                'mode' => $mode === '' ? null : mb_substr($mode, 0, 20),
                // Only 'live' and 'test' may be handed to translate(); anything else in the column
                // is somebody's own value and is echoed as stored.
                'mode_known' => in_array($mode, ['live', 'test'], true),
            ];
        }

        return $this->configured = [
            'state' => $gateways === [] ? 'not_configured' : 'ok',
            'note' => $gateways === []
                ? 'No payment gateway is configured on this deployment, so no payment method can be classified as a gateway and no gateway success rate can be computed.'
                : null,
            'remedy' => $gateways === [] ? 'Configure a gateway in Admin → 3rd Party Setup → Payment Methods. Each one writes a row in addon_settings with settings_type=payment_config.' : null,
            'source' => $source,
            'rows' => $gateways,
            'truncated' => $rows->count() > self::MAX_GATEWAY_SETTINGS,
        ];
    }

    /**
     * Whether a payment method reaches a gateway, offline, or cannot be told.
     *
     * Three-valued deliberately. An unrecognised method is NOT assumed to be a gateway: it would be
     * folded into a success rate that only a gateway can lower, which is exactly the wrong way for
     * this page to be wrong.
     */
    private function gatewayKind(string $method): string
    {
        $method = mb_strtolower(trim($method));

        if ($method === '') {
            return 'unknown';
        }
        if (in_array($method, self::OFFLINE_METHODS, true)) {
            return 'offline';
        }

        $configured = $this->configuredGateways();
        if ($configured['state'] !== 'ok') {
            return 'unknown';
        }

        return array_key_exists($method, $configured['rows']) ? 'gateway' : 'unknown';
    }

    // -------------------------------------------------------------------------------------------
    // The nine reconciliations

    /**
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, array<string, mixed>>
     */
    private function findings(string $range, array $sample, array $ledger): array
    {
        return [
            'captured_without_order' => $this->capturedWithoutOrder($ledger),
            'paid_without_gateway_record' => $this->paidWithoutGatewayRecord($sample, $ledger),
            'gateway_amount_mismatch' => $this->amountMismatch($sample, $ledger),
            'duplicate_capture' => $this->duplicateCapture($sample, $ledger),
            'duplicate_settlement_rows' => $this->duplicateSettlements($sample, $ledger),
            'paid_without_settlement' => $this->paidWithoutSettlement($sample, $ledger),
            'delivered_cod_not_disbursed' => $this->codNotDisbursed($sample, $ledger),
            'commission_does_not_reconcile' => $this->commissionMismatch($sample, $ledger),
            'refund_without_payment' => $this->refundWithoutPayment($sample, $ledger),
        ];
    }

    /**
     * F1. A gateway says it took the money and no order carries its reference.
     *
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function capturedWithoutOrder(array $ledger): array
    {
        $means = 'The gateway recorded a completed payment and no order in this shop carries its reference. The customer has been charged and has nothing. Each of these is a refund or a manually created order, and it is the most expensive row on this page.';
        $action = 'Take the reference to the gateway dashboard, confirm the capture, then either create the order by hand or refund it. The success hook that should have written the order is the failure_hook/success_hook pair on the payment_requests row.';

        $captures = $ledger['captures'];
        if ($captures['state'] !== 'ok') {
            return $this->findingFrom($captures, self::GATEWAY_SOURCE, $means, $action);
        }

        $references = array_values(array_filter(
            array_map(static fn (array $row) => $row['reference_too_long'] ? null : $row['reference'], $captures['rows']),
        ));

        $matched = [];
        if ($references !== []) {
            try {
                // Rides idx_orders_transaction_ref (transaction_ref).
                $matched = $this->shop()->table('orders')
                    ->whereIn('transaction_ref', $references)
                    ->limit(count($references) + 1)
                    ->pluck('transaction_ref')
                    ->all();
            } catch (\Throwable $exception) {
                return $this->finding('failed', self::GATEWAY_SOURCE, $means, $action, note: $this->failureNote($exception));
            }
        }

        $matched = array_flip(array_map(static fn ($reference) => (string) $reference, $matched));

        $rows = [];
        $unjoinable = 0;
        foreach ($captures['rows'] as $capture) {
            if ($capture['reference'] === null) {
                $unjoinable++;

                continue;
            }
            if ($capture['reference_too_long']) {
                $unjoinable++;

                continue;
            }
            if (isset($matched[$capture['reference']])) {
                continue;
            }

            $rows[] = [
                'reference' => $capture['reference'],
                'kind' => 'payment_request',
                'gateway' => $capture['gateway'],
                'amount' => $capture['amount'],
                'currency' => $capture['currency'],
                'expected' => null,
                'occurred_at' => $capture['created_at'],
                'detail' => 'Captured by the gateway; no order carries this reference.',
            ];
        }

        $note = null;
        if ($unjoinable > 0) {
            $note = $unjoinable . ' captured gateway row(s) carry no reference, or one longer than orders.transaction_ref can hold (' . self::REFERENCE_WIDTH . ' characters). They are excluded rather than reported: an order could not have been matched to them either way, so their absence is a column-width fact rather than a finding.';
        }

        return $this->finding(
            state: 'ok',
            source: self::GATEWAY_SOURCE . ' ↔ orders.transaction_ref',
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$captures['truncated'],
            note: $note,
        );
    }

    /**
     * F2. An order says it was paid and no gateway or offline record backs it.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function paidWithoutGatewayRecord(array $sample, array $ledger): array
    {
        $means = 'The order is marked paid, its payment method is a gateway rather than cash or wallet, and neither the gateway ledger nor the offline-payment table holds a row for it. Either the money never arrived and the status is wrong, or it arrived through a path that records nothing.';
        $action = 'Reconcile each order against the gateway statement before shipping it. If the gateway is one of the fourteen curl controllers in app/Http/Controllers/Payment_Methods/, confirm its callback is writing payment_requests at all.';

        $blocked = $this->blockedBy($sample, [$ledger['requests'], $ledger['offline']], self::GATEWAY_SOURCE, $means, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $paidReferences = [];
        foreach ($ledger['requests']['rows'] as $request) {
            if ($request['paid'] && $request['reference'] !== null) {
                $paidReferences[$request['reference']] = true;
            }
        }
        $offline = array_flip($ledger['offline']['rows']);

        $rows = [];
        foreach ($sample['rows'] as $order) {
            if ($order['payment_status'] !== 'paid' || $order['offline_method']) {
                continue;
            }
            if ($order['reference'] !== null && isset($paidReferences[$order['reference']])) {
                continue;
            }
            if (isset($offline[$order['id']])) {
                continue;
            }

            $rows[] = [
                'reference' => (string) $order['id'],
                'kind' => 'order',
                'gateway' => $order['payment_method'],
                'amount' => $order['order_amount'],
                'currency' => null,
                'expected' => null,
                'occurred_at' => $order['created_at'],
                'detail' => $order['reference'] === null
                    ? 'Paid, but the order carries no gateway reference at all, so no gateway row could ever be matched to it.'
                    : 'Paid, reference ' . $order['reference'] . ', and no gateway row is marked paid for it.',
            ];
        }

        return $this->finding(
            state: 'ok',
            source: self::ORDERS_SOURCE . ' ↔ payment_requests, offline_payments',
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$sample['scope']['truncated'] && !$ledger['requests']['truncated'] && !$ledger['offline']['truncated'],
        );
    }

    /**
     * F3. The gateway captured one amount and the order totals another.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function amountMismatch(array $sample, array $ledger): array
    {
        $means = 'A gateway row marked paid carries a different amount from the order it belongs to. Either the order was edited after payment, the currency conversion moved, or the customer was charged the wrong figure.';
        $action = 'Compare the two amounts against the gateway statement. An order edited after capture should carry edit_due_amount or edit_return_amount; one that does not is a genuine discrepancy.';

        $blocked = $this->blockedBy($sample, [$ledger['requests']], self::GATEWAY_SOURCE, $means, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $paid = [];
        foreach ($ledger['requests']['rows'] as $request) {
            if ($request['paid'] && $request['reference'] !== null) {
                $paid[$request['reference']] = $request;
            }
        }

        $rows = [];
        foreach ($sample['rows'] as $order) {
            if ($order['reference'] === null || !isset($paid[$order['reference']])) {
                continue;
            }

            $request = $paid[$order['reference']];
            if (abs($order['order_amount'] - $request['amount']) <= self::MONEY_TOLERANCE) {
                continue;
            }

            $rows[] = [
                'reference' => (string) $order['id'],
                'kind' => 'order',
                'gateway' => $request['gateway'] ?? $order['payment_method'],
                'amount' => $request['amount'],
                'currency' => $request['currency'],
                'expected' => $order['order_amount'],
                'occurred_at' => $order['created_at'],
                'detail' => 'The gateway captured a different amount from the order total. The order table has no currency column, so only the amounts are compared — a difference here can also be a currency the gateway recorded and the order did not.',
            ];
        }

        return $this->finding(
            state: 'ok',
            source: self::ORDERS_SOURCE . ' ↔ payment_requests.payment_amount',
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$sample['scope']['truncated'] && !$ledger['requests']['truncated'],
        );
    }

    /**
     * F4. One order reference, more than one capture.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function duplicateCapture(array $sample, array $ledger): array
    {
        $means = 'More than one gateway row marked paid carries the same order reference. A callback that fired twice, or a customer who paid twice for one order. The shop has taken more money than the order is worth.';
        $action = 'Refund the extra capture at the gateway. There is no unique key preventing this — payment_requests has no index at all — so it can happen again until the callback path is made idempotent.';

        $blocked = $this->blockedBy($sample, [$ledger['requests']], self::GATEWAY_SOURCE, $means, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $byReference = [];
        foreach ($ledger['requests']['rows'] as $request) {
            if ($request['paid'] && $request['reference'] !== null) {
                $byReference[$request['reference']][] = $request;
            }
        }

        $orderByReference = [];
        foreach ($sample['rows'] as $order) {
            if ($order['reference'] !== null) {
                $orderByReference[$order['reference']] = $order;
            }
        }

        $rows = [];
        foreach ($byReference as $reference => $requests) {
            if (count($requests) < 2) {
                continue;
            }

            $total = 0.0;
            $currency = null;
            foreach ($requests as $request) {
                $total += $request['amount'];
                $currency ??= $request['currency'];
            }

            $order = $orderByReference[$reference] ?? null;

            $rows[] = [
                'reference' => (string) $reference,
                'kind' => 'payment_request',
                'gateway' => $requests[0]['gateway'],
                'amount' => round($total, 2),
                'currency' => $currency,
                'expected' => $order === null ? null : $order['order_amount'],
                'occurred_at' => $requests[0]['created_at'],
                'detail' => count($requests) . ' captures carry this reference.',
            ];
        }

        return $this->finding(
            state: 'ok',
            source: self::GATEWAY_SOURCE,
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$sample['scope']['truncated'] && !$ledger['requests']['truncated'],
        );
    }

    /**
     * F5. More than one settlement row for one order.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function duplicateSettlements(array $sample, array $ledger): array
    {
        $means = 'order_transactions holds more than one row for the same order. The table has no unique key on order_id — it has no index on it at all — so a retried settlement writes a second row, and the vendor is credited twice for one sale.';
        $action = 'Compare the rows against what the vendor was actually paid, delete the duplicate, and add the missing key: `ALTER TABLE order_transactions ADD UNIQUE KEY ot_order_status_unique (order_id, status);` after the existing duplicates are cleared.';

        $blocked = $this->blockedBy($sample, [$ledger['settlements']], self::SETTLEMENT_SOURCE, $means, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $byOrder = [];
        foreach ($ledger['settlements']['rows'] as $settlement) {
            $byOrder[$settlement['order_id']][] = $settlement;
        }

        $rows = [];
        foreach ($byOrder as $orderId => $settlements) {
            if (count($settlements) < 2) {
                continue;
            }

            $sellerTotal = 0.0;
            $statuses = [];
            foreach ($settlements as $settlement) {
                $sellerTotal += $settlement['seller_amount'];
                $statuses[] = $settlement['status'] ?? 'null';
            }

            $rows[] = [
                'reference' => (string) $orderId,
                'kind' => 'settlement',
                'gateway' => null,
                'amount' => round($sellerTotal, 2),
                'currency' => null,
                'expected' => null,
                'occurred_at' => $settlements[0]['created_at'],
                'detail' => count($settlements) . ' settlement rows, statuses: ' . implode(', ', $statuses) . '.',
            ];
        }

        return $this->finding(
            state: 'ok',
            source: self::SETTLEMENT_SOURCE,
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$sample['scope']['truncated'] && !$ledger['settlements']['truncated'],
        );
    }

    /**
     * F6. A paid order with no settlement row at all.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function paidWithoutSettlement(array $sample, array $ledger): array
    {
        $means = 'The order is paid and non-offline, and no row exists in order_transactions for it. OrderManager writes one with status=hold for every such order at generation time, so its absence means the vendor will never be paid for this sale — nothing downstream will ever find it to disburse.';
        $action = 'Check whether the order was created outside OrderManager::generateOrder (an import, a manual insert, an add-on). Each of these needs a settlement row before the vendor payout runs.';

        $blocked = $this->blockedBy($sample, [$ledger['settlements']], self::SETTLEMENT_SOURCE, $means, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $settled = [];
        foreach ($ledger['settlements']['rows'] as $settlement) {
            $settled[$settlement['order_id']] = true;
        }

        $rows = [];
        foreach ($sample['rows'] as $order) {
            if ($order['payment_status'] !== 'paid' || $order['offline_method'] || isset($settled[$order['id']])) {
                continue;
            }

            $rows[] = [
                'reference' => (string) $order['id'],
                'kind' => 'order',
                'gateway' => $order['payment_method'],
                'amount' => $order['order_amount'],
                'currency' => null,
                'expected' => null,
                'occurred_at' => $order['created_at'],
                'detail' => 'Paid, and order_transactions holds no row for it.',
            ];
        }

        return $this->finding(
            state: 'ok',
            source: self::ORDERS_SOURCE . ' ↔ order_transactions',
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$sample['scope']['truncated'] && !$ledger['settlements']['truncated'],
        );
    }

    /**
     * F7. A delivered cash order whose settlement never moved to disburse.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function codNotDisbursed(array $sample, array $ledger): array
    {
        $means = 'The goods were delivered and paid for in cash, and the settlement row is still on hold. The delivery path flips it to disburse; a row still on hold means the money the courier collected has not been accounted to anybody.';
        $action = 'Reconcile the courier cash against these orders, then move the settlement. If many rows are here, the delivered-status path that writes disburse did not run for them.';

        $blocked = $this->blockedBy($sample, [$ledger['settlements']], self::SETTLEMENT_SOURCE, $means, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $byOrder = [];
        foreach ($ledger['settlements']['rows'] as $settlement) {
            $byOrder[$settlement['order_id']][] = $settlement;
        }

        $rows = [];
        foreach ($sample['rows'] as $order) {
            if (!in_array($order['order_status'], self::DELIVERED_STATUSES, true) || !$order['offline_method']) {
                continue;
            }

            $settlements = $byOrder[$order['id']] ?? [];
            if ($settlements === []) {
                continue;
            }

            $undisbursed = array_values(array_filter($settlements, static fn (array $row) => $row['status'] !== 'disburse'));
            if ($undisbursed === []) {
                continue;
            }

            $rows[] = [
                'reference' => (string) $order['id'],
                'kind' => 'settlement',
                'gateway' => $order['payment_method'],
                'amount' => $undisbursed[0]['seller_amount'],
                'currency' => null,
                'expected' => $order['order_amount'],
                'occurred_at' => $order['updated_at'],
                'detail' => 'Delivered, and the settlement is still ' . ($undisbursed[0]['status'] ?? 'unset') . '.',
            ];
        }

        return $this->finding(
            state: 'ok',
            source: self::ORDERS_SOURCE . ' ↔ order_transactions.status',
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$sample['scope']['truncated'] && !$ledger['settlements']['truncated'],
        );
    }

    /**
     * F9. The commission on the order does not equal the commission frozen on its lines.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function commissionMismatch(array $sample, array $ledger): array
    {
        $means = 'The commission stored on the order disagrees with the sum of the per-line commissions frozen at checkout. One of the two is what the vendor is paid from and the other is what the report shows, so a difference here is money that reconciles nowhere.';
        $action = 'Recompute the order commission from its lines. order_item_commissions is the frozen record — it carries the rule that applied at the time — so it is the side to trust when they disagree.';

        $blocked = $this->blockedBy($sample, [$ledger['commissions']], 'MySQL order_item_commissions', $means, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $commissions = $ledger['commissions']['rows'];

        // Not one order in the sample has a single commission line. That is the line-level ledger
        // being unused on this deployment, not every order disagreeing with it — and reporting the
        // whole sample as a finding would be the loudest possible way to be wrong.
        if ($commissions === []) {
            return $this->finding(
                state: 'not_configured',
                source: 'MySQL order_item_commissions',
                means: $means,
                action: $action,
                note: 'No order in this window carries a per-line commission row, so there is nothing to reconcile the order commission against. The line-level commission ledger is not in use here.',
                remedy: 'Per-line commissions are written by the commission-rules path at checkout. Configure a rule in Admin → Marketplace → Marketplace Finance → Commission Rules, or ignore this check if commission is not split per line on this shop.',
            );
        }

        $rows = [];
        foreach ($sample['rows'] as $order) {
            $lines = $commissions[$order['id']] ?? null;
            if ($lines === null) {
                continue;
            }

            $frozen = $lines['commission_total'] - $lines['reversed_total'];
            if (abs($order['admin_commission'] - $frozen) <= self::MONEY_TOLERANCE) {
                continue;
            }

            $rows[] = [
                'reference' => (string) $order['id'],
                'kind' => 'order',
                'gateway' => $order['payment_method'],
                'amount' => round($frozen, 4),
                'currency' => null,
                'expected' => $order['admin_commission'],
                'occurred_at' => $order['created_at'],
                'detail' => $lines['lines'] . ' commission line(s) sum to a different figure from orders.admin_commission'
                    . ($lines['reversed_total'] > 0 ? ', after ' . $lines['reversed_total'] . ' reversed' : '') . '.',
            ];
        }

        return $this->finding(
            state: 'ok',
            source: 'MySQL orders.admin_commission ↔ order_item_commissions',
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$sample['scope']['truncated'] && !$ledger['commissions']['truncated'],
            note: 'Only orders that carry at least one commission line are compared. An order with none is not counted as a mismatch, because the absence of the line ledger is a different fact from a disagreement with it.',
        );
    }

    /**
     * F10. A refund with no payment behind it.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function refundWithoutPayment(array $sample, array $ledger): array
    {
        $means = 'Money was refunded against an order that does not exist, or against one the shop never recorded as paid. Either way the shop has paid out against nothing it can show it took.';
        $action = 'Match each refund to a gateway capture before it is paid out. A refund whose order is missing entirely points at a deleted order; one whose order is unpaid points at a refund raised on the wrong id.';

        $refunds = $ledger['refunds'];
        if ($refunds['state'] !== 'ok') {
            return $this->findingFrom($refunds, 'MySQL refund_transactions', $means, $action);
        }

        $orderIds = array_values(array_unique(array_filter(
            array_column($refunds['rows'], 'order_id'),
            static fn ($id) => $id !== null,
        )));

        $paid = [];
        if ($orderIds !== []) {
            try {
                // Primary key lookup on orders.id.
                $rows = $this->shop()->table('orders')
                    ->whereIn('id', $orderIds)
                    ->limit(count($orderIds) + 1)
                    ->get(['id', 'payment_status']);
                foreach ($rows as $row) {
                    $paid[(int) $row->id] = (string) $row->payment_status;
                }
            } catch (\Throwable $exception) {
                return $this->finding('failed', 'MySQL refund_transactions', $means, $action, note: $this->failureNote($exception));
            }
        }

        $rows = [];
        foreach ($refunds['rows'] as $refund) {
            $status = $refund['order_id'] === null ? null : ($paid[$refund['order_id']] ?? null);

            if ($refund['order_id'] !== null && $status === 'paid') {
                continue;
            }

            $rows[] = [
                'reference' => $refund['order_id'] === null ? 'refund #' . $refund['id'] : (string) $refund['order_id'],
                'kind' => 'refund',
                'gateway' => $refund['gateway'],
                'amount' => $refund['amount'],
                'currency' => null,
                'expected' => null,
                'occurred_at' => $refund['created_at'],
                'detail' => match (true) {
                    $refund['order_id'] === null => 'The refund carries no order id at all.',
                    $status === null => 'No order exists with this id.',
                    default => 'The order exists and its payment status is ' . $status . ', not paid.',
                },
            ];
        }

        return $this->finding(
            state: 'ok',
            source: 'MySQL refund_transactions ↔ orders',
            means: $means,
            action: $action,
            rows: $rows,
            count: count($rows),
            exact: !$refunds['truncated'],
        );
    }

    // -------------------------------------------------------------------------------------------
    // F8 — the one gateway that keeps a per-attempt record

    /**
     * PayTabs approvals and declines.
     *
     * The only gateway in this build that writes a row whichever way the attempt went, so it is the
     * only place a decline rate exists independently of the analytics events above. It says nothing
     * about the other thirteen gateways, which is stated rather than left to be assumed.
     *
     * @return array<string, mixed>
     */
    private function declines(string $range): array
    {
        $source = 'MySQL paytabs_invoices';
        $guard = $this->scannable('paytabs_invoices');

        if ($guard['state'] !== 'ok') {
            return array_merge($this->blockedRead($guard), [
                'source' => $source,
                'approved' => null,
                'declined' => null,
                'attempts' => null,
                'decline_rate' => null,
                'codes' => [],
            ]);
        }

        try {
            $rows = $this->shop()->table('paytabs_invoices')
                ->where('created_at', '>=', $this->shopSince($range))
                ->limit(self::MAX_DECLINE_ROWS + 1)
                ->get(['response_code', 'amount', 'currency', 'created_at']);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'source' => $source,
                'approved' => null,
                'declined' => null,
                'attempts' => null,
                'decline_rate' => null,
                'codes' => [],
                'truncated' => false,
            ];
        }

        $approved = 0;
        $declined = 0;
        $codes = [];
        foreach ($rows->take(self::MAX_DECLINE_ROWS) as $row) {
            $code = (int) $row->response_code;
            if ($code === self::PAYTABS_APPROVED) {
                $approved++;

                continue;
            }

            $declined++;
            $codes[$code] = ($codes[$code] ?? 0) + 1;
        }

        arsort($codes);
        $attempts = $approved + $declined;

        $listed = [];
        foreach (array_slice($codes, 0, self::MAX_SAMPLE_ROWS, true) as $code => $count) {
            $listed[] = ['code' => (int) $code, 'attempts' => $count];
        }

        return [
            'state' => $attempts === 0 ? 'no_data' : 'ok',
            'note' => $attempts === 0
                ? 'PayTabs recorded no attempt inside this window. It is the only gateway in this build that writes a row for a failed attempt, so this figure covers PayTabs and nothing else.'
                : null,
            'remedy' => null,
            'source' => $source,
            'approved' => $approved,
            'declined' => $declined,
            'attempts' => $attempts,
            // No attempts is not a zero decline rate.
            'decline_rate' => $attempts > 0 ? round(100 * $declined / $attempts, 1) : null,
            'codes' => $listed,
            'truncated' => $rows->count() > self::MAX_DECLINE_ROWS,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The numbers above everything

    /**
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $recording
     * @param  array<string, mixed>  $rate
     * @param  array<string, array<string, mixed>>  $findings
     * @return array<string, Metric>
     */
    private function headline(array $sample, array $volume, array $recording, array $rate, array $findings): array
    {
        $headline = [];

        if ($sample['scope']['state'] !== 'failed') {
            $headline['orders_reconciled'] = Metric::of(
                value: $sample['scope']['orders_examined'],
                source: self::ORDERS_SOURCE,
                unit: null,
                note: $sample['scope']['truncated']
                    ? 'This window holds more orders than this page reconciles; the newest ' . self::MAX_ORDERS . ' are covered.'
                    : null,
            );
        }

        $checked = 0;
        $unavailable = 0;
        $total = 0;
        foreach ($findings as $finding) {
            if ($finding['state'] === 'ok') {
                $checked++;
                $total += (int) $finding['count'];

                continue;
            }
            $unavailable++;
        }

        $headline['integrity_findings'] = $checked === 0
            ? Metric::noData(
                source: self::ORDERS_SOURCE,
                note: 'Not one of the ' . count(self::FINDINGS) . ' reconciliations could be run in this window, so this is the absence of a count rather than a count of zero.',
            )
            : Metric::of(
                value: $total,
                source: self::ORDERS_SOURCE,
                unit: null,
                note: $unavailable > 0
                    ? 'Counted over ' . $checked . ' of ' . count(self::FINDINGS) . ' reconciliations; '
                        . ($unavailable === 1 ? 'one could not be run and says so below.' : $unavailable . ' could not be run and say so below.')
                    : 'Counted over all ' . count(self::FINDINGS) . ' reconciliations, within the orders this page examined.',
            );

        // These three are published whatever the volume read did. They used to appear only when it
        // produced counts, so switching analytics off deleted them from the page — and a card that
        // is not there says nothing at all, where one that names the switch says everything.
        $counted = in_array($volume['state'], ['ok', 'no_data'], true);

        $headline['payments_recorded'] = $counted
            ? Metric::of(
                value: $volume['totals']['succeeded'],
                source: self::EVENTS_SOURCE,
                unit: null,
                note: $volume['totals']['offline_succeeded'] > 0
                    ? $volume['totals']['offline_succeeded'] . ' of them are cash, wallet or offline payments, which never travel through a gateway.'
                    : null,
            )
            : $this->volumeUnavailable($volume);

        $headline['payment_failures_recorded'] = match (true) {
            !$counted => $this->volumeUnavailable($volume),
            $recording['first_failure_at'] === null => Metric::noData(
                source: self::EVENTS_SOURCE,
                note: 'No payment failure has ever been recorded on this deployment. Before the failure hook was implemented nothing was written at all, so this is not a reading of zero declines.',
            ),
            default => Metric::of(
                value: $volume['totals']['failed'],
                source: self::EVENTS_SOURCE,
                unit: null,
                note: 'Failures have been recorded since ' . $recording['first_failure_at'] . ' (' . Clock::displayTimezone() . ').',
            ),
        };

        $headline['gateways_with_activity'] = $counted
            ? Metric::of(
                value: count($volume['rows']),
                source: self::EVENTS_SOURCE,
            )
            : $this->volumeUnavailable($volume);

        $headline['settled_success_rate'] = $rate['rate'] === null
            ? Metric::noData(source: self::EVENTS_SOURCE, note: $rate['note'] ?? $rate['basis'])
            : Metric::of(
                value: $rate['rate'],
                source: self::EVENTS_SOURCE,
                unit: '%',
                note: 'Over ' . $rate['settled'] . ' settled gateway attempts. Abandonment is not in it: nothing emits payment_started.',
            );

        return $headline;
    }

    /**
     * The volume read's unavailability, lifted into a Metric so a card carries the table's reason.
     *
     * Each state is a different sentence to the operator: analytics switched off is something they
     * can turn on and the card says where, whereas a read that threw is nobody's setting. Both are
     * kept out of NO_DATA, which on this page means "the probe worked and found nothing".
     *
     * @param  array<string, mixed>  $volume
     */
    private function volumeUnavailable(array $volume): Metric
    {
        return match ($volume['state']) {
            'not_configured' => Metric::notConfigured(
                source: self::EVENTS_SOURCE,
                remedy: (string) $volume['remedy'],
                note: $volume['note'],
            ),
            'failed' => Metric::failed(
                source: self::EVENTS_SOURCE,
                exception: $this->volumeFailure ?? new \RuntimeException((string) $volume['note']),
            ),
            default => Metric::noData(source: self::EVENTS_SOURCE, note: $volume['note']),
        };
    }

    // -------------------------------------------------------------------------------------------
    // What this build does not record about a payment

    /**
     * Readings this section is expected to carry that nothing on this deployment produces.
     *
     * Published as unconfigured metrics rather than left off the page. The navigation promises
     * "attempts, success rate, gateway latency and webhooks"; two of those four have no producer at
     * all, and a page that simply omitted them would read as a shop where they are fine.
     *
     * @return array<string, mixed>
     */
    private function unrecorded(): array
    {
        return [
            'state' => 'not_configured',
            'source' => 'code, not data',
            'note' => 'Each of these is a measurement nothing on this deployment takes. They are drawn as unconfigured readings rather than as empty cells, because a blank latency column reads as a gateway that answered instantly.',
            'fields' => [
                'payment_started' => Metric::notConfigured(
                    source: 'analytics_events.payment_started',
                    remedy: 'Emit it from App\\Traits\\Payment::generate_link() (app/Traits/Payment.php), immediately after $payment->save() — the single choke point every one of the fourteen gateways passes through.',
                    note: 'Analytics::paymentAttempted() already maps an outcome of "started" onto this event, and no code path calls it that way. Without it, a shopper who left the gateway before it answered is invisible and no abandonment rate exists.',
                ),
                'gateway_latency' => Metric::notConfigured(
                    source: 'monitoring_dependency_buckets',
                    remedy: 'Register one Http::globalMiddleware in MonitoringServiceProvider::boot() and increment MetricSink under BucketWriter::DEPENDENCY_PREFIX. The table, its writer, its rollup and the Overview cards that read it are already built.',
                    note: 'Nothing times an outbound call to a gateway. Thirteen of the fourteen payment controllers are invisible to an Http:: middleware, but not for one reason: nine use raw curl_exec, three drive a vendor SDK (Stripe, Razorpay, MercadoPago) and SenangPay makes no outbound call at all. Only Paymera goes out through Http::, so the other twelve need instrumenting one at a time even after the middleware exists — and wrapping an SDK is not the same job as wrapping curl.',
                ),
                'webhook_receipts' => Metric::notConfigured(
                    source: 'no table',
                    remedy: 'Record each gateway callback — its gateway, its reference, its outcome and when it landed — at the top of the callback controllers in app/Http/Controllers/Payment_Methods/.',
                    note: 'No gateway callback leaves a receipt anywhere. A callback that never arrived and one that arrived and failed are the same absence of a row, which is why "money captured with no order" below can name the symptom and never the cause.',
                ),
                'payment_request_outcome' => Metric::notConfigured(
                    source: 'MySQL payment_requests',
                    remedy: 'Add status, failure_code, failure_message, finalized_at and attempts to payment_requests, and write them from digital_payment_fail(). Index (status, created_at) and (payment_method, created_at) at the same time.',
                    note: 'The gateway ledger records only is_paid. A declined card, a gateway timeout and a shopper who closed the tab are byte-identical rows, so no failure reason can be shown for any of them.',
                ),
                'payment_request_order_link' => Metric::notConfigured(
                    source: 'MySQL payment_requests.attribute_id',
                    remedy: 'Add order_id to payment_requests and set it in the success hook, then index it. The reconciliations on this page become exact rather than best-effort.',
                    note: 'attribute_id holds a unix timestamp, not an order id (app/Http/Controllers/Customer/PaymentController.php). The only join available is orders.transaction_ref = payment_requests.transaction_id, varchar(30) against varchar(100), nullable on both sides.',
                ),
            ],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The cost guard

    /**
     * Whether a table with no usable index is small enough to read at all.
     *
     * payment_requests has no index and no primary key; order_transactions, paytabs_invoices,
     * refund_transactions and offline_payments are indexed on their own id and nothing else. Every
     * read of them is a full scan, so its cost is the table's size — and an admin page that can
     * table-scan a shop's payment history is an admin page that becomes the incident. The size is
     * taken from information_schema, which costs nothing and is approximate for InnoDB; the ceiling
     * is set well below anything that would hurt so the approximation cannot matter.
     *
     * @return array<string, mixed>
     */
    private function scannable(string $table): array
    {
        if (array_key_exists($table, $this->scans)) {
            return $this->scans[$table];
        }

        $ceiling = self::MAX_UNINDEXED_ROWS;

        try {
            $row = $this->shop()->selectOne(
                'SELECT TABLE_ROWS AS estimated_rows FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                [$table],
            );
        } catch (\Throwable $exception) {
            return $this->scans[$table] = [
                'table' => $table,
                'state' => 'failed',
                'estimated_rows' => null,
                'ceiling' => $ceiling,
                'note' => 'The size of this table could not be established (' . $this->failureNote($exception) . '), and it has no index a read could be bounded on, so it was not read.',
                'remedy' => 'Grant the database user access to information_schema, or index the table so its size stops mattering.',
            ];
        }

        if ($row === null) {
            return $this->scans[$table] = [
                'table' => $table,
                'state' => 'not_supported',
                'estimated_rows' => null,
                'ceiling' => $ceiling,
                'note' => 'This table does not exist on this deployment, so the checks that read it cannot be run here.',
                'remedy' => null,
            ];
        }

        $estimated = $row->estimated_rows === null ? null : (int) $row->estimated_rows;

        if ($estimated === null) {
            return $this->scans[$table] = [
                'table' => $table,
                'state' => 'no_data',
                'estimated_rows' => null,
                'ceiling' => $ceiling,
                'note' => 'The database reported no row estimate for this table, and it has no index a read could be bounded on, so it was not read rather than scanned blind.',
                'remedy' => 'Run `ANALYZE TABLE ' . $table . ';` so the optimiser has a row estimate, or index the table.',
            ];
        }

        if ($estimated > $ceiling) {
            return $this->scans[$table] = [
                'table' => $table,
                'state' => 'not_supported',
                'estimated_rows' => $estimated,
                'ceiling' => $ceiling,
                'note' => 'This table holds roughly ' . number_format($estimated) . ' rows and has no index a read could be bounded on, so reading it would mean a full scan on every refresh of this page. It was not read.',
                'remedy' => $this->indexRemedy($table),
            ];
        }

        return $this->scans[$table] = [
            'table' => $table,
            'state' => 'ok',
            'estimated_rows' => $estimated,
            'ceiling' => $ceiling,
            'note' => null,
            'remedy' => $this->indexRemedy($table),
        ];
    }

    /** The index that would make this table cheap to read, as a runnable statement. */
    private function indexRemedy(string $table): ?string
    {
        return match ($table) {
            'payment_requests' => 'ALTER TABLE payment_requests ADD PRIMARY KEY (id), ADD KEY pr_created_idx (created_at), ADD KEY pr_transaction_idx (transaction_id), ADD KEY pr_paid_created_idx (is_paid, created_at);',
            'order_transactions' => 'ALTER TABLE order_transactions ADD KEY ot_order_idx (order_id), ADD KEY ot_created_idx (created_at);',
            'paytabs_invoices' => 'ALTER TABLE paytabs_invoices ADD KEY pti_created_idx (created_at), ADD KEY pti_order_idx (order_id);',
            'refund_transactions' => 'ALTER TABLE refund_transactions ADD KEY rt_created_idx (created_at), ADD KEY rt_order_idx (order_id);',
            'offline_payments' => 'ALTER TABLE offline_payments ADD KEY op_order_idx (order_id);',
            default => null,
        };
    }

    /**
     * The guard's verdict on every table it was asked about, so a missing check has a reason.
     *
     * @return array<string, mixed>
     */
    private function scanReport(): array
    {
        $rows = array_values($this->scans);
        usort($rows, static fn (array $a, array $b) => [$a['state'] === 'ok' ? 1 : 0, $a['table']] <=> [$b['state'] === 'ok' ? 1 : 0, $b['table']]);

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === [] ? 'No unindexed table needed to be read for this window.' : null,
            'source' => 'information_schema.TABLES',
            'ceiling' => self::MAX_UNINDEXED_ROWS,
            'rows' => $rows,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Block shapes

    /**
     * The uniform shape every reconciliation returns, whichever way it went.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function finding(
        string $state,
        string $source,
        string $means,
        string $action,
        array $rows = [],
        ?int $count = null,
        bool $exact = true,
        ?string $note = null,
        ?string $remedy = null,
    ): array {
        return [
            'state' => $state,
            'note' => $note,
            'remedy' => $remedy,
            'source' => $source,
            'means' => $means,
            'action' => $action,
            // Null when nothing was counted, never zero: a check that could not run has not found
            // nothing, it has not looked.
            'count' => $state === 'ok' ? (int) ($count ?? count($rows)) : null,
            'count_exact' => $state === 'ok' ? $exact : false,
            'rows' => array_slice($rows, 0, self::MAX_SAMPLE_ROWS),
            'listed' => min(count($rows), self::MAX_SAMPLE_ROWS),
            'limit' => self::MAX_SAMPLE_ROWS,
            'truncated' => count($rows) > self::MAX_SAMPLE_ROWS,
        ];
    }

    /**
     * A reconciliation that could not run because the read it needs did not.
     *
     * @param  array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function findingFrom(array $read, string $source, string $means, string $action): array
    {
        return $this->finding(
            state: $read['state'] === 'ok' ? 'no_data' : $read['state'],
            source: $source,
            means: $means,
            action: $action,
            note: $read['note'] ?? null,
            remedy: $read['remedy'] ?? null,
        );
    }

    /**
     * The first reason a reconciliation cannot run, or null when it can.
     *
     * @param  array{scope: array<string, mixed>, rows: array<int, array<string, mixed>>}  $sample
     * @param  array<int, array<string, mixed>>  $reads
     * @return array<string, mixed>|null
     */
    private function blockedBy(array $sample, array $reads, string $source, string $means, string $action): ?array
    {
        if ($sample['scope']['state'] !== 'ok') {
            return $this->findingFrom($sample['scope'], $source, $means, $action);
        }

        foreach ($reads as $read) {
            if (($read['state'] ?? 'ok') !== 'ok') {
                return $this->findingFrom($read, $source, $means, $action);
            }
        }

        return null;
    }

    /**
     * The shape a read takes when the cost guard refused it.
     *
     * @param  array<string, mixed>  $guard
     * @return array<string, mixed>
     */
    private function blockedRead(array $guard): array
    {
        return [
            'state' => $guard['state'],
            'note' => $guard['note'],
            'remedy' => $guard['remedy'],
            'rows' => [],
            'truncated' => false,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Connections and clocks

    /**
     * The shop's own database.
     *
     * Not $reader->connection(): that one resolves config('monitoring.connection'), which may point
     * at a separate database or a separate host. Orders, payments and settlements live in the
     * application's own database and nowhere else, so reading them through the monitoring
     * connection would silently return nothing on any deployment that moved monitoring off-box.
     */
    private function shop(): Connection
    {
        return DB::connection();
    }

    /** Analytics has its own connection setting for the same reason, defaulting to the shop's. */
    private function analytics(): Connection
    {
        return DB::connection(config('analytics.connection'));
    }

    /**
     * A monitoring (UTC) instant, expressed in the clock the shop writes its timestamps in.
     *
     * orders.created_at, payment_requests.created_at and analytics_events.occurred_at are all
     * written with Carbon::now(), which runs on app.timezone. Binding a UTC bound against those
     * columns would shift every window by the offset — six hours on this deployment — and the page
     * would quietly reconcile the wrong orders while looking perfectly healthy.
     */
    private function shopStamp(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(date_default_timezone_get())->toDateTimeString();
    }

    private function shopSince(string $range): string
    {
        return $this->shopStamp($this->reader->since($range));
    }

    /** A shop-written timestamp, read in the shop's clock and rendered in the dashboard's. */
    private function shopDisplay(mixed $stored): ?string
    {
        $moment = $this->shopMoment($stored);

        if ($moment === null) {
            return is_scalar($stored) && trim((string) $stored) !== '' ? (string) $stored : null;
        }

        return $moment->copy()->setTimezone(Clock::displayTimezone())->toDateTimeString();
    }

    private function shopMoment(mixed $stored): ?Carbon
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Carbon::parse($stored, date_default_timezone_get());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A failed read, said in one line that is safe to print.
     *
     * A QueryException carries the statement and its bindings, and a payment query's bindings are
     * transaction references and order ids — so it goes through the redactor and is bounded before
     * it reaches a page somebody will screenshot into a support thread.
     */
    private function failureNote(\Throwable $exception): string
    {
        return class_basename($exception) . ': '
            . $this->redactor->text(mb_substr($exception->getMessage(), 0, 400));
    }

    private function shortText(mixed $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $this->redactor->text(mb_substr($value, 0, $length));
    }
}
