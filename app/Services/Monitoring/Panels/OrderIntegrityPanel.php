<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Order integrity: orders that contradict themselves, read from the shop's own tables.
 *
 * Every other business page on this dashboard counts what happened. This one counts what cannot
 * have happened: an order that was paid for and holds no items, a total that does not equal the sum
 * of its own lines, the same customer charged the same amount twice inside two minutes, an order
 * that has sat in one status since last week. None of these are measured by a collector — nothing
 * instruments them — so the panel derives them from `orders` and `order_details` directly, which
 * makes three things load-bearing.
 *
 * First, COST. This is an admin page on a live shop, and a monitoring page that table-scans the
 * orders table is the outage. Every read here is bounded by a time window AND a LIMIT, and rides an
 * index that is named in the comment above it. The one check whose join column has no index at all
 * — `order_status_histories.order_id` — is not run: it reports itself as unconfigured with the
 * exact migration that would make it affordable, because a check that takes the shop down to find a
 * missing audit row is worse than the missing audit row.
 *
 * Second, HONESTY ABOUT THE SAMPLE. The checks that need line data read the most recent N orders in
 * the period and say so. A finding count is therefore "how many of the orders this page examined",
 * never "how many exist" — those are different claims and only one of them was measured.
 *
 * Third, THE RANGE SELECTOR MUST NOT BE ABLE TO HIDE A BROKEN ORDER. At `live` the window is five
 * minutes; a page that reported "no contradictions" over five minutes while last night's orders
 * were still broken would be the most reassuring lie here. So every check reads at least the last
 * seven days however short the selected range is, and orders stuck in a status are read over a
 * fixed thirty days regardless of the range, because being stuck is a standing condition rather
 * than an event inside a window.
 *
 * What this page cannot know is published as readings too, at the foot: there is no idempotency key
 * on checkout, so "duplicate" is a heuristic over customer, amount and seconds; and the status
 * ledger is written by hand at three call sites, so a status that moved without one leaves no trail
 * to check against.
 */
class OrderIntegrityPanel implements Panel
{
    /** The order status vocabulary the shop writes — the allowlist that makes translate() safe. */
    private const ORDER_STATUSES = [
        'pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'canceled', 'returned', 'failed',
    ];

    /** The statuses an order can sit in while it is still somebody's work. */
    private const OPEN_STATUSES = ['pending', 'confirmed', 'processing', 'out_for_delivery'];

    /** The payment status vocabulary the shop writes. */
    private const PAYMENT_STATUSES = ['paid', 'unpaid'];

    /** How bad a finding is. A fixed vocabulary, so the view may translate it. */
    private const SEVERITIES = ['critical', 'major', 'minor'];

    /**
     * The checks, in the order they are declared.
     *
     * These keys are the section's own vocabulary and reach translate() as compile-time literals.
     * Nothing read out of a column is ever used as a translation key.
     */
    private const CHECKS = [
        'paid_orders_with_no_items',
        'order_totals_that_do_not_match_their_lines',
        'duplicate_orders_seconds_apart',
        'orders_stuck_in_one_status',
        'delivered_orders_with_no_status_record',
        'orders_with_no_customer',
    ];

    /** How the findings may be ranked. Money first, because that is what an operator triages by. */
    private const SORTS = ['amount', 'count'];

    /**
     * Orders read once for every check that needs their lines.
     *
     * The ceiling on the single heaviest read on this page. Four hundred rows off the head of an
     * index range costs four hundred row lookups whatever the shop's size.
     */
    private const MAX_SAMPLE_ORDERS = 400;

    /** Rows listed under one finding. The count above the table is over the sample, not this. */
    private const MAX_FINDING_ROWS = 20;

    /** Stuck orders listed per status. Four statuses, so at most a hundred rows for that check. */
    private const MAX_STUCK_PER_STATUS = 25;

    /** Order status groups counted for the denominator. Fixed, so the count query cannot fan out. */
    private const MAX_STATUS_GROUPS = 8;

    /**
     * The shortest period any check will look at.
     *
     * A five-minute window on a shop that takes ten orders a day reports a clean bill of health for
     * a reason that has nothing to do with the shop.
     */
    private const MINIMUM_LOOKBACK_DAYS = 7;

    /**
     * How far back a standing condition is read, whatever the range says.
     *
     * Fixed rather than widened by the selector: an order that has been stuck for longer than a
     * month needs a report run against the database, not a dashboard page holding a scan of it
     * open while an operator reads.
     */
    private const STANDING_LOOKBACK_DAYS = 30;

    /** Two orders for the same customer and the same amount inside this gap look like a double submit. */
    private const DUPLICATE_GAP_SECONDS = 120;

    /** Money is compared to the cent. Below this the two numbers are the same number. */
    private const AMOUNT_TOLERANCE = 0.01;

    private const ORDERS_SOURCE = 'MySQL orders';

    private const LINES_SOURCE = 'MySQL order_details';

    private const LEDGER_SOURCE = 'MySQL order_status_histories';

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly MonitoringSettings $settings,
        private readonly Redactor $redactor,
        private readonly DatabaseManager $database,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $eventSince = $this->eventSince($range);
        $standingSince = Clock::daysAgo(self::STANDING_LOOKBACK_DAYS);

        $sort = $this->sort($request);
        $shop = $this->shop();
        $stuckHours = $this->stuckHours();

        $volume = $this->volume($shop, $eventSince);
        $sample = $this->sample($shop, $eventSince);
        $lines = $this->lineTotals($shop, $sample);
        $ledger = $this->ledgerIndex($shop);

        $findings = $this->rank($this->findings($shop, $sample, $lines, $ledger, $standingSince, $stuckHours), $sort);
        $summary = $this->summary($findings);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'scope' => $this->scope($range, $eventSince, $standingSince),
            'shop' => $shop,
            'thresholds' => $stuckHours,
            'headline' => $this->headline($volume, $sample, $summary),
            'volume' => $volume,
            'sort' => $sort,
            'findings' => $findings,
            'summary' => $summary,
            'gaps' => $this->gaps(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // The period every check reads

    /**
     * The left edge of the event checks: the selected window, or seven days, whichever is earlier.
     */
    private function eventSince(string $range): Carbon
    {
        $selected = $this->reader->since($range);
        $floor = Clock::daysAgo(self::MINIMUM_LOOKBACK_DAYS);

        return $selected->lessThan($floor) ? $selected : $floor;
    }

    /**
     * The periods, published rather than implied.
     *
     * @return array<string, mixed>
     */
    private function scope(string $range, Carbon $eventSince, Carbon $standingSince): array
    {
        $selected = $this->reader->since($range);

        return [
            'event_since' => Clock::display($eventSince)->toDateTimeString(),
            'event_until' => Clock::display(Clock::now())->toDateTimeString(),
            'floor_days' => self::MINIMUM_LOOKBACK_DAYS,
            'floor_applied' => $selected->greaterThan($eventSince),
            'standing_since' => Clock::display($standingSince)->toDateTimeString(),
            'standing_days' => self::STANDING_LOOKBACK_DAYS,
            'timezone' => Clock::displayTimezone(),
            'sample_limit' => self::MAX_SAMPLE_ORDERS,
            'note' => 'Integrity checks never read less than the last ' . self::MINIMUM_LOOKBACK_DAYS
                . ' days however short the selected range is, and orders stuck in a status are read over a fixed '
                . self::STANDING_LOOKBACK_DAYS . ' days. A five-minute window that found nothing would be a statement about the window.',
        ];
    }

    /**
     * How long an order may sit in one status before it counts as stuck.
     *
     * @return array<string, mixed>
     */
    private function stuckHours(): array
    {
        $shipped = (float) config('monitoring.thresholds.stuck_order_hours', 6);
        $configured = $this->settings->threshold('stuck_order_hours', $shipped);
        $hours = $configured !== null && $configured > 0 ? $configured : $shipped;

        return [
            'stuck_order_hours' => $hours,
            'source' => 'monitoring_settings (thresholds.stuck_order_hours), falling back to config/monitoring.php',
            'note' => 'One threshold covers every status. The shop stores no per-status target to compare against, so a pending order and an out-for-delivery order are held to the same clock.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // The connection these tables live on

    /**
     * The shop's own database, stated once at the top.
     *
     * Deliberately NOT the monitoring connection: `config('monitoring.connection')` may point at a
     * separate database that holds no orders at all. When this cannot be reached every check below
     * is blank for the same single reason, and that reason is said here once instead of six times.
     *
     * @return array<string, mixed>
     */
    private function shop(): array
    {
        try {
            $connection = $this->database->connection();
            $connection->getPdo();
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'connection' => (string) config('database.default', ''),
                'driver' => null,
                'note' => $this->failureNote($exception),
                'remedy' => 'Check DB_* in .env and that the database is reachable: `php artisan db:show`.',
            ];
        }

        return [
            'state' => 'ok',
            'connection' => $connection->getName(),
            'driver' => $connection->getDriverName(),
            'note' => null,
            'remedy' => null,
        ];
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    // ---------------------------------------------------------------------------------------------
    // The denominator

    /**
     * How many orders the period holds, per status.
     *
     * Counted one status at a time on purpose. Each count is `order_status = ? AND created_at >= ?`,
     * which is exactly the two columns of idx_orders_status_created_at — the index answers it
     * without touching a single row. A GROUP BY over the same range would have to read every order
     * row in the period to find its status, which is the read this page is not allowed to make.
     *
     * The money in those orders is NOT summed here for the same reason: order_amount is not in any
     * index, so totalling it means reading every row. It is published as a null with that stated
     * rather than as a zero.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function volume(array $shop, Carbon $since): array
    {
        $base = [
            'source' => self::ORDERS_SOURCE,
            'index' => 'idx_orders_status_created_at (order_status, created_at), idx_orders_created_at (created_at)',
            'rows' => [],
            'total' => null,
            'other' => null,
            'amount' => null,
            'amount_note' => 'Revenue in the period is not totalled here: order_amount is in no index, so summing it would mean reading every order row in the window. It is left unread rather than guessed at.',
        ];

        if ($shop['state'] !== 'ok') {
            return array_merge($base, [
                'state' => $shop['state'],
                'note' => null,
                'remedy' => null,
                'blocked_by_connection' => true,
            ]);
        }

        try {
            $connection = $this->connection();
            $bound = $this->shopBound($since);

            $counts = [];
            foreach (array_slice(self::ORDER_STATUSES, 0, self::MAX_STATUS_GROUPS) as $status) {
                $counts[$status] = (int) $connection->table('orders')
                    ->where('order_status', $status)
                    ->where('created_at', '>=', $bound)
                    ->count();
            }

            $total = (int) $connection->table('orders')->where('created_at', '>=', $bound)->count();
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing the denominator costs one card,
            // while letting it escape would blank six findings that read perfectly well.
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The orders table is part of the shop schema. Import installation/backup/database.sql, then run `php artisan migrate`.',
                'blocked_by_connection' => false,
            ]);
        }

        $rows = [];
        foreach ($counts as $status => $count) {
            $rows[] = ['status' => $status, 'status_known' => true, 'orders' => $count];
        }

        return array_merge($base, [
            'state' => $total === 0 ? 'no_data' : 'ok',
            'note' => $total === 0 ? 'No order was created inside this period.' : null,
            'remedy' => null,
            'blocked_by_connection' => false,
            'rows' => $rows,
            'total' => $total,
            // Counted, not assumed: a status outside the list this build writes is a finding of its own.
            'other' => max(0, $total - array_sum($counts)),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // The orders every line-level check is run against

    /**
     * The most recent orders in the period, read once for four of the six checks.
     *
     * Rides idx_orders_created_at (created_at): a descending range scan off the newest end that
     * stops after the limit, so the cost is the limit rather than the size of the window.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function sample(array $shop, Carbon $since): array
    {
        $base = [
            'source' => self::ORDERS_SOURCE,
            'index' => 'idx_orders_created_at (created_at)',
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_SAMPLE_ORDERS,
            'examined' => null,
        ];

        if ($shop['state'] !== 'ok') {
            return array_merge($base, ['state' => $shop['state'], 'note' => null, 'remedy' => null]);
        }

        try {
            $rows = $this->connection()->table('orders')
                ->where('created_at', '>=', $this->shopBound($since))
                ->orderByDesc('created_at')
                ->limit(self::MAX_SAMPLE_ORDERS + 1)
                ->get([
                    'id', 'customer_id', 'is_guest', 'order_status', 'payment_status', 'order_amount',
                    'shipping_cost', 'discount_amount', 'extra_discount', 'refer_and_earn_discount',
                    'total_tax_amount', 'created_at', 'updated_at',
                ]);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The orders table is part of the shop schema. Import installation/backup/database.sql, then run `php artisan migrate`.',
            ]);
        }

        $orders = [];
        foreach ($rows->take(self::MAX_SAMPLE_ORDERS) as $row) {
            $orders[] = [
                'id' => (int) $row->id,
                // Never rendered. Kept only long enough to group a double submit, then dropped.
                'customer_key' => $this->customerKey($row->customer_id),
                'is_guest' => (int) $row->is_guest === 1,
                'order_status' => $this->vocabulary($row->order_status),
                'payment_status' => $this->vocabulary($row->payment_status),
                'amount' => $this->floatOrNull($row->order_amount),
                'shipping_cost' => $this->floatOrNull($row->shipping_cost),
                'discount_amount' => $this->floatOrNull($row->discount_amount),
                'extra_discount' => $this->floatOrNull($row->extra_discount),
                'refer_and_earn_discount' => $this->floatOrNull($row->refer_and_earn_discount),
                'total_tax_amount' => $this->floatOrNull($row->total_tax_amount),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'created_seconds' => $this->shopSeconds($row->created_at),
            ];
        }

        return array_merge($base, [
            'state' => $orders === [] ? 'no_data' : 'ok',
            'note' => $orders === [] ? 'No order was created inside this period, so there was nothing to check.' : null,
            'remedy' => null,
            'rows' => $orders,
            'truncated' => $rows->count() > self::MAX_SAMPLE_ORDERS,
            'examined' => count($orders),
        ]);
    }

    /**
     * One aggregate row per order in the sample, from its lines.
     *
     * Rides idx_order_details_order_id_delivery_status, whose leading column is order_id, so the
     * IN list is a bounded set of index lookups rather than a scan of the line table.
     *
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function lineTotals(array $shop, array $sample): array
    {
        $base = [
            'source' => self::LINES_SOURCE,
            'index' => 'idx_order_details_order_id_delivery_status (order_id, delivery_status)',
            'by_order' => [],
            'truncated' => false,
        ];

        if ($shop['state'] !== 'ok' || $sample['state'] !== 'ok') {
            return array_merge($base, [
                'state' => $sample['state'] === 'ok' ? $shop['state'] : $sample['state'],
                'note' => null,
            ]);
        }

        $ids = array_column($sample['rows'], 'id');
        if ($ids === []) {
            return array_merge($base, ['state' => 'no_data', 'note' => 'No order in the sample to read lines for.']);
        }

        try {
            $connection = $this->connection();
            $rows = $connection->table('order_details')
                ->whereIn('order_id', $ids)
                ->groupBy('order_id')
                ->limit(count($ids) + 1)
                ->get([
                    'order_id',
                    $connection->raw('COUNT(*) AS lines_count'),
                    $connection->raw('SUM(qty) AS units'),
                    $connection->raw('SUM(price * qty - discount) AS lines_total'),
                    $connection->raw('SUM(tax) AS lines_tax'),
                ]);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
            ]);
        }

        $byOrder = [];
        foreach ($rows as $row) {
            $byOrder[(int) $row->order_id] = [
                'lines' => (int) $row->lines_count,
                'units' => $this->integerOrNull($row->units),
                'lines_total' => $this->floatOrNull($row->lines_total),
                'lines_tax' => $this->floatOrNull($row->lines_tax),
            ];
        }

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'by_order' => $byOrder,
        ]);
    }

    /**
     * Whether the status ledger can be joined on at all.
     *
     * `order_status_histories` carries a primary key on `id` and nothing else, so a lookup by
     * order_id reads the whole audit table — the largest table in the schema on a busy shop. The
     * check that needs it is therefore not run unless an index leads with order_id, and this is the
     * metadata read that decides. information_schema is a catalogue lookup, not a table scan.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function ledgerIndex(array $shop): array
    {
        $base = ['source' => self::LEDGER_SOURCE, 'index' => null, 'usable' => null];

        if ($shop['state'] !== 'ok') {
            return array_merge($base, ['state' => $shop['state'], 'note' => null, 'remedy' => null]);
        }

        $driver = (string) ($shop['driver'] ?? '');
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return array_merge($base, [
                'state' => 'not_supported',
                'usable' => null,
                'note' => 'Whether order_status_histories carries an index on order_id can only be read from information_schema, which this driver does not offer here.',
                'remedy' => null,
            ]);
        }

        try {
            $rows = $this->connection()->select(
                'SELECT INDEX_NAME AS index_name, COLUMN_NAME AS column_name FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND SEQ_IN_INDEX = 1 LIMIT 20',
                ['order_status_histories'],
            );
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
            ]);
        }

        // No index at all, not even a primary key: on this schema that means the table is not here,
        // which is a different problem from a table that is here and cannot be searched.
        if ($rows === []) {
            return array_merge($base, [
                'state' => 'failed',
                'usable' => false,
                'note' => 'order_status_histories carries no index at all on this connection, which on this schema means the table is missing rather than unindexed.',
                'remedy' => 'The status ledger is part of the shop schema. Import installation/backup/database.sql, then run `php artisan migrate`.',
            ]);
        }

        foreach ($rows as $row) {
            if (($row->column_name ?? null) === 'order_id') {
                return array_merge($base, [
                    'state' => 'ok',
                    'usable' => true,
                    'index' => $this->shortText($row->index_name ?? null, 64),
                    'note' => null,
                    'remedy' => null,
                ]);
            }
        }

        return array_merge($base, [
            'state' => 'not_configured',
            'usable' => false,
            'note' => 'order_status_histories has a primary key on id and no index leading with order_id, so looking one order up in it reads the whole audit table. This check is not run rather than made the reason the shop slows down.',
            'remedy' => 'ALTER TABLE order_status_histories ADD INDEX osh_order_created_idx (order_id, created_at); then reload this page.',
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // The findings

    /**
     * @param  array<string, mixed>  $shop
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>  $lines
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $stuckHours
     * @return array<int, array<string, mixed>>
     */
    private function findings(array $shop, array $sample, array $lines, array $ledger, Carbon $standingSince, array $stuckHours): array
    {
        return [
            $this->paidWithNoItems($sample, $lines),
            $this->totalsThatDoNotMatch($sample, $lines),
            $this->duplicates($sample),
            $this->stuck($shop, $standingSince, $stuckHours),
            $this->deliveredWithNoRecord($shop, $sample, $ledger),
            $this->withNoCustomer($sample),
        ];
    }

    /**
     * Paid, and `order_details` holds nothing for it.
     *
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>  $lines
     * @return array<string, mixed>
     */
    private function paidWithNoItems(array $sample, array $lines): array
    {
        $meaning = 'Money was taken and the order has nothing in it. Either the line write failed after the payment was accepted, or the lines were deleted afterwards. The customer has been charged for an order the warehouse cannot pick.';
        $action = 'Open each order id against order_details and the gateway record. If the lines are genuinely gone the order has to be rebuilt from the payment or refunded — it cannot be fulfilled as it stands.';

        $blocked = $this->blocked('paid_orders_with_no_items', 'critical', $sample, $lines, $meaning, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($sample['rows'] as $order) {
            if ($order['payment_status']['value'] !== 'paid' || isset($lines['by_order'][$order['id']])) {
                continue;
            }

            $rows[] = $this->row($order, 'Marked paid, and order_details holds no line for it.');
        }

        return $this->finding(
            key: 'paid_orders_with_no_items',
            severity: 'critical',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::ORDERS_SOURCE . ' left against ' . self::LINES_SOURCE,
            index: $sample['index'] . ' then ' . $lines['index'],
            sample: $sample,
            emptyNote: 'Every paid order examined in this period carries at least one line.',
        );
    }

    /**
     * The header total does not equal the lines under it.
     *
     * An order is only reported when NO reasonable reading of its own columns reconciles: the lines
     * plus shipping less the discounts, that same figure plus the tax the header recorded, and that
     * same figure plus the tax the lines recorded are all tried, and the order is a finding only if
     * it matches none of them. Tax modelling in this build is settings-driven — `include` folds the
     * tax into the price and `exclude` adds it — and a check that picked one interpretation would
     * report every order on half the deployments in existence.
     *
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>  $lines
     * @return array<string, mixed>
     */
    private function totalsThatDoNotMatch(array $sample, array $lines): array
    {
        $meaning = 'The order total is not the sum of the order. A line was added, removed or repriced after the total was written, or the total was written from a different cart. Every downstream figure — commission, payout, the invoice the customer holds — is computed from one of the two numbers, so they now disagree as well.';
        $action = 'Compare the order against its lines before touching anything: the difference tells you which side moved. Orders edited after placement are the usual cause, and edited_status on the order says whether that is what happened.';

        $blocked = $this->blocked('order_totals_that_do_not_match_their_lines', 'major', $sample, $lines, $meaning, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($sample['rows'] as $order) {
            $line = $lines['by_order'][$order['id']] ?? null;
            if ($line === null || $line['lines_total'] === null || $order['amount'] === null) {
                continue;
            }

            $base = $line['lines_total']
                + ($order['shipping_cost'] ?? 0.0)
                - ($order['discount_amount'] ?? 0.0)
                - ($order['extra_discount'] ?? 0.0)
                - ($order['refer_and_earn_discount'] ?? 0.0);

            $candidates = [
                'without_tax' => $base,
                'with_the_headers_tax' => $base + ($order['total_tax_amount'] ?? 0.0),
                'with_the_lines_tax' => $base + ($line['lines_tax'] ?? 0.0),
            ];

            $nearest = null;
            $difference = null;
            foreach ($candidates as $candidate) {
                $gap = $order['amount'] - $candidate;
                if ($difference === null || abs($gap) < abs($difference)) {
                    $difference = $gap;
                    $nearest = $candidate;
                }
            }

            if ($difference === null || abs($difference) <= self::AMOUNT_TOLERANCE) {
                continue;
            }

            $rows[] = $this->row($order, 'Its ' . $line['lines'] . ' ' . ($line['lines'] === 1 ? 'line adds' : 'lines add')
                . ' to ' . $this->money($nearest) . ' at the nearest reading of its own columns; the order says '
                . $this->money($order['amount']) . ', a difference of ' . $this->money($difference) . '.');
        }

        return $this->finding(
            key: 'order_totals_that_do_not_match_their_lines',
            severity: 'major',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::ORDERS_SOURCE . ' against ' . self::LINES_SOURCE,
            index: $sample['index'] . ' then ' . $lines['index'],
            sample: $sample,
            emptyNote: 'Every order examined in this period reconciles with its own lines to within ' . $this->money(self::AMOUNT_TOLERANCE) . '.',
            caveat: 'Compared as lines + shipping − coupon − free-delivery − referral, tried against the order with no tax, with the tax the header recorded and with the tax the lines recorded. An order is listed only when none of the three reconciles.',
        );
    }

    /**
     * The same customer, the same amount, seconds apart.
     *
     * A heuristic, and named as one on the page. There is no idempotency key on checkout in this
     * build — order_group_id defaults to the literal 'def-order-group' and separates the vendor
     * split of one basket rather than one submit from the next — so customer, amount and elapsed
     * seconds are the only evidence a double submit leaves behind.
     *
     * Grouped in PHP over the sample already read, deliberately: the SQL for this is a self-join on
     * orders, and a self-join on the orders table is the query that takes a shop down.
     *
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function duplicates(array $sample): array
    {
        $meaning = 'One customer has two orders for the same amount within ' . self::DUPLICATE_GAP_SECONDS
            . ' seconds. That is what a double-clicked checkout, a retried request or a gateway callback fired twice looks like from the orders table. If both were charged, the customer paid twice.';
        $action = 'Check each pair against the gateway before cancelling either — two genuine identical orders minutes apart do happen. Where it is a double submit, cancel the later order and refund it.';

        $blocked = $this->blocked('duplicate_orders_seconds_apart', 'major', $sample, null, $meaning, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $groups = [];
        foreach ($sample['rows'] as $order) {
            if ($order['customer_key'] === null || $order['amount'] === null || $order['created_seconds'] === null) {
                continue;
            }

            $groups[$order['customer_key'] . '|' . number_format($order['amount'], 2, '.', '')][] = $order;
        }

        $rows = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            usort($group, static fn (array $left, array $right) => $left['created_seconds'] <=> $right['created_seconds']);

            foreach ($group as $index => $order) {
                if ($index === 0) {
                    continue;
                }

                $previous = $group[$index - 1];
                $gap = $order['created_seconds'] - $previous['created_seconds'];
                if ($gap > self::DUPLICATE_GAP_SECONDS) {
                    continue;
                }

                $rows[] = $this->row($order, 'Placed ' . $gap . ' ' . ($gap === 1 ? 'second' : 'seconds')
                    . ' after order ' . $previous['id'] . ', same customer and same amount.');
            }
        }

        return $this->finding(
            key: 'duplicate_orders_seconds_apart',
            severity: 'major',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::ORDERS_SOURCE,
            index: $sample['index'],
            sample: $sample,
            emptyNote: 'No two orders examined in this period share a customer and an amount inside '
                . self::DUPLICATE_GAP_SECONDS . ' seconds.',
            caveat: 'A heuristic, not a fact: this build has no idempotency key on checkout, so two identical orders placed deliberately are indistinguishable from one submitted twice. Guest orders with no customer id are not grouped at all.',
        );
    }

    /**
     * Orders that have not moved.
     *
     * One query per open status rather than a whereIn: `order_status = ? AND created_at >= ?` is
     * the exact prefix of idx_orders_status_created_at, so each read is an ordered index range with
     * a limit on it. A whereIn over four statuses with an ORDER BY would sort the union instead.
     *
     * The window here is the fixed standing lookback, not the selected range. An order stuck since
     * Tuesday is the finding while somebody is looking at the last hour, and a range selector that
     * could hide it would be a control for making the page look better.
     *
     * @param  array<string, mixed>  $shop
     * @param  array<string, mixed>  $stuckHours
     * @return array<string, mixed>
     */
    private function stuck(array $shop, Carbon $standingSince, array $stuckHours): array
    {
        $hours = $stuckHours['stuck_order_hours'];
        $meaning = 'These orders have sat in one status for longer than ' . $this->number($hours)
            . ' hours without being touched. Nobody is working them: a confirmed order that never reaches processing is a customer waiting, and an out-for-delivery order that never closes is stock the shop has already lost sight of.';
        $action = 'Work the oldest first — they are listed oldest first. If a whole status is stuck the cause is upstream of the orders: a vendor who stopped accepting, a courier integration that stopped answering, or a worker that stopped running.';

        $base = [
            'source' => self::ORDERS_SOURCE,
            'index' => 'idx_orders_status_created_at (order_status, created_at)',
            'scope' => 'standing',
        ];

        if ($shop['state'] !== 'ok') {
            return $this->finding(
                key: 'orders_stuck_in_one_status',
                severity: 'major',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: $base['source'],
                index: $base['index'],
                sample: null,
                emptyNote: null,
                state: $shop['state'],
                note: null,
                scope: 'standing',
                blockedByConnection: true,
            );
        }

        $threshold = $this->shopBound(Clock::now()->copy()->subMinutes((int) round($hours * 60)));
        $lower = $this->shopBound($standingSince);
        $rows = [];
        $truncated = false;

        try {
            $connection = $this->connection();
            foreach (self::OPEN_STATUSES as $status) {
                $found = $connection->table('orders')
                    ->where('order_status', $status)
                    ->where('created_at', '>=', $lower)
                    ->where('updated_at', '<', $threshold)
                    ->orderBy('created_at')
                    ->limit(self::MAX_STUCK_PER_STATUS + 1)
                    ->get(['id', 'order_status', 'payment_status', 'order_amount', 'created_at', 'updated_at']);

                $truncated = $truncated || $found->count() > self::MAX_STUCK_PER_STATUS;

                foreach ($found->take(self::MAX_STUCK_PER_STATUS) as $row) {
                    $idle = $this->shopHoursSince($row->updated_at);

                    $rows[] = $this->row(
                        [
                            'id' => (int) $row->id,
                            'order_status' => $this->vocabulary($row->order_status),
                            'payment_status' => $this->vocabulary($row->payment_status),
                            'amount' => $this->floatOrNull($row->order_amount),
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ],
                        $idle === null
                            ? 'Its last-touched time cannot be read, so how long it has been still is unknown.'
                            : 'Untouched for ' . $this->number($idle) . ' hours; the threshold is ' . $this->number($hours) . '.',
                    );
                }
            }
        } catch (\Throwable $exception) {
            return $this->finding(
                key: 'orders_stuck_in_one_status',
                severity: 'major',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: $base['source'],
                index: $base['index'],
                sample: null,
                emptyNote: null,
                state: 'failed',
                note: $this->failureNote($exception),
                remedy: 'The orders table is part of the shop schema. Import installation/backup/database.sql, then run `php artisan migrate`.',
                scope: 'standing',
            );
        }

        return $this->finding(
            key: 'orders_stuck_in_one_status',
            severity: 'major',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: $base['source'],
            index: $base['index'],
            sample: null,
            emptyNote: 'No order in an open status has been untouched for more than ' . $this->number($hours) . ' hours.',
            scope: 'standing',
            truncated: $truncated,
            caveat: 'Read over the last ' . self::STANDING_LOOKBACK_DAYS
                . ' days whatever range is selected, at most ' . self::MAX_STUCK_PER_STATUS
                . ' per status. An order with no last-touched time is not counted, because there is nothing to measure the delay from.',
        );
    }

    /**
     * Delivered, with nothing in the status ledger to say so.
     *
     * @param  array<string, mixed>  $shop
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function deliveredWithNoRecord(array $shop, array $sample, array $ledger): array
    {
        $meaning = 'The order says delivered and order_status_histories holds no delivered row for it, so nothing records who closed it or when. The status ledger in this build is written by hand at three call sites, so any other path that set the status left no trail — and with no trail there is no way to answer a customer who says the parcel never arrived.';
        $action = 'Treat a small number as the paths that write the status directly; treat a large number as the ledger not being written at all. The fix is at the source: write the history from the model event that already fires on Order::updated rather than from each controller.';

        if ($ledger['state'] !== 'ok') {
            return $this->finding(
                key: 'delivered_orders_with_no_status_record',
                severity: 'minor',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: self::LEDGER_SOURCE,
                index: 'none — this is the gap',
                sample: null,
                emptyNote: null,
                state: $ledger['state'],
                note: $ledger['note'],
                remedy: $ledger['remedy'],
                blockedByConnection: $shop['state'] !== 'ok',
            );
        }

        $blocked = $this->blocked('delivered_orders_with_no_status_record', 'minor', $sample, null, $meaning, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $delivered = array_values(array_filter(
            $sample['rows'],
            static fn (array $order) => $order['order_status']['value'] === 'delivered',
        ));

        if ($delivered === []) {
            return $this->finding(
                key: 'delivered_orders_with_no_status_record',
                severity: 'minor',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: self::LEDGER_SOURCE,
                index: $ledger['index'] ?? '',
                sample: $sample,
                emptyNote: 'No order examined in this period is marked delivered, so there was nothing to look for in the ledger.',
            );
        }

        $ids = array_column($delivered, 'id');

        try {
            // Rides the index this block just confirmed leads with order_id; without it this read
            // would be a scan of the audit table and the block above refuses to run at all.
            $recorded = $this->connection()->table('order_status_histories')
                ->whereIn('order_id', $ids)
                ->where('status', 'delivered')
                ->limit(count($ids) + 1)
                ->distinct()
                ->pluck('order_id');
        } catch (\Throwable $exception) {
            return $this->finding(
                key: 'delivered_orders_with_no_status_record',
                severity: 'minor',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: self::LEDGER_SOURCE,
                index: $ledger['index'] ?? '',
                sample: null,
                emptyNote: null,
                state: 'failed',
                note: $this->failureNote($exception),
            );
        }

        $known = array_flip(array_map('intval', $recorded->all()));

        $rows = [];
        foreach ($delivered as $order) {
            if (isset($known[$order['id']])) {
                continue;
            }

            $rows[] = $this->row($order, 'Marked delivered, with no delivered row in the status ledger.');
        }

        return $this->finding(
            key: 'delivered_orders_with_no_status_record',
            severity: 'minor',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::LEDGER_SOURCE,
            index: $ledger['index'] ?? '',
            sample: $sample,
            emptyNote: 'Every delivered order examined in this period has a delivered row in the status ledger.',
            caveat: 'Only the delivered orders inside the examined sample are looked up, and only rows in order_status_histories count as a record. An order delivered before the ledger was in use will show here and is not a defect.',
        );
    }

    /**
     * An order with nobody attached to it.
     *
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function withNoCustomer(array $sample): array
    {
        $meaning = 'The order carries no customer id at all. Nothing links it to an account or to a guest record, so it cannot be found from the customer\'s side, cannot be included in their order history, and cannot be traced back to whoever placed it.';
        $action = 'Recover the identity from the shipping address row or the payment record and write it back. A rising count points at a checkout path that creates orders before it resolves the customer.';

        $blocked = $this->blocked('orders_with_no_customer', 'minor', $sample, null, $meaning, $action);
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($sample['rows'] as $order) {
            if ($order['customer_key'] !== null) {
                continue;
            }

            $rows[] = $this->row($order, $order['is_guest']
                ? 'No customer id, and flagged as a guest order — the guest record it should point at is missing too.'
                : 'No customer id, and not flagged as a guest order.');
        }

        return $this->finding(
            key: 'orders_with_no_customer',
            severity: 'minor',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::ORDERS_SOURCE,
            index: $sample['index'],
            sample: $sample,
            emptyNote: 'Every order examined in this period carries a customer id.',
            caveat: 'A guest order is not a finding by itself: guest checkouts store the guest record\'s id in the same column. Only an empty or zero id is listed.',
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Building one finding

    /**
     * The shared shape every check returns, whatever happened to it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $sample
     * @return array<string, mixed>
     */
    private function finding(
        string $key,
        string $severity,
        array $rows,
        string $meaning,
        string $action,
        string $source,
        string $index,
        ?array $sample,
        ?string $emptyNote,
        ?string $caveat = null,
        string $state = 'ok',
        ?string $note = null,
        ?string $remedy = null,
        string $scope = 'window',
        bool $truncated = false,
        bool $blockedByConnection = false,
    ): array {
        $sampled = $sample !== null && ($sample['truncated'] ?? false);
        $count = $state === 'ok' ? count($rows) : null;

        return [
            'key' => $key,
            'severity' => $severity,
            'state' => $state,
            'note' => $note ?? ($state === 'ok' && $rows === [] ? $emptyNote : null),
            'remedy' => $remedy,
            'meaning' => $meaning,
            'action' => $action,
            'caveat' => $caveat,
            'source' => $source,
            'index' => $index,
            'scope' => $scope,
            'count' => $count,
            // False when the read stopped at its limit: the number is then a floor, not a total.
            'count_exact' => $state === 'ok' && !$sampled && !$truncated,
            'amount' => $state === 'ok' ? $this->sumAmounts($rows) : null,
            'amount_known' => $state === 'ok' && $this->amountsKnown($rows),
            'rows' => array_slice($rows, 0, self::MAX_FINDING_ROWS),
            'truncated' => $truncated || count($rows) > self::MAX_FINDING_ROWS,
            'limit' => self::MAX_FINDING_ROWS,
            'examined' => $sample === null ? null : ($sample['examined'] ?? null),
            'sample_truncated' => $sampled,
            'blocked_by_connection' => $blockedByConnection,
        ];
    }

    /**
     * The check could not run because something it depends on could not be read.
     *
     * Returns null when everything it needs is present. The reason is deliberately left null when
     * the connection banner already carries it: one fault said six times reads as six faults.
     *
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>|null  $lines
     * @return array<string, mixed>|null
     */
    private function blocked(string $key, string $severity, array $sample, ?array $lines, string $meaning, string $action): ?array
    {
        foreach ([$sample, $lines] as $dependency) {
            if ($dependency === null || $dependency['state'] === 'ok') {
                continue;
            }

            return $this->finding(
                key: $key,
                severity: $severity,
                rows: [],
                meaning: $meaning,
                action: $action,
                source: $dependency['source'],
                index: $dependency['index'] ?? '',
                sample: null,
                emptyNote: null,
                state: $dependency['state'],
                note: $dependency['note'] ?? null,
                remedy: $dependency['remedy'] ?? null,
                blockedByConnection: ($dependency['note'] ?? null) === null && $dependency['state'] === 'failed',
            );
        }

        return null;
    }

    /**
     * One order in a finding, with nothing on it that identifies a person.
     *
     * Id, status, amount and age only. A name or an address on a monitoring page is a copy of
     * customer data in a place nobody remembers to protect, and none of it is needed to fix an
     * order that contradicts itself.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function row(array $order, string $detail): array
    {
        return [
            'id' => $order['id'],
            'order_status' => $order['order_status']['value'],
            'order_status_known' => $order['order_status']['known'],
            'payment_status' => $order['payment_status']['value'],
            'payment_status_known' => $order['payment_status']['known'],
            'amount' => $order['amount'],
            'age_hours' => $this->shopHoursSince($order['created_at'] ?? null),
            'created_at' => $this->shopStamp($order['created_at'] ?? null),
            'updated_at' => $this->shopStamp($order['updated_at'] ?? null),
            'detail' => $detail,
        ];
    }

    /**
     * Findings ranked by the money behind them.
     *
     * A count is not a priority: ninety orders missing a ledger row matter less than one paid order
     * with nothing in it. Where the money is unknown the finding sorts under everything that has a
     * figure rather than being given a zero to sort by.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $findings, string $sort): array
    {
        $severity = array_flip(self::SEVERITIES);
        $declared = array_flip(self::CHECKS);

        usort($findings, static function (array $left, array $right) use ($sort, $severity, $declared) {
            $leftEmpty = ($left['count'] ?? 0) === 0 || $left['count'] === null;
            $rightEmpty = ($right['count'] ?? 0) === 0 || $right['count'] === null;

            if ($leftEmpty !== $rightEmpty) {
                return $leftEmpty ? 1 : -1;
            }

            if ($sort === 'amount') {
                $leftAmount = $left['amount_known'] ? (float) $left['amount'] : null;
                $rightAmount = $right['amount_known'] ? (float) $right['amount'] : null;

                if ($leftAmount !== $rightAmount) {
                    if ($leftAmount === null) {
                        return 1;
                    }
                    if ($rightAmount === null) {
                        return -1;
                    }

                    return $rightAmount <=> $leftAmount;
                }
            }

            if (($left['count'] ?? 0) !== ($right['count'] ?? 0)) {
                return ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
            }

            $bySeverity = ($severity[$left['severity']] ?? 9) <=> ($severity[$right['severity']] ?? 9);

            return $bySeverity !== 0
                ? $bySeverity
                : ($declared[$left['key']] ?? 99) <=> ($declared[$right['key']] ?? 99);
        });

        return $findings;
    }

    /**
     * The page's own totals, counted over orders rather than over rows.
     *
     * One order can break two rules, and adding the finding counts together would say two orders
     * are wrong when one is. Ids are folded first and each order's amount is counted once.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function summary(array $findings): array
    {
        $amounts = [];
        $unknown = 0;
        $withRows = 0;
        $ran = 0;
        $blocked = 0;

        foreach ($findings as $finding) {
            if (in_array($finding['state'], ['ok', 'no_data'], true)) {
                $ran++;
            } else {
                $blocked++;
            }

            if (($finding['count'] ?? 0) > 0) {
                $withRows++;
            }

            foreach ($finding['rows'] as $row) {
                if ($row['amount'] === null) {
                    $unknown++;
                    continue;
                }

                $amounts[$row['id']] = (float) $row['amount'];
            }
        }

        // Exact only when every check both ran and finished. A blocked check makes the total a
        // lower bound, and a total presented as exact while a check was blind is a claim nobody
        // measured.
        $exact = array_reduce(
            $findings,
            static fn (bool $carry, array $finding) => $carry
                && ($finding['state'] === 'no_data' || ($finding['state'] === 'ok' && $finding['count_exact'])),
            true,
        );

        return [
            'checks_total' => count($findings),
            'checks_ran' => $ran,
            'checks_blocked' => $blocked,
            'findings_with_rows' => $withRows,
            'orders_implicated' => count($amounts) + $unknown,
            'orders_implicated_exact' => $exact,
            'amount_implicated' => $amounts === [] ? null : round(array_sum($amounts), 2),
            'amount_known' => $unknown === 0,
            'amount_note' => 'Summed once per order across every check, over the rows this page lists. Orders carry no currency column, so the figure is in whatever currency the shop stores its totals in.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // The cards above the findings

    /**
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>  $summary
     * @return array<string, Metric>
     */
    private function headline(array $volume, array $sample, array $summary): array
    {
        $headline = [];

        $headline['orders_created_in_this_period'] = $volume['state'] === 'failed' || $volume['total'] === null
            ? Metric::noData(source: self::ORDERS_SOURCE, note: $volume['note'] ?? 'The order count for this period could not be read.')
            : Metric::of(value: $volume['total'], source: self::ORDERS_SOURCE);

        $headline['orders_examined_for_contradictions'] = $sample['examined'] === null
            ? Metric::noData(source: self::ORDERS_SOURCE, note: $sample['note'] ?? 'No order could be read to check.')
            : Metric::of(
                value: $sample['examined'],
                source: self::ORDERS_SOURCE,
                unit: null,
                note: $sample['truncated']
                    ? 'The period holds more orders than this page reads. Every count below is over these ' . $sample['examined'] . ' and is a floor, not a total.'
                    : null,
            );

        // Zero contradictions is only a reading when at least one check actually ran. With every
        // check blocked the same 0 would say the orders are sound, which is the exact claim nothing
        // on this page is in a position to make.
        $blindNote = 'No check could run, so this is not a count of nothing wrong — it is the absence of a count.';
        $partialNote = 'Counted over the ' . $summary['checks_ran'] . ' of ' . $summary['checks_total']
            . ' checks that ran; the rest could not look.';

        $headline['orders_that_contradict_themselves'] = $summary['checks_ran'] === 0
            ? Metric::noData(source: self::ORDERS_SOURCE . ', ' . self::LINES_SOURCE, note: $blindNote)
            : Metric::of(
                value: $summary['orders_implicated'],
                source: self::ORDERS_SOURCE . ', ' . self::LINES_SOURCE,
                unit: null,
                note: match (true) {
                    $summary['checks_blocked'] > 0 => $partialNote,
                    !$summary['orders_implicated_exact'] => 'At least this many: one of the checks stopped at its limit.',
                    default => 'Counted once per order, however many checks it breaks.',
                },
            );

        $headline['money_in_contradicted_orders'] = $summary['amount_implicated'] === null
            ? Metric::noData(
                source: self::ORDERS_SOURCE,
                note: match (true) {
                    $summary['checks_ran'] === 0 => $blindNote,
                    $summary['orders_implicated'] > 0 => 'No amount could be read for the orders listed.',
                    default => 'Nothing is listed, so there is no money behind it.',
                },
            )
            : Metric::of(
                value: $summary['amount_implicated'],
                source: self::ORDERS_SOURCE . '.order_amount',
                unit: null,
                note: $summary['checks_blocked'] > 0 ? $partialNote : $summary['amount_note'],
            );

        $headline['checks_that_could_not_run'] = Metric::of(
            value: $summary['checks_blocked'],
            source: 'this panel',
            unit: null,
            note: $summary['checks_blocked'] > 0
                ? 'A check that could not run is not a check that found nothing. Each one says why underneath.'
                : null,
        );

        return $headline;
    }

    // ---------------------------------------------------------------------------------------------
    // What this page cannot know

    /**
     * The four gaps that decide how far these findings can be trusted.
     *
     * Published as unconfigured readings rather than left unsaid. Each one is the reason a check
     * above is a heuristic, is capped, or is not run at all, and each names the exact change that
     * would remove the caveat.
     *
     * @return array<string, mixed>
     */
    private function gaps(): array
    {
        return [
            'state' => 'not_configured',
            'source' => self::ORDERS_SOURCE . ', ' . self::LEDGER_SOURCE,
            'note' => 'Nothing on this deployment records these, so the checks above work around their absence. They are drawn as readings rather than left out, because a caveat nobody states is a caveat nobody applies.',
            'fields' => [
                'checkout_idempotency_key' => Metric::notConfigured(
                    source: self::ORDERS_SOURCE,
                    remedy: 'Add orders.checkout_token char(36) with a unique index and set it from the cart group in app/Utils/OrderManager.php::generateOrder, then duplicates become exact instead of inferred.',
                    note: 'There is no key that says two orders came from one submit. order_group_id defaults to the literal def-order-group and separates the vendor split of one basket, not one checkout from the next.',
                ),
                'status_ledger_index' => Metric::notConfigured(
                    source: self::LEDGER_SOURCE,
                    remedy: 'ALTER TABLE order_status_histories ADD INDEX osh_order_created_idx (order_id, created_at);',
                    note: 'The status ledger has a primary key on id and no other index, so an order cannot be looked up in it without reading the whole table. The delivered-order check refuses to run until this exists.',
                ),
                'status_ledger_writer' => Metric::notConfigured(
                    source: self::LEDGER_SOURCE,
                    remedy: 'Write the history from the Order::updated listener in app/Services/Analytics/CommerceInstrumentation.php, which already fires on a status change, instead of from each controller.',
                    note: 'The ledger is written by hand in three controllers. Any other path that sets orders.order_status leaves no row at all, so a missing record can mean a missing write rather than a missing delivery.',
                ),
                'paid_orders_index' => Metric::notConfigured(
                    source: self::ORDERS_SOURCE,
                    remedy: 'ALTER TABLE orders ADD INDEX idx_orders_payment_status_created_at (payment_status, created_at);',
                    note: 'There is an index on payment_status and one on created_at, and none on the pair. Paid orders in a period are therefore read through the sample this page takes rather than looked up directly, which is why the paid-with-no-items count is over the sample.',
                ),
                'per_status_sla_targets' => Metric::notConfigured(
                    source: 'monitoring_settings (thresholds.stuck_order_hours)',
                    remedy: 'Move the one threshold with `php artisan tinker`: '
                        . "app(App\\Services\\Monitoring\\Support\\MonitoringSettings::class)->put('thresholds.stuck_order_hours', 12); "
                        . '— or change thresholds.stuck_order_hours in config/monitoring.php to move what a fresh install starts from. '
                        . 'Monitoring → Settings prints the value in force but has no form that writes it. A per-status target needs a column on the shop side that does not exist yet.',
                    note: 'The shop stores no promise about how long a status may take, so one threshold is applied to all of them.',
                ),
                'stored_integrity_history' => Metric::notConfigured(
                    source: 'monitoring_series',
                    remedy: 'Count these findings in the monitoring flush and write them through BucketWriter::SERIES_PREFIX as orders.paid_without_items, orders.amount_mismatch and orders.stuck_count, and they become chartable and alertable.',
                    note: 'Every figure here is computed at the moment the page is opened. Nothing is stored, so there is no trend, no comparison with yesterday and no alert rule that can fire on any of it.',
                ),
            ],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Untrusted input

    /**
     * How the findings are ranked.
     *
     * `?sort[]=x` hands the request an array, and casting one to string is a warning the error
     * handler turns into a throw — which would take the whole section down. Anything that is not
     * one of two literals falls back to the default rather than reaching the sort.
     */
    private function sort(Request $request): string
    {
        $value = $request->query('sort', 'amount');
        $value = is_string($value) ? trim($value) : 'amount';

        return in_array($value, self::SORTS, true) ? $value : 'amount';
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * A stored status, with whether it is one this build writes.
     *
     * translate() persists any key it has not already seen into resources/lang/*\/new-messages.php,
     * so a value read out of a column must never reach it — these are free varchars at the database
     * level, and one unrecognised status would mint a language key per distinct value.
     *
     * @return array{value: string, known: bool}
     */
    private function vocabulary(mixed $stored): array
    {
        $value = is_scalar($stored) ? trim((string) $stored) : '';
        $known = in_array($value, self::ORDER_STATUSES, true) || in_array($value, self::PAYMENT_STATUSES, true);

        return [
            'value' => $known ? $value : $this->redactor->text(mb_substr($value, 0, 50)),
            'known' => $known,
        ];
    }

    /** The grouping key for a double submit, or null when the order has no customer on it. */
    private function customerKey(mixed $stored): ?string
    {
        $value = is_scalar($stored) ? trim((string) $stored) : '';

        return $value === '' || $value === '0' ? null : mb_substr($value, 0, 15);
    }

    /**
     * A stamp the shop wrote, in the timezone the dashboard renders in.
     *
     * The shop writes its timestamps in config('app.timezone'); monitoring stores its own in UTC.
     * Reading an order's created_at as UTC would put every order six hours away from the truth on
     * this deployment, so the source zone is the process zone, exactly as the queue panel does for
     * failed_jobs.
     */
    private function shopStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Carbon::parse($stored, date_default_timezone_get())
                ->setTimezone(Clock::displayTimezone())
                ->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the order really exists,
            // and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? $this->shortText((string) $stored, 40) : null;
        }
    }

    /**
     * A UTC bound turned into the literal the shop's own columns are comparable with.
     *
     * The same six-hour error in the other direction: a window computed in UTC and compared against
     * a column written in Asia/Dhaka silently reads the wrong six hours.
     */
    private function shopBound(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s');
    }

    private function shopSeconds(mixed $stored): ?int
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return (int) Carbon::parse($stored, date_default_timezone_get())->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private function shopHoursSince(mixed $stored): ?int
    {
        $seconds = $this->shopSeconds($stored);

        return $seconds === null ? null : max(0, intdiv(Clock::now()->getTimestamp() - $seconds, 3600));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sumAmounts(array $rows): ?float
    {
        $amounts = array_filter(array_column($rows, 'amount'), static fn ($amount) => $amount !== null);

        return $amounts === [] ? null : round(array_sum($amounts), 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function amountsKnown(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['amount'] === null) {
                return false;
            }
        }

        return $rows !== [];
    }

    /** Money as a sentence fragment, for the panel-authored prose that is echoed untranslated. */
    private function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    /** A threshold or a duration, without a trailing ".0" on a whole number. */
    private function number(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ','), '0'), '.');
    }

    /**
     * A failed read, said in one line that is safe to print.
     *
     * A QueryException carries the statement and its bindings, and an exception message is one of
     * the most reliable places in an application to find a token or a customer's address — so it
     * goes through the redactor and is bounded before it reaches a page an operator can screenshot.
     */
    private function failureNote(\Throwable $exception): string
    {
        return class_basename($exception) . ': '
            . $this->redactor->text(mb_substr($exception->getMessage(), 0, 400));
    }

    /**
     * A count, or null when the row had none to give.
     *
     * Null is preserved rather than cast: (int) null is 0, and a zero in a units column would say
     * the order was placed for nothing.
     */
    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function shortText(mixed $value, int $length): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->redactor->text(mb_substr(trim($value), 0, $length));
    }
}
