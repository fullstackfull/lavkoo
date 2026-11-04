<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Inventory integrity: stock that disagrees with itself, read from the shop's own tables.
 *
 * Nothing measures any of this. There is no inventory collector, no inventory event and no stored
 * series, so every figure here is derived at the moment the page is opened from `products`,
 * `stock_movements`, `order_details` and the two marketplace tables — which makes four things
 * load-bearing.
 *
 * COST. A monitoring page that scans the product catalogue or the movement ledger is the outage it
 * was installed to warn about. Every read is bounded by a LIMIT and rides an index that is named in
 * the comment above it, and the two reads that would need an index nobody created are not run: they
 * report themselves with the exact ALTER TABLE that would make them affordable. The movement ledger
 * is read backwards off its primary key rather than by timestamp, because `stock_movements` has no
 * index leading with `created_at` and a window filter on it would scan the whole table.
 *
 * THE SAMPLE IS PART OF THE FINDING. A count here is "how many of the rows this page examined",
 * never "how many exist", except where an index can answer the whole question exactly — negative
 * and zero stock, which `idx_products_current_stock` counts without touching a row. Each finding
 * carries which population it looked at and how big that population was.
 *
 * THE LEDGER IS PARTIAL, AND THAT CHANGES WHAT ITS FINDINGS MEAN. Sales, receipts, returns and
 * manual adjustments write a movement; the restock that runs when an order is cancelled does not
 * (app/Utils/OrderManager.php::getStockUpdateOnOrderStatusChange increments the product directly),
 * the floor-at-zero clamp beside it does not, and warehouse transfers do not. So a product whose
 * live stock has drifted from its last recorded balance is at least as likely to be a movement
 * nobody wrote as stock that went missing, and the two reconciliation checks say that on their own
 * cards rather than in a footnote.
 *
 * STUCK RESERVATIONS CANNOT BE ANSWERED AT ALL. There is no stock-reservation system in this build
 * — no table, no column, no expiry; a cart holds nothing and stock is taken at order generation.
 * That check is published in a not_supported state naming what would have to exist, because an
 * empty reservations table would read as "no reservation is stuck", which is a finding nobody made.
 */
class InventoryIntegrityPanel implements Panel
{
    /**
     * The checks, in the order they are declared.
     *
     * These keys are this section's own vocabulary and reach translate() as compile-time literals.
     * Nothing read out of a column is ever used as a translation key.
     */
    private const CHECKS = [
        'products_with_negative_stock',
        'sellable_products_with_no_stock',
        'variant_quantities_that_do_not_sum_to_the_product',
        'variants_with_negative_quantity',
        'stock_deducted_twice_for_one_order_line',
        'sale_movements_with_no_order_behind_them',
        'movements_whose_running_balance_does_not_add_up',
        'live_stock_that_disagrees_with_the_ledger',
        'cancelled_orders_whose_stock_was_never_returned',
        'warehouse_allocation_above_the_products_stock',
        'expired_batches_still_counted_as_sellable',
        'stuck_stock_reservations',
    ];

    /** How bad a finding is. A fixed vocabulary, so the view may translate it. */
    private const SEVERITIES = ['critical', 'major', 'minor'];

    /** Which population a check looked at. Fixed, so the view may translate it. */
    private const SCOPES = ['catalogue', 'ledger', 'standing', 'unsupported'];

    /** The movement types StockMovement defines — the allowlist that makes translate() safe. */
    private const MOVEMENT_TYPES = ['adjustment', 'receipt', 'sale', 'return', 'transfer'];

    /** The reference types this build actually writes, so a stored one is echoed only if it is ours. */
    private const REFERENCE_TYPES = ['order', 'pos_order', 'return_shipment', 'purchase_order'];

    /** Reference types that point at `orders.id`, which is what makes the line check possible. */
    private const ORDER_REFERENCES = ['order', 'pos_order'];

    /** The order statuses whose stock is supposed to have been given back. */
    private const RESTOCK_STATUSES = ['canceled', 'returned', 'failed'];

    /** How the findings may be ranked. Units first: that is the size of the hole in the shelf. */
    private const SORTS = ['units', 'count'];

    /**
     * Products read off the low end of the stock index, most negative first.
     *
     * The ORDER BY matches the index, so the read stops after this many entries instead of sorting
     * a catalogue.
     */
    private const MAX_STOCK_SAMPLE = 300;

    /** The newest products in the catalogue, read once for both variant checks. */
    private const MAX_CATALOGUE_SAMPLE = 300;

    /**
     * Movements read off the primary key, newest first.
     *
     * A contiguous run from the top of the table on purpose: two sampled movements for one product
     * with no other sampled movement between them really are consecutive, which is what makes the
     * running-balance check correct over a sample rather than merely suggestive.
     */
    private const MAX_MOVEMENTS = 1000;

    /** Orders read per terminal status for the restock check. Three statuses, so at most 300 rows. */
    private const MAX_ORDERS_PER_STATUS = 100;

    /** Order lines read for the two checks that need them. */
    private const MAX_ORDER_LINES = 400;

    /** Ids in any `WHERE id IN (…)` lookup, so a primary-key read can never fan out. */
    private const MAX_PRODUCT_LOOKUP = 200;

    /** Groups read from the warehouse and batch tables. Both group along their leading index column. */
    private const MAX_GROUPS = 200;

    /** Expired batch rows listed. */
    private const MAX_BATCH_ROWS = 100;

    /** Rows listed under one finding. The count above the table is over the population, not this. */
    private const MAX_FINDING_ROWS = 20;

    /**
     * How far back a standing condition is read, whatever the range says.
     *
     * An order cancelled six weeks ago whose stock never came back needs a report run against the
     * database, not a dashboard page holding a scan open while an operator reads.
     */
    private const STANDING_LOOKBACK_DAYS = 30;

    /** Stock is compared in whole units; the columns are integers. */
    private const UNIT_TOLERANCE = 0;

    private const PRODUCTS_SOURCE = 'MySQL products';

    private const MOVEMENTS_SOURCE = 'MySQL stock_movements';

    private const LINES_SOURCE = 'MySQL order_details';

    private const ORDERS_SOURCE = 'MySQL orders';

    private const WAREHOUSE_SOURCE = 'MySQL warehouse_stock';

    private const BATCH_SOURCE = 'MySQL product_batches';

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly Redactor $redactor,
        private readonly DatabaseManager $database,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $standingSince = Clock::daysAgo(self::STANDING_LOOKBACK_DAYS);
        $sort = $this->sort($request);

        $shop = $this->shop();
        $catalogue = $this->catalogue($shop);
        $stock = $this->stockSample($shop);
        $products = $this->catalogueSample($shop);
        $ledger = $this->ledger($shop);

        $findings = $this->named($this->findings($shop, $catalogue, $stock, $products, $ledger, $standingSince));
        $findings = $this->rank($findings, $sort);
        $summary = $this->summary($findings);

        // The movement rows themselves are working data for four checks, not content: a thousand of
        // them in the payload would be the largest thing this dashboard serves, and every fact drawn
        // from them is already in a finding.
        $ledgerCard = $ledger;
        unset($ledgerCard['rows'], $ledgerCard['empty_means_clean']);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'scope' => $this->scope($standingSince),
            'shop' => $shop,
            'ledger' => $ledgerCard,
            'catalogue' => $catalogue,
            'headline' => $this->headline($catalogue, $ledger, $summary, $findings),
            'sort' => $sort,
            'findings' => $findings,
            'summary' => $summary,
            'gaps' => $this->gaps(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // What period, and what population

    /**
     * The populations these checks read, published rather than implied.
     *
     * The range selector at the top of the page does NOT narrow most of this section, and saying so
     * is the difference between a page that is read correctly and one that is not: negative stock is
     * a condition now, not an event inside a window, and a five-minute range that reported none of
     * it would be a statement about the range.
     *
     * @return array<string, mixed>
     */
    private function scope(Carbon $standingSince): array
    {
        return [
            'standing_since' => Clock::display($standingSince)->toDateTimeString(),
            'standing_days' => self::STANDING_LOOKBACK_DAYS,
            'timezone' => Clock::displayTimezone(),
            'stock_sample_limit' => self::MAX_STOCK_SAMPLE,
            'catalogue_sample_limit' => self::MAX_CATALOGUE_SAMPLE,
            'movement_sample_limit' => self::MAX_MOVEMENTS,
            'note' => 'Stock is a condition rather than an event, so the range control does not narrow this section. Catalogue checks read the whole catalogue through a bounded sample, ledger checks read the most recent '
                . self::MAX_MOVEMENTS . ' movements whatever period those cover, and the one order-based check reads the last '
                . self::STANDING_LOOKBACK_DAYS . ' days.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // The connection these tables live on

    /**
     * The shop's own database, stated once at the top.
     *
     * Deliberately NOT the monitoring connection: `config('monitoring.connection')` may point at a
     * separate database that holds no products at all. When this cannot be reached every check below
     * is blank for the same single reason, and that reason is said here once instead of twelve times.
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

    /**
     * Whether a table is present, without asking a question of it.
     *
     * Three of the tables this page reads arrive with the marketplace migrations and are legitimately
     * absent on an older deployment. Null means the catalogue itself could not be read, which is a
     * third answer and not the same as "no".
     */
    private function tableExists(string $table): ?bool
    {
        try {
            return $this->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // The denominator

    /**
     * How much stock the catalogue holds, counted where an index can answer exactly.
     *
     * `current_stock < 0` and `current_stock = 0` are a range and an equality on
     * idx_products_current_stock, so both are answered from the index without reading a product row.
     * Sellable products are counted on idx_products_type_status_request, whose three columns are
     * exactly the three the storefront's own scope filters on. The catalogue total is NOT counted:
     * a COUNT with no predicate reads an entire index, and this page is not allowed to make that
     * read. It is published as a null with the reason rather than as a number nobody bounded.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function catalogue(array $shop): array
    {
        $base = [
            'source' => self::PRODUCTS_SOURCE,
            'index' => 'idx_products_current_stock (current_stock), idx_products_type_status_request (product_type, status, request_status)',
            'negative' => null,
            'zero' => null,
            'sellable' => null,
            'total' => null,
            'total_note' => 'The size of the whole catalogue is not counted here: a count with no condition on it reads an entire index, and every figure on this page is supposed to be bounded. The sellable count beside it is answered from an index that holds all three of its columns.',
            'sellable_definition' => 'Sellable means what Product::scopeActive means on this build: product_type physical, status 1 and request_status 1. The published column is not part of that scope here.',
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
            $negative = (int) $connection->table('products')->where('current_stock', '<', 0)->count();
            $zero = (int) $connection->table('products')->where('current_stock', '=', 0)->count();
            $sellable = (int) $connection->table('products')
                ->where('product_type', 'physical')
                ->where('status', 1)
                ->where('request_status', 1)
                ->count();
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing the denominator costs one card,
            // while letting it escape would blank twelve checks that read perfectly well.
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The products table is part of the shop schema. Import installation/backup/database.sql, then run `php artisan migrate`.',
                'blocked_by_connection' => false,
            ]);
        }

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'blocked_by_connection' => false,
            'negative' => $negative,
            'zero' => $zero,
            'sellable' => $sellable,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // The two product samples

    /**
     * Products at or below zero stock, most negative first.
     *
     * Rides idx_products_current_stock (current_stock): the ORDER BY is the index's own order, so
     * this is an ordered range scan off the low end that stops after the limit. Products whose stock
     * is NULL are not in the range at all — the column is nullable and a digital product legitimately
     * carries no stock figure — which is why nothing here treats NULL as zero.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function stockSample(array $shop): array
    {
        $base = [
            'source' => self::PRODUCTS_SOURCE,
            'index' => 'idx_products_current_stock (current_stock)',
            // An empty catalogue sample is a measured absence of candidates, not an absent
            // measurement: no product is at or below zero, so no product can be negative either.
            'empty_means_clean' => true,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_STOCK_SAMPLE,
            'examined' => null,
        ];

        if ($shop['state'] !== 'ok') {
            return array_merge($base, ['state' => $shop['state'], 'note' => null, 'remedy' => null]);
        }

        try {
            $rows = $this->connection()->table('products')
                ->where('current_stock', '<=', 0)
                ->orderBy('current_stock')
                ->limit(self::MAX_STOCK_SAMPLE + 1)
                ->get(['id', 'name', 'current_stock', 'product_type', 'variant_product', 'status', 'published', 'request_status', 'updated_at']);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The products table is part of the shop schema. Import installation/backup/database.sql, then run `php artisan migrate`.',
            ]);
        }

        $products = [];
        foreach ($rows->take(self::MAX_STOCK_SAMPLE) as $row) {
            $products[] = $this->product($row);
        }

        return array_merge($base, [
            'state' => $products === [] ? 'no_data' : 'ok',
            'note' => $products === [] ? 'No product in the catalogue is at or below zero stock.' : null,
            'remedy' => null,
            'rows' => $products,
            'truncated' => $rows->count() > self::MAX_STOCK_SAMPLE,
            'examined' => count($products),
        ]);
    }

    /**
     * The most recently created products, read once for both variant checks.
     *
     * Rides idx_products_created_at (created_at) as a descending range that stops at the limit.
     * There is no index on variant_product, so a variant product is found by reading a bounded slice
     * of the catalogue and looking, rather than by asking the catalogue a question it cannot answer
     * without reading all of itself.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function catalogueSample(array $shop): array
    {
        $base = [
            'source' => self::PRODUCTS_SOURCE,
            'index' => 'idx_products_created_at (created_at)',
            'empty_means_clean' => true,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_CATALOGUE_SAMPLE,
            'examined' => null,
            'variants' => 0,
            'unreadable_variation' => 0,
        ];

        if ($shop['state'] !== 'ok') {
            return array_merge($base, ['state' => $shop['state'], 'note' => null, 'remedy' => null]);
        }

        try {
            $rows = $this->connection()->table('products')
                ->orderByDesc('created_at')
                ->limit(self::MAX_CATALOGUE_SAMPLE + 1)
                ->get(['id', 'name', 'current_stock', 'product_type', 'variant_product', 'variation', 'status', 'published', 'request_status', 'updated_at']);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The products table is part of the shop schema. Import installation/backup/database.sql, then run `php artisan migrate`.',
            ]);
        }

        $products = [];
        $variants = 0;
        $unreadable = 0;

        foreach ($rows->take(self::MAX_CATALOGUE_SAMPLE) as $row) {
            $product = $this->product($row);
            $product['variation'] = $this->variation($row->variation ?? null);

            if ($product['variant_product']) {
                $variants++;
                if ($product['variation']['state'] === 'failed') {
                    $unreadable++;
                }
            }

            $products[] = $product;
        }

        return array_merge($base, [
            'state' => $products === [] ? 'no_data' : 'ok',
            'note' => $products === [] ? 'The catalogue holds no product, so there was nothing to check.' : null,
            'remedy' => null,
            'rows' => $products,
            'truncated' => $rows->count() > self::MAX_CATALOGUE_SAMPLE,
            'examined' => count($products),
            'variants' => $variants,
            'unreadable_variation' => $unreadable,
        ]);
    }

    /**
     * One product row, with nothing on it that identifies a person.
     *
     * @return array<string, mixed>
     */
    private function product(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $this->shortText($row->name ?? null, 80),
            // Preserved rather than cast: (int) null is 0, and a zero in a stock column is the
            // single most misleading value this page could print.
            'current_stock' => $this->integerOrNull($row->current_stock ?? null),
            'product_type' => $this->vocabulary($row->product_type ?? null, ['physical', 'digital']),
            'variant_product' => (int) ($row->variant_product ?? 0) === 1,
            'enabled' => (int) ($row->status ?? 0) === 1,
            'approved' => (int) ($row->request_status ?? 0) === 1,
            'published' => (int) ($row->published ?? 0) === 1,
            'updated_at' => $this->shopStamp($row->updated_at ?? null),
        ];
    }

    /**
     * The variant quantities a product carries, decoded from its own column.
     *
     * `products.variation` is the live per-variant quantity in this build: every order path adjusts
     * it under the product's row lock. The `product_stocks` table is a second variant table with a
     * primary key and no index on product_id, and nothing in app/ writes it — which is why this
     * reads the column rather than joining the table.
     *
     * A block rather than a bare list: "no variants" and "the column is not readable JSON" both
     * produce no quantities and mean opposite things.
     *
     * @return array<string, mixed>
     */
    private function variation(mixed $stored): array
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return ['state' => 'no_data', 'rows' => [], 'total' => null, 'negatives' => 0];
        }

        $decoded = json_decode((string) $stored, true);
        if (!is_array($decoded)) {
            return ['state' => 'failed', 'rows' => [], 'total' => null, 'negatives' => 0];
        }

        $rows = [];
        $total = 0;
        $negatives = 0;
        $unreadable = 0;

        foreach ($decoded as $variant) {
            if (!is_array($variant)) {
                $unreadable++;

                continue;
            }

            $quantity = $this->integerOrNull($variant['qty'] ?? null);
            if ($quantity === null) {
                $unreadable++;

                continue;
            }

            $total += $quantity;
            if ($quantity < 0) {
                $negatives++;
            }

            $rows[] = [
                // A variant type is free text an operator typed. It is echoed, never translated, and
                // goes through the redactor like every other string this system did not author.
                'type' => $this->shortText($variant['type'] ?? null, 40),
                'qty' => $quantity,
            ];
        }

        return [
            // An entry with no readable qty makes the sum a partial one, and a partial sum compared
            // against the product total would report a mismatch this page invented.
            'state' => $unreadable > 0 ? 'failed' : ($rows === [] ? 'no_data' : 'ok'),
            'rows' => $rows,
            'total' => $unreadable > 0 || $rows === [] ? null : $total,
            'negatives' => $negatives,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // The movement ledger

    /**
     * The most recent movements, read backwards off the primary key.
     *
     * NOT filtered by time, deliberately. `stock_movements` carries sm_product_time_idx
     * (product_id, created_at) and sm_type_idx (type) and nothing that leads with created_at, so a
     * window filter on it would read the whole table to find out that most of it does not match. The
     * primary key descending is insertion order, so this is a contiguous run from the top of the
     * ledger that costs exactly its own limit — and the period it happens to cover is published
     * rather than assumed to be the one on the range selector.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function ledger(array $shop): array
    {
        $base = [
            'source' => self::MOVEMENTS_SOURCE,
            'index' => 'PRIMARY (id), read descending',
            // An empty ledger is NOT a clean ledger. Nothing was recorded, so nothing could be
            // compared, and a check drawn as "found nothing" here would be the reassuring lie.
            'empty_means_clean' => false,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_MOVEMENTS,
            'examined' => null,
            'oldest_at' => null,
            'newest_at' => null,
            'by_type' => [],
            'unknown_types' => 0,
            'writers' => 'Written by app/Services/Marketplace/InventoryService::record — from order generation and POS (sale), purchase receipts (receipt), return shipments (return) and manual adjustments (adjustment).',
        ];

        if ($shop['state'] !== 'ok') {
            return array_merge($base, ['state' => $shop['state'], 'note' => null, 'remedy' => null]);
        }

        $exists = $this->tableExists('stock_movements');
        if ($exists === false) {
            return array_merge($base, [
                'state' => 'not_configured',
                'note' => 'This deployment has no stock_movements table, so no stock change has a record behind it and the four ledger checks below have nothing to read.',
                'remedy' => 'Run `php artisan migrate` — the ledger is created by database/migrations/2026_08_09_700004_create_stock_movements_table.php.',
            ]);
        }

        try {
            $rows = $this->connection()->table('stock_movements')
                ->orderByDesc('id')
                ->limit(self::MAX_MOVEMENTS + 1)
                ->get(['id', 'product_id', 'type', 'qty_change', 'balance_after', 'reason', 'reference_type', 'reference_id', 'created_at']);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The movement ledger is created by `php artisan migrate`. Check the shop connection is reachable and migrated.',
            ]);
        }

        $movements = [];
        $byType = [];
        $unknown = 0;

        foreach ($rows->take(self::MAX_MOVEMENTS) as $row) {
            $type = $this->vocabulary($row->type ?? null, self::MOVEMENT_TYPES);
            if (!$type['known']) {
                $unknown++;
            }

            $byType[$type['value']] ??= ['type' => $type['value'], 'known' => $type['known'], 'movements' => 0];
            $byType[$type['value']]['movements']++;

            $movements[] = [
                'id' => (int) $row->id,
                'product_id' => $this->integerOrNull($row->product_id ?? null),
                'type' => $type,
                'qty_change' => $this->integerOrNull($row->qty_change ?? null),
                'balance_after' => $this->integerOrNull($row->balance_after ?? null),
                'reason' => $this->shortText($row->reason ?? null, 60),
                'reference_type' => $this->vocabulary($row->reference_type ?? null, self::REFERENCE_TYPES),
                'reference_id' => $this->integerOrNull($row->reference_id ?? null),
                'created_at' => $row->created_at ?? null,
            ];
        }

        // A list rather than a map keyed on the column's own values: the view has to know whether a
        // type is one of ours before it may translate it, and a bare key carries no way to say so.
        usort($byType, static fn (array $left, array $right) => $right['movements'] <=> $left['movements']);

        // Read newest-first, so the last row of the sample is the oldest movement it reaches.
        $newest = $movements === [] ? null : $movements[0]['created_at'];
        $oldest = $movements === [] ? null : $movements[count($movements) - 1]['created_at'];

        return array_merge($base, [
            'state' => $movements === [] ? 'no_data' : 'ok',
            'note' => $movements === []
                ? 'The movement ledger is empty. Nothing has recorded a stock change on this deployment, so the four checks that read it have nothing to compare against — which is not the same as stock that reconciles.'
                : null,
            'remedy' => $movements === []
                ? 'Movements are written from the sale, receipt, return and adjustment paths. Place an order or adjust stock in Admin → Inventory and the ledger starts filling.'
                : null,
            'rows' => $movements,
            'truncated' => $rows->count() > self::MAX_MOVEMENTS,
            'examined' => count($movements),
            'oldest_at' => $this->shopStamp($oldest),
            'newest_at' => $this->shopStamp($newest),
            'by_type' => $byType,
            'unknown_types' => $unknown,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // The findings

    /**
     * @param  array<string, mixed>  $shop
     * @param  array<string, mixed>  $catalogue
     * @param  array<string, mixed>  $stock
     * @param  array<string, mixed>  $products
     * @param  array<string, mixed>  $ledger
     * @return array<int, array<string, mixed>>
     */
    private function findings(array $shop, array $catalogue, array $stock, array $products, array $ledger, Carbon $standingSince): array
    {
        $lines = $this->saleLines($ledger);
        $orders = $this->referencedOrders($ledger);
        $heads = $this->ledgerHeads($shop, $ledger);

        return [
            $this->negativeStock($catalogue, $stock),
            $this->sellableWithNoStock($stock),
            $this->variantSumMismatch($products),
            $this->negativeVariants($products),
            $this->doubleDeductions($ledger, $lines),
            $this->salesWithNoOrder($ledger, $orders),
            $this->balanceThatDoesNotAddUp($ledger),
            $this->driftFromTheLedger($ledger, $heads),
            $this->stockNeverReturned($shop, $standingSince),
            $this->warehouseOverAllocation($shop),
            $this->expiredBatches($shop),
            $this->reservations(),
        ];
    }

    /**
     * Stock below zero: the shop has sold what it did not have.
     *
     * The count is exact because idx_products_current_stock answers `< 0` without reading a row; the
     * rows under it are the worst of them, which is what the sample is ordered by.
     *
     * @param  array<string, mixed>  $catalogue
     * @param  array<string, mixed>  $stock
     * @return array<string, mixed>
     */
    private function negativeStock(array $catalogue, array $stock): array
    {
        $meaning = 'These products have been sold past zero. Every unit below zero is an order somebody placed that the warehouse cannot pick, and the storefront is still offering the product because a negative number passes every "greater than nothing" test in the catalogue.';
        $action = 'Count the shelf and correct each one through Admin → Inventory so the correction lands in the movement ledger with a reason. Correcting the column directly leaves no record of how much stock was written off.';

        $blocked = $this->blocked('products_with_negative_stock', 'critical', [$stock], $meaning, $action, 'catalogue');
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($stock['rows'] as $product) {
            if ($product['current_stock'] === null || $product['current_stock'] >= 0) {
                continue;
            }

            $rows[] = $this->row(
                productId: $product['id'],
                name: $product['name'],
                stock: $product['current_stock'],
                counted: null,
                units: abs($product['current_stock']),
                detail: 'Stock stands at ' . $product['current_stock'] . ', so ' . abs($product['current_stock'])
                    . ' ' . ($product['current_stock'] === -1 ? 'unit has' : 'units have') . ' been sold past zero.',
                at: $product['updated_at'],
            );
        }

        // The count comes from the index rather than from the rows: it is the one figure on this page
        // that can be exact without reading the catalogue, and a count of the sample would understate
        // it the moment more products are negative than this page lists.
        $exact = $catalogue['state'] === 'ok' && $catalogue['negative'] !== null;

        return $this->finding(
            key: 'products_with_negative_stock',
            severity: 'critical',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::PRODUCTS_SOURCE,
            index: $catalogue['index'],
            scope: 'catalogue',
            population: $stock,
            emptyNote: 'No product in the catalogue holds a negative stock figure.',
            count: $exact ? $catalogue['negative'] : count($rows),
            countExact: $exact,
            caveat: 'The post-payment order path deliberately allows overselling (app/Utils/module-helper.php, stock_policy allow_oversell): refusing an order after the gateway has taken the money would leave a customer paid with nothing, so a negative here can be the designed outcome of that trade rather than a fault.',
        );
    }

    /**
     * Offered for sale with nothing behind it.
     *
     * @param  array<string, mixed>  $stock
     * @return array<string, mixed>
     */
    private function sellableWithNoStock(array $stock): array
    {
        $meaning = 'The storefront is listing these and cannot ship them. Every one is an order that will be taken and then cancelled, or a customer who reaches checkout and is refused there.';
        $action = 'Restock them or take them out of the catalogue. If the shop deliberately sells on backorder, that is a decision worth making per product rather than leaving to whichever page happens to check stock.';

        $blocked = $this->blocked('sellable_products_with_no_stock', 'major', [$stock], $meaning, $action, 'catalogue');
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($stock['rows'] as $product) {
            if ($product['current_stock'] === null || $product['current_stock'] > 0) {
                continue;
            }
            if ($product['product_type']['value'] !== 'physical' || !$product['enabled'] || !$product['approved']) {
                continue;
            }

            $rows[] = $this->row(
                productId: $product['id'],
                name: $product['name'],
                stock: $product['current_stock'],
                counted: null,
                units: null,
                detail: 'Enabled, approved and physical, with stock at ' . $product['current_stock'] . '.',
                at: $product['updated_at'],
            );
        }

        return $this->finding(
            key: 'sellable_products_with_no_stock',
            severity: 'major',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::PRODUCTS_SOURCE,
            index: $stock['index'],
            scope: 'catalogue',
            population: $stock,
            emptyNote: 'Every product examined at or below zero stock is either not physical or not offered for sale.',
            caveat: 'Sellable is read as the storefront reads it — Product::scopeActive filters on product_type, status and request_status — so a product held back by the published column alone is not listed here.',
        );
    }

    /**
     * The variants add up to something the product does not agree with.
     *
     * @param  array<string, mixed>  $products
     * @return array<string, mixed>
     */
    private function variantSumMismatch(array $products): array
    {
        $meaning = 'A variant product keeps two figures: the quantity on each variant and one total on the product. The storefront sells against the total and the warehouse picks against the variant, so once they disagree one of the two is lying to somebody — usually the total, which is what the catalogue offers.';
        $action = 'Open each product and save it: the product form recomputes the total from the variants. Check the variant quantities are the ones you expect first, because the save takes them as the truth.';

        $blocked = $this->blocked('variant_quantities_that_do_not_sum_to_the_product', 'major', [$products], $meaning, $action, 'catalogue');
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($products['rows'] as $product) {
            if (!$product['variant_product'] || $product['current_stock'] === null) {
                continue;
            }

            $sum = $product['variation']['total'] ?? null;
            if ($sum === null) {
                continue;
            }

            $difference = $product['current_stock'] - $sum;
            if (abs($difference) <= self::UNIT_TOLERANCE) {
                continue;
            }

            $rows[] = $this->row(
                productId: $product['id'],
                name: $product['name'],
                stock: $product['current_stock'],
                counted: $sum,
                units: abs($difference),
                detail: 'Its ' . count($product['variation']['rows']) . ' '
                    . (count($product['variation']['rows']) === 1 ? 'variant holds' : 'variants hold') . ' ' . $sum
                    . ' units between them; the product says ' . $product['current_stock']
                    . ', a difference of ' . $difference . '.',
                at: $product['updated_at'],
            );
        }

        return $this->finding(
            key: 'variant_quantities_that_do_not_sum_to_the_product',
            severity: 'major',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::PRODUCTS_SOURCE . '.variation',
            index: $products['index'],
            scope: 'catalogue',
            population: $products,
            emptyNote: $products['variants'] === 0
                ? 'No product examined is a variant product, so there was no pair of figures to disagree.'
                : 'Every variant product examined sums to its own total.',
            caveat: $products['unreadable_variation'] > 0
                ? $products['unreadable_variation'] . ' of the variant products examined carry a variation column that is not readable as a list of quantities. They are excluded rather than compared against a partial sum, which would report a mismatch this page invented.'
                : null,
        );
    }

    /**
     * A variant below zero, hidden behind a product total that is not.
     *
     * @param  array<string, mixed>  $products
     * @return array<string, mixed>
     */
    private function negativeVariants(array $products): array
    {
        $meaning = 'One variant is oversold while the product total stays positive, so nothing on the catalogue side looks wrong. The size that is gone keeps selling until somebody tries to pick it.';
        $action = 'Correct the variant quantity on the product, then save so the total is recomputed. A variant that went negative usually means the same variant was sold twice on one order edit.';

        $blocked = $this->blocked('variants_with_negative_quantity', 'critical', [$products], $meaning, $action, 'catalogue');
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($products['rows'] as $product) {
            if (!$product['variant_product'] || $product['variation']['negatives'] === 0) {
                continue;
            }

            $negatives = array_values(array_filter(
                $product['variation']['rows'],
                static fn (array $variant) => $variant['qty'] < 0,
            ));

            $units = 0;
            $named = [];
            foreach ($negatives as $variant) {
                $units += abs($variant['qty']);
                $named[] = ($variant['type'] ?? '?') . ' ' . $variant['qty'];
            }

            $rows[] = $this->row(
                productId: $product['id'],
                name: $product['name'],
                stock: $product['current_stock'],
                counted: $product['variation']['total'],
                units: $units,
                detail: 'Below zero on ' . count($negatives) . ' '
                    . (count($negatives) === 1 ? 'variant' : 'variants') . ': ' . implode(', ', array_slice($named, 0, 6)) . '.',
                at: $product['updated_at'],
            );
        }

        return $this->finding(
            key: 'variants_with_negative_quantity',
            severity: 'critical',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::PRODUCTS_SOURCE . '.variation',
            index: $products['index'],
            scope: 'catalogue',
            population: $products,
            emptyNote: $products['variants'] === 0
                ? 'No product examined is a variant product.'
                : 'No variant of any product examined holds a negative quantity.',
        );
    }

    /**
     * The same order line deducted twice.
     *
     * `stock_movements` has no unique key on (type, reference_type, reference_id, product_id), so a
     * retried callback or a double status change can write the deduction twice. Where the reference
     * points at an order the duplicate is CONFIRMED against that order's own lines rather than
     * assumed: the sale writer records the ORDER id, not the order-line id, so an order holding two
     * lines of the same product writes two movements entirely legitimately.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $lines
     * @return array<string, mixed>
     */
    private function doubleDeductions(array $ledger, array $lines): array
    {
        $meaning = 'Stock was taken twice for one order line. The customer was charged once, the shelf lost twice, and the difference stays in the catalogue as stock that does not exist until somebody counts it.';
        $action = 'Compare each order against its lines, then correct the product through Admin → Inventory so the correction is itself recorded. The duplicate movement rows are history and should be left as they are.';

        $blocked = $this->blocked('stock_deducted_twice_for_one_order_line', 'critical', [$ledger], $meaning, $action, 'ledger');
        if ($blocked !== null) {
            return $blocked;
        }

        $groups = [];
        foreach ($ledger['rows'] as $movement) {
            if ($movement['type']['value'] !== 'sale' || $movement['product_id'] === null) {
                continue;
            }
            if ($movement['reference_type']['value'] === '' || $movement['reference_id'] === null) {
                continue;
            }

            $key = $movement['reference_type']['value'] . '|' . $movement['reference_id'] . '|' . $movement['product_id'];
            $groups[$key][] = $movement;
        }

        $rows = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $first = $group[0];
            $reference = $first['reference_type']['value'];
            $lineKey = $first['reference_id'] . '|' . $first['product_id'];
            $expected = in_array($reference, self::ORDER_REFERENCES, true) && $lines['state'] === 'ok'
                ? ($lines['by_line'][$lineKey]['lines'] ?? 0)
                : null;

            // A confirmed legitimate pair is dropped, not listed with a caveat: two lines of the same
            // product on one order are ordinary, and listing them would bury the real duplicates.
            if ($expected !== null && count($group) <= $expected) {
                continue;
            }

            $taken = 0;
            foreach ($group as $movement) {
                $taken += abs($movement['qty_change'] ?? 0);
            }

            $units = $expected !== null && $expected > 0
                ? (int) round($taken * (count($group) - $expected) / count($group))
                : null;

            $rows[] = $this->row(
                productId: $first['product_id'],
                name: null,
                stock: null,
                counted: count($group),
                units: $units,
                detail: count($group) . ' sale movements carry ' . $this->reference($first) . ', taking '
                    . $taken . ' units in total'
                    . ($expected === null
                        ? '. The order behind it could not be read, so whether that is one deduction too many is not confirmed here.'
                        : ($expected === 0
                            ? '. That order holds no line for this product at all.'
                            : ', and the order holds ' . $expected . ' ' . ($expected === 1 ? 'line' : 'lines') . ' for this product.')),
                at: $first['created_at'] === null ? null : $this->shopStamp($first['created_at']),
            );
        }

        return $this->finding(
            key: 'stock_deducted_twice_for_one_order_line',
            severity: 'critical',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::MOVEMENTS_SOURCE . ' confirmed against ' . self::LINES_SOURCE,
            index: $ledger['index'] . ' then idx_order_details_order_id_delivery_status (order_id, delivery_status)',
            scope: 'ledger',
            population: $ledger,
            emptyNote: 'No sale in the movements examined was recorded more than its order has lines for.',
            caveat: $lines['state'] === 'ok'
                ? 'The sale writer records the order id rather than the order-line id, so a duplicate is only a duplicate once the order has fewer lines for that product than it has movements. That comparison is what this check does; it is not available for a reference type that does not point at an order.'
                : 'The order lines could not be read (' . ($lines['note'] ?? 'no reason given') . '), so nothing here is confirmed: an order legitimately holding two lines of the same product writes two movements, and this list cannot currently tell the two apart.',
        );
    }

    /**
     * A sale that took stock with nothing to trace it back to.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $orders
     * @return array<string, mixed>
     */
    private function salesWithNoOrder(array $ledger, array $orders): array
    {
        $meaning = 'Stock left the shelf as a sale and the ledger cannot say for which order. Either the reference was never written, or the order it names is gone — and in both cases the deduction cannot be reversed by anything that works from orders.';
        $action = 'Match each movement against the orders placed around its timestamp. A missing reference on a POS sale is usually the till; a reference pointing at nothing usually means the order was deleted rather than cancelled.';

        $blocked = $this->blocked('sale_movements_with_no_order_behind_them', 'major', [$ledger], $meaning, $action, 'ledger');
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($ledger['rows'] as $movement) {
            if ($movement['type']['value'] !== 'sale') {
                continue;
            }

            $reference = $movement['reference_type']['value'];
            $missing = $reference === '' || $movement['reference_id'] === null;
            $dangling = !$missing
                && in_array($reference, self::ORDER_REFERENCES, true)
                && $orders['state'] === 'ok'
                && !in_array($movement['reference_id'], $orders['ids'], true);

            if (!$missing && !$dangling) {
                continue;
            }

            $rows[] = $this->row(
                productId: $movement['product_id'],
                name: null,
                stock: null,
                counted: null,
                units: abs($movement['qty_change'] ?? 0),
                detail: $missing
                    ? 'Movement ' . $movement['id'] . ' took ' . abs($movement['qty_change'] ?? 0) . ' units as a sale and carries no reference to an order.'
                    : 'Movement ' . $movement['id'] . ' took ' . abs($movement['qty_change'] ?? 0) . ' units for ' . $this->reference($movement) . ', and no such order exists.',
                at: $movement['created_at'] === null ? null : $this->shopStamp($movement['created_at']),
            );
        }

        return $this->finding(
            key: 'sale_movements_with_no_order_behind_them',
            severity: 'major',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::MOVEMENTS_SOURCE . ' left against ' . self::ORDERS_SOURCE,
            index: $ledger['index'] . ' then PRIMARY (id) on orders',
            scope: 'ledger',
            population: $ledger,
            emptyNote: 'Every sale in the movements examined names an order that exists.',
            caveat: $orders['state'] === 'ok'
                ? ($orders['truncated']
                    ? 'More orders are referenced by the movements examined than this page looks up, so a movement whose order was not among the ' . self::MAX_PRODUCT_LOOKUP . ' checked is not listed as dangling.'
                    : null)
                : 'Only movements with no reference at all are listed: the orders they name could not be read (' . ($orders['note'] ?? 'no reason given') . '), so a reference pointing at a deleted order cannot be told from one pointing at a live one.',
        );
    }

    /**
     * The ledger contradicts itself: a balance that does not follow the movement before it.
     *
     * Consecutive pairs are taken WITHIN the sample, which is sound because the sample is a
     * contiguous run from the top of the primary key: no movement can exist between two sampled rows
     * of the same product without also being in the sample.
     *
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function balanceThatDoesNotAddUp(array $ledger): array
    {
        $meaning = 'Each movement records the balance it left behind. Where one balance is not the previous balance plus the change, something moved the stock between the two movements without recording anything — an admin edit, a restock, a warehouse transfer, or a write that was lost.';
        $action = 'Read the two movements either side of each gap and find what happened between their timestamps. The size of the jump is how much stock changed hands with no record.';

        $blocked = $this->blocked('movements_whose_running_balance_does_not_add_up', 'minor', [$ledger], $meaning, $action, 'ledger');
        if ($blocked !== null) {
            return $blocked;
        }

        $previous = [];
        $rows = [];

        // Oldest first within the sample, so each product's movements are walked in the order they
        // were written rather than backwards, which would invert every subtraction.
        foreach (array_reverse($ledger['rows']) as $movement) {
            $product = $movement['product_id'];
            if ($product === null || $movement['balance_after'] === null) {
                continue;
            }

            $before = $previous[$product] ?? null;
            $previous[$product] = $movement;

            if ($before === null || $before['balance_after'] === null || $movement['qty_change'] === null) {
                continue;
            }

            $expected = $before['balance_after'] + $movement['qty_change'];
            if ($expected === $movement['balance_after']) {
                continue;
            }

            $rows[] = $this->row(
                productId: $product,
                name: null,
                stock: null,
                counted: $movement['balance_after'],
                units: abs($movement['balance_after'] - $expected),
                detail: 'Movement ' . $movement['id'] . ' (' . $this->signed($movement['qty_change']) . ') followed movement '
                    . $before['id'] . ', which left ' . $before['balance_after'] . '. The balance should have been '
                    . $expected . ' and was recorded as ' . $movement['balance_after'] . '.',
                at: $movement['created_at'] === null ? null : $this->shopStamp($movement['created_at']),
            );
        }

        return $this->finding(
            key: 'movements_whose_running_balance_does_not_add_up',
            severity: 'minor',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::MOVEMENTS_SOURCE,
            index: $ledger['index'],
            scope: 'ledger',
            population: $ledger,
            emptyNote: 'Every pair of consecutive movements examined follows on from the one before it.',
            caveat: 'Three stock paths in this build change a product without writing a movement: the restock in OrderManager::getStockUpdateOnOrderStatusChange, the floor-at-zero clamp beside it, and every warehouse transfer. A gap here is at least as likely to be one of those as a lost write.',
        );
    }

    /**
     * Live stock has drifted from the last balance the ledger recorded.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $heads
     * @return array<string, mixed>
     */
    private function driftFromTheLedger(array $ledger, array $heads): array
    {
        $meaning = 'The product carries one number and its last movement carries another. Everything that reads the ledger — a stock report, an audit, a reorder decision — is working from a figure the shop itself no longer holds.';
        $action = 'Treat the counted shelf as the truth, correct the product through Admin → Inventory so the correction is recorded, and then find which of the unrecorded paths moved it.';

        $blocked = $this->blocked('live_stock_that_disagrees_with_the_ledger', 'minor', [$ledger, $heads], $meaning, $action, 'ledger');
        if ($blocked !== null) {
            return $blocked;
        }

        $rows = [];
        foreach ($heads['rows'] as $head) {
            if ($head['balance_after'] === null || $head['current_stock'] === null) {
                continue;
            }
            if ($head['balance_after'] === $head['current_stock']) {
                continue;
            }

            $rows[] = $this->row(
                productId: $head['product_id'],
                name: $head['name'],
                stock: $head['current_stock'],
                counted: $head['balance_after'],
                units: abs($head['current_stock'] - $head['balance_after']),
                detail: 'The product holds ' . $head['current_stock'] . '; movement ' . $head['movement_id']
                    . ' left ' . $head['balance_after'] . ' behind it, a drift of '
                    . ($head['current_stock'] - $head['balance_after']) . ' units since then.',
                at: $head['at'],
            );
        }

        return $this->finding(
            key: 'live_stock_that_disagrees_with_the_ledger',
            severity: 'minor',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: self::MOVEMENTS_SOURCE . ' against ' . self::PRODUCTS_SOURCE,
            index: $ledger['index'] . ' then PRIMARY (id) on products',
            scope: 'ledger',
            population: $ledger,
            emptyNote: 'Every product whose last movement was examined still holds the balance that movement left.',
            caveat: 'Compared for the ' . count($heads['rows']) . ' products whose most recent movement is inside the sample, and only for movements that recorded a balance at all. The same three unrecorded paths as the check above apply: a drift is a missing movement at least as often as it is missing stock.',
        );
    }

    /**
     * Cancelled, returned or failed, and the stock was never given back.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function stockNeverReturned(array $shop, Carbon $standingSince): array
    {
        $meaning = 'An order that is cancelled, returned or failed is supposed to put its stock back, and the flag on each line records whether that happened. These lines still say the stock is out, so the shelf is holding units the catalogue does not know it has — the opposite of overselling, and just as wrong.';
        $action = 'Re-run the status change on each order, or correct the products by hand and clear the flag. The restock is guarded by that same flag, so a line stuck at 1 will never restock itself.';

        $base = [
            'source' => self::ORDERS_SOURCE . ' then ' . self::LINES_SOURCE,
            'index' => 'idx_orders_status_created_at (order_status, created_at) then idx_order_details_order_id_delivery_status (order_id, delivery_status)',
        ];

        if ($shop['state'] !== 'ok') {
            return $this->finding(
                key: 'cancelled_orders_whose_stock_was_never_returned',
                severity: 'major',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: $base['source'],
                index: $base['index'],
                scope: 'standing',
                population: null,
                emptyNote: null,
                state: $shop['state'],
                blockedByConnection: true,
            );
        }

        try {
            $connection = $this->connection();
            $bound = $this->shopBound($standingSince);
            $orders = [];
            $truncated = false;

            // One status at a time: each read is `order_status = ? AND created_at >= ?`, which is
            // exactly the two columns of idx_orders_status_created_at, so it is an ordered range that
            // stops at the limit. A whereIn over the three would sort the union instead.
            foreach (self::RESTOCK_STATUSES as $status) {
                $rows = $connection->table('orders')
                    ->where('order_status', $status)
                    ->where('created_at', '>=', $bound)
                    ->orderByDesc('created_at')
                    ->limit(self::MAX_ORDERS_PER_STATUS + 1)
                    ->get(['id', 'order_status', 'created_at', 'updated_at']);

                $truncated = $truncated || $rows->count() > self::MAX_ORDERS_PER_STATUS;

                foreach ($rows->take(self::MAX_ORDERS_PER_STATUS) as $row) {
                    $orders[(int) $row->id] = [
                        'id' => (int) $row->id,
                        'status' => $status,
                        'updated_at' => $this->shopStamp($row->updated_at ?? null),
                    ];
                }
            }

            $lines = [];
            if ($orders !== []) {
                $lines = $connection->table('order_details')
                    ->whereIn('order_id', array_keys($orders))
                    ->where('is_stock_decreased', 1)
                    ->limit(self::MAX_ORDER_LINES + 1)
                    ->get(['id', 'order_id', 'product_id', 'qty'])
                    ->all();
            }
        } catch (\Throwable $exception) {
            return $this->finding(
                key: 'cancelled_orders_whose_stock_was_never_returned',
                severity: 'major',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: $base['source'],
                index: $base['index'],
                scope: 'standing',
                population: null,
                emptyNote: null,
                state: 'failed',
                note: $this->failureNote($exception),
                remedy: 'The orders tables are part of the shop schema. Import installation/backup/database.sql, then run `php artisan migrate`.',
            );
        }

        $rows = [];
        foreach (array_slice($lines, 0, self::MAX_ORDER_LINES) as $line) {
            $order = $orders[(int) $line->order_id] ?? null;
            if ($order === null) {
                continue;
            }

            $quantity = $this->integerOrNull($line->qty ?? null);
            $rows[] = $this->row(
                productId: $this->integerOrNull($line->product_id ?? null),
                name: null,
                stock: null,
                counted: null,
                units: $quantity,
                detail: 'Order ' . $order['id'] . ' is ' . $order['status'] . ' and its line ' . (int) $line->id
                    . ' still records the stock as taken'
                    . ($quantity === null ? '.' : ' (' . $quantity . ' units).'),
                at: $order['updated_at'],
            );
        }

        return $this->finding(
            key: 'cancelled_orders_whose_stock_was_never_returned',
            severity: 'major',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: $base['source'],
            index: $base['index'],
            scope: 'standing',
            population: null,
            emptyNote: $orders === []
                ? 'No order was cancelled, returned or failed in the last ' . self::STANDING_LOOKBACK_DAYS . ' days.'
                : 'Every line of every cancelled, returned or failed order in the last ' . self::STANDING_LOOKBACK_DAYS . ' days has had its stock returned.',
            examined: count($orders),
            truncated: $truncated || count($lines) > self::MAX_ORDER_LINES,
            caveat: 'Read over orders CREATED in the last ' . self::STANDING_LOOKBACK_DAYS
                . ' days, because created_at is the indexed column and the moment of cancellation is not. An order placed before that window and cancelled inside it is not examined.',
        );
    }

    /**
     * More stock placed in warehouses than the product has.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function warehouseOverAllocation(array $shop): array
    {
        $meaning = 'The warehouses between them hold more of this product than the product says exists. One of the two is wrong, and picking works from the warehouse while selling works from the product — so the shop will keep selling to a total it cannot fill from the shelves it has.';
        $action = 'Recount the warehouse rows for each product and correct the allocation, or correct the product total if the warehouses are right. Warehouse moves write no movement record, so nothing else will show you which one drifted.';
        $source = self::WAREHOUSE_SOURCE . ' against ' . self::PRODUCTS_SOURCE;
        $index = 'ws_product_idx (product_id), grouped along the index so the read stops after ' . self::MAX_GROUPS . ' products';

        $unavailable = $this->tableCheck('warehouse_allocation_above_the_products_stock', 'major', $shop, 'warehouse_stock', $meaning, $action, $source, $index,
            'This deployment has no warehouse_stock table, so stock is not allocated to warehouses at all and there is nothing to over-allocate.',
            'Run `php artisan migrate` — the table is created by database/migrations/2026_08_09_700008_create_warehouse_stock_table.php.');
        if ($unavailable !== null) {
            return $unavailable;
        }

        try {
            $connection = $this->connection();
            $groups = $connection->table('warehouse_stock')
                ->groupBy('product_id')
                ->limit(self::MAX_GROUPS + 1)
                ->get(['product_id', $connection->raw('SUM(quantity) AS allocated'), $connection->raw('COUNT(*) AS warehouses')]);

            $ids = [];
            foreach ($groups->take(self::MAX_GROUPS) as $group) {
                $id = $this->integerOrNull($group->product_id ?? null);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }

            $stock = $this->productStock($ids);
        } catch (\Throwable $exception) {
            return $this->finding(
                key: 'warehouse_allocation_above_the_products_stock',
                severity: 'major',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: $source,
                index: $index,
                scope: 'catalogue',
                population: null,
                emptyNote: null,
                state: 'failed',
                note: $this->failureNote($exception),
            );
        }

        $rows = [];
        foreach ($groups->take(self::MAX_GROUPS) as $group) {
            $id = $this->integerOrNull($group->product_id ?? null);
            $allocated = $this->integerOrNull($group->allocated ?? null);
            $product = $id === null ? null : ($stock['by_id'][$id] ?? null);

            if ($id === null || $allocated === null || $product === null || $product['current_stock'] === null) {
                continue;
            }
            if ($allocated <= $product['current_stock']) {
                continue;
            }

            $rows[] = $this->row(
                productId: $id,
                name: $product['name'],
                stock: $product['current_stock'],
                counted: $allocated,
                units: $allocated - $product['current_stock'],
                detail: (int) $group->warehouses . ' ' . ((int) $group->warehouses === 1 ? 'warehouse holds' : 'warehouses hold')
                    . ' ' . $allocated . ' units between them; the product says ' . $product['current_stock'] . '.',
                at: null,
            );
        }

        return $this->finding(
            key: 'warehouse_allocation_above_the_products_stock',
            severity: 'major',
            rows: $rows,
            meaning: $meaning,
            action: $action,
            source: $source,
            index: $index,
            scope: 'catalogue',
            population: null,
            emptyNote: $groups->isEmpty()
                ? 'No product has any stock allocated to a warehouse, so nothing can be allocated past what exists.'
                : 'No product examined has more stock in its warehouses than the product itself records.',
            examined: $groups->take(self::MAX_GROUPS)->count(),
            truncated: $groups->count() > self::MAX_GROUPS,
            // Only a failed lookup is a caveat. An empty one means there was nothing to look up,
            // which the empty note above already says in the right words.
            caveat: $stock['state'] === 'failed'
                ? 'The products behind these allocations could not be read (' . ($stock['note'] ?? 'no reason given') . '), so nothing could be compared.'
                : null,
        );
    }

    /**
     * Batches past their expiry date that are still counted as sellable stock.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    private function expiredBatches(array $shop): array
    {
        $meaning = 'These batches expired and are still marked active with quantity on them, so their units are still part of what the shop offers. On a pharmacy that is stock that must not be picked, and it is being counted as stock that can.';
        $action = 'Mark each batch expired and write the quantity off through Admin → Inventory so the write-off is recorded against the product. Quarantine the physical stock first.';
        $source = self::BATCH_SOURCE;
        $index = 'pb_status_expiry_idx (status, expiry_date)';

        $unavailable = $this->tableCheck('expired_batches_still_counted_as_sellable', 'major', $shop, 'product_batches', $meaning, $action, $source, $index,
            'This deployment has no product_batches table, so stock is not tracked by batch or expiry at all.',
            'Run `php artisan migrate` — the table is created by database/migrations/2026_08_09_700006_create_product_batches_table.php.');
        if ($unavailable !== null) {
            return $unavailable;
        }

        try {
            // status equality then an expiry range, in that order, is exactly pb_status_expiry_idx —
            // and the ORDER BY is the index's own order, so the oldest expiries come off the front of
            // it without a sort.
            $rows = $this->connection()->table('product_batches')
                ->where('status', 'active')
                ->where('quantity', '>', 0)
                ->where('expiry_date', '<', $this->shopDate())
                ->orderBy('expiry_date')
                ->limit(self::MAX_BATCH_ROWS + 1)
                ->get(['id', 'product_id', 'batch_number', 'expiry_date', 'quantity']);
        } catch (\Throwable $exception) {
            return $this->finding(
                key: 'expired_batches_still_counted_as_sellable',
                severity: 'major',
                rows: [],
                meaning: $meaning,
                action: $action,
                source: $source,
                index: $index,
                scope: 'catalogue',
                population: null,
                emptyNote: null,
                state: 'failed',
                note: $this->failureNote($exception),
            );
        }

        $presented = [];
        foreach ($rows->take(self::MAX_BATCH_ROWS) as $row) {
            $quantity = $this->integerOrNull($row->quantity ?? null);
            $expiry = $this->shortText($row->expiry_date ?? null, 20);

            $presented[] = $this->row(
                productId: $this->integerOrNull($row->product_id ?? null),
                name: null,
                stock: null,
                counted: null,
                units: $quantity,
                detail: 'Batch ' . ($this->shortText($row->batch_number ?? null, 40) ?? (int) $row->id)
                    . ' expired' . ($expiry === null ? '' : ' on ' . $expiry) . ' and still carries '
                    . ($quantity ?? 0) . ' active units.',
                at: null,
            );
        }

        return $this->finding(
            key: 'expired_batches_still_counted_as_sellable',
            severity: 'major',
            rows: $presented,
            meaning: $meaning,
            action: $action,
            source: $source,
            index: $index,
            scope: 'catalogue',
            population: null,
            emptyNote: 'No active batch has passed its expiry date with stock still on it.',
            truncated: $rows->count() > self::MAX_BATCH_ROWS,
            caveat: 'Compared against ' . $this->shopDate() . ' in the shop\'s own timezone, because expiry_date is a date the shop wrote and a date has no offset to convert.',
        );
    }

    /**
     * Stuck reservations, which this build cannot have.
     *
     * Not an empty table — no table. Nothing in this codebase reserves stock: a cart holds a quantity
     * and no hold on it, and stock is taken at order generation. An empty list here would read as
     * "no reservation is stuck", which is a finding, and nothing looked.
     *
     * @return array<string, mixed>
     */
    private function reservations(): array
    {
        return $this->finding(
            key: 'stuck_stock_reservations',
            severity: 'major',
            rows: [],
            meaning: 'A reservation holds stock for a shopper between the cart and the payment, and a stuck one holds it forever. This build has no reservation of any kind: carts hold a quantity with no expiry and no claim on stock, and stock is taken at order generation — so there is nothing that can be stuck, and equally nothing preventing two shoppers from buying the last unit at once.',
            action: 'Nothing to do about stuck reservations, because none can exist. The gap this leaves is oversell at checkout, which the negative-stock check above is the only evidence of.',
            source: 'no table',
            index: '',
            scope: 'unsupported',
            population: null,
            emptyNote: null,
            state: 'not_supported',
            note: 'There is no stock-reservation system in this codebase. carts carries quantity, is_checked and cart_group_id and no reserved_until, expires_at or reserved_qty; the only reserved column in the schema is jobs.reserved_at, which belongs to the queue.',
            remedy: 'A reservation needs a stock_reservations table (product_id, variant, qty, cart_group_id, order_id, expires_at, released_at, status) written in app/Utils/CartManager.php::add_to_cart and released on order generation or expiry. Only then does "stuck" become a question with an answer.',
        );
    }

    // ---------------------------------------------------------------------------------------------
    // The bounded lookups the ledger checks depend on

    /**
     * How many lines each referenced order holds for each product.
     *
     * Rides idx_order_details_order_id_delivery_status, whose leading column is order_id, so the IN
     * list is a bounded set of index lookups rather than a scan of the line table. The grouping does
     * build a temporary table, and it is worth being precise about what goes into it: only the lines
     * of the orders in the IN list, which is itself capped, and the read stops at its own limit on
     * top of that.
     *
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function saleLines(array $ledger): array
    {
        $base = ['source' => self::LINES_SOURCE, 'by_line' => [], 'truncated' => false];

        if ($ledger['state'] !== 'ok') {
            return array_merge($base, ['state' => $ledger['state'], 'note' => $ledger['note'] ?? null]);
        }

        $ids = [];
        foreach ($ledger['rows'] as $movement) {
            if ($movement['type']['value'] !== 'sale' || $movement['reference_id'] === null) {
                continue;
            }
            if (!in_array($movement['reference_type']['value'], self::ORDER_REFERENCES, true)) {
                continue;
            }

            $ids[$movement['reference_id']] = true;
        }

        $ids = array_slice(array_keys($ids), 0, self::MAX_PRODUCT_LOOKUP);
        if ($ids === []) {
            return array_merge($base, ['state' => 'no_data', 'note' => 'No sale in the movements examined references an order.']);
        }

        try {
            $connection = $this->connection();
            $rows = $connection->table('order_details')
                ->whereIn('order_id', $ids)
                ->groupBy('order_id', 'product_id')
                ->limit(self::MAX_ORDER_LINES + 1)
                ->get(['order_id', 'product_id', $connection->raw('COUNT(*) AS lines_count'), $connection->raw('SUM(qty) AS units')]);
        } catch (\Throwable $exception) {
            return array_merge($base, ['state' => 'failed', 'note' => $this->failureNote($exception)]);
        }

        $byLine = [];
        foreach ($rows->take(self::MAX_ORDER_LINES) as $row) {
            $byLine[(int) $row->order_id . '|' . (int) $row->product_id] = [
                'lines' => (int) $row->lines_count,
                'units' => $this->integerOrNull($row->units ?? null),
            ];
        }

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'by_line' => $byLine,
            'truncated' => $rows->count() > self::MAX_ORDER_LINES,
        ]);
    }

    /**
     * Which of the orders the movements name actually exist.
     *
     * A primary-key read of a bounded id list — the cheapest lookup in the schema.
     *
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function referencedOrders(array $ledger): array
    {
        $base = ['source' => self::ORDERS_SOURCE, 'ids' => [], 'truncated' => false];

        if ($ledger['state'] !== 'ok') {
            return array_merge($base, ['state' => $ledger['state'], 'note' => $ledger['note'] ?? null]);
        }

        $wanted = [];
        foreach ($ledger['rows'] as $movement) {
            if ($movement['type']['value'] !== 'sale' || $movement['reference_id'] === null) {
                continue;
            }
            if (!in_array($movement['reference_type']['value'], self::ORDER_REFERENCES, true)) {
                continue;
            }

            $wanted[$movement['reference_id']] = true;
        }

        $wanted = array_keys($wanted);
        $truncated = count($wanted) > self::MAX_PRODUCT_LOOKUP;
        $wanted = array_slice($wanted, 0, self::MAX_PRODUCT_LOOKUP);

        if ($wanted === []) {
            return array_merge($base, ['state' => 'no_data', 'note' => 'No sale in the movements examined references an order.']);
        }

        try {
            $rows = $this->connection()->table('orders')
                ->whereIn('id', $wanted)
                ->limit(self::MAX_PRODUCT_LOOKUP + 1)
                ->get(['id']);
        } catch (\Throwable $exception) {
            return array_merge($base, ['state' => 'failed', 'note' => $this->failureNote($exception)]);
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        return array_merge($base, ['state' => 'ok', 'note' => null, 'ids' => $ids, 'truncated' => $truncated]);
    }

    /**
     * The newest movement per product in the sample, beside that product's live stock.
     *
     * The sample is a contiguous run from the top of the primary key, so the first movement seen for
     * a product while walking it newest-first IS that product's most recent movement.
     *
     * @param  array<string, mixed>  $shop
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function ledgerHeads(array $shop, array $ledger): array
    {
        $base = ['source' => self::PRODUCTS_SOURCE, 'index' => 'PRIMARY (id)', 'empty_means_clean' => false, 'rows' => [], 'truncated' => false];

        if ($shop['state'] !== 'ok' || $ledger['state'] !== 'ok') {
            return array_merge($base, [
                'state' => $ledger['state'] === 'ok' ? $shop['state'] : $ledger['state'],
                'note' => $ledger['state'] === 'ok' ? null : ($ledger['note'] ?? null),
            ]);
        }

        $heads = [];
        foreach ($ledger['rows'] as $movement) {
            $product = $movement['product_id'];
            if ($product === null || isset($heads[$product])) {
                continue;
            }

            $heads[$product] = $movement;
        }

        $truncated = count($heads) > self::MAX_PRODUCT_LOOKUP;
        $heads = array_slice($heads, 0, self::MAX_PRODUCT_LOOKUP, true);

        if ($heads === []) {
            return array_merge($base, ['state' => 'no_data', 'note' => 'No movement examined names a product.']);
        }

        $stock = $this->productStock(array_keys($heads));
        if ($stock['state'] !== 'ok') {
            return array_merge($base, ['state' => $stock['state'], 'note' => $stock['note'] ?? null]);
        }

        $rows = [];
        foreach ($heads as $productId => $movement) {
            $product = $stock['by_id'][$productId] ?? null;

            $rows[] = [
                'product_id' => $productId,
                'name' => $product['name'] ?? null,
                // Null when the product is gone: a movement for a deleted product is a fact about the
                // ledger, not a stock figure of zero.
                'current_stock' => $product['current_stock'] ?? null,
                'movement_id' => $movement['id'],
                'balance_after' => $movement['balance_after'],
                'at' => $movement['created_at'] === null ? null : $this->shopStamp($movement['created_at']),
            ];
        }

        return array_merge($base, ['state' => 'ok', 'note' => null, 'rows' => $rows, 'truncated' => $truncated]);
    }

    /**
     * Live stock for a bounded list of product ids, off the primary key.
     *
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    private function productStock(array $ids): array
    {
        $ids = array_slice(array_values(array_unique($ids)), 0, self::MAX_PRODUCT_LOOKUP);

        if ($ids === []) {
            return ['state' => 'no_data', 'note' => 'No product to look up.', 'by_id' => []];
        }

        try {
            $rows = $this->connection()->table('products')
                ->whereIn('id', $ids)
                ->limit(self::MAX_PRODUCT_LOOKUP + 1)
                ->get(['id', 'name', 'current_stock']);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'by_id' => []];
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = [
                'name' => $this->shortText($row->name ?? null, 80),
                'current_stock' => $this->integerOrNull($row->current_stock ?? null),
            ];
        }

        return ['state' => 'ok', 'note' => null, 'by_id' => $byId];
    }

    /**
     * Product names for the rows that were built from a table holding only ids.
     *
     * One bounded primary-key read for the whole page rather than one per finding. A name that
     * cannot be read stays null and the view prints the id — a product with no name is not the
     * same as a product this page failed to name.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function named(array $findings): array
    {
        $wanted = [];
        foreach ($findings as $finding) {
            foreach ($finding['rows'] as $row) {
                if ($row['product_id'] !== null && $row['product'] === null) {
                    $wanted[$row['product_id']] = true;
                }
            }
        }

        if ($wanted === []) {
            return $findings;
        }

        $stock = $this->productStock(array_keys($wanted));
        if ($stock['state'] !== 'ok') {
            return $findings;
        }

        foreach ($findings as $index => $finding) {
            foreach ($finding['rows'] as $position => $row) {
                if ($row['product_id'] === null || $row['product'] !== null) {
                    continue;
                }

                $findings[$index]['rows'][$position]['product'] = $stock['by_id'][$row['product_id']]['name'] ?? null;
            }
        }

        return $findings;
    }

    // ---------------------------------------------------------------------------------------------
    // Building one finding

    /**
     * The shared shape every check returns, whatever happened to it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $population
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
        string $scope,
        ?array $population,
        ?string $emptyNote,
        ?string $caveat = null,
        string $state = 'ok',
        ?string $note = null,
        ?string $remedy = null,
        ?int $count = null,
        ?bool $countExact = null,
        ?int $examined = null,
        bool $truncated = false,
        bool $blockedByConnection = false,
    ): array {
        $sampled = $population !== null && ($population['truncated'] ?? false);
        $counted = $state === 'ok' ? ($count ?? count($rows)) : null;

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
            'scope' => in_array($scope, self::SCOPES, true) ? $scope : 'catalogue',
            'count' => $counted,
            // False when the read stopped at its limit: the number is then a floor, not a total.
            'count_exact' => $state === 'ok' && ($countExact ?? (!$sampled && !$truncated)),
            'units' => $state === 'ok' ? $this->sumUnits($rows) : null,
            'units_known' => $state === 'ok' && $this->unitsKnown($rows),
            'rows' => array_slice($rows, 0, self::MAX_FINDING_ROWS),
            'truncated' => $truncated || count($rows) > self::MAX_FINDING_ROWS,
            'limit' => self::MAX_FINDING_ROWS,
            'examined' => $examined ?? ($population === null ? null : ($population['examined'] ?? null)),
            'population_truncated' => $sampled,
            'blocked_by_connection' => $blockedByConnection,
        ];
    }

    /**
     * The check could not run because something it depends on could not be read.
     *
     * Returns null when everything it needs is present. The reason is left null when the connection
     * banner already carries it: one fault said twelve times reads as twelve faults.
     *
     * @param  array<int, array<string, mixed>>  $dependencies
     * @return array<string, mixed>|null
     */
    private function blocked(string $key, string $severity, array $dependencies, string $meaning, string $action, string $scope): ?array
    {
        foreach ($dependencies as $dependency) {
            if (($dependency['state'] ?? 'ok') === 'ok') {
                continue;
            }

            // A readable population that happens to be empty is not a blocked check: it is a check
            // that ran and found nothing, which is the good news this page exists to be able to give.
            // Only the populations that say so, though — an empty movement ledger recorded nothing
            // rather than found nothing, and drawing that as a clean check is the one mistake this
            // whole section exists to avoid.
            if ($dependency['state'] === 'no_data' && ($dependency['empty_means_clean'] ?? false)) {
                return $this->finding(
                    key: $key,
                    severity: $severity,
                    rows: [],
                    meaning: $meaning,
                    action: $action,
                    source: $dependency['source'] ?? '',
                    index: $dependency['index'] ?? '',
                    scope: $scope,
                    population: $dependency,
                    emptyNote: $dependency['note'] ?? null,
                );
            }

            return $this->finding(
                key: $key,
                severity: $severity,
                rows: [],
                meaning: $meaning,
                action: $action,
                source: $dependency['source'] ?? '',
                index: $dependency['index'] ?? '',
                scope: $scope,
                population: null,
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
     * A check whose table may not be on this deployment at all.
     *
     * Missing is not broken: these two tables arrive with the marketplace migrations and a shop that
     * never installed them has no warehouses and no batches, which is a configuration rather than a
     * fault. Null means the table is there and the check may run.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>|null
     */
    private function tableCheck(
        string $key,
        string $severity,
        array $shop,
        string $table,
        string $meaning,
        string $action,
        string $source,
        string $index,
        string $missingNote,
        string $missingRemedy,
    ): ?array {
        if ($shop['state'] !== 'ok') {
            return $this->finding(
                key: $key, severity: $severity, rows: [], meaning: $meaning, action: $action,
                source: $source, index: $index, scope: 'catalogue', population: null, emptyNote: null,
                state: $shop['state'], blockedByConnection: true,
            );
        }

        $exists = $this->tableExists($table);
        if ($exists === true) {
            return null;
        }

        return $this->finding(
            key: $key, severity: $severity, rows: [], meaning: $meaning, action: $action,
            source: $source, index: $index, scope: 'catalogue', population: null, emptyNote: null,
            state: $exists === false ? 'not_configured' : 'failed',
            note: $exists === false ? $missingNote : 'Whether this deployment has a ' . $table . ' table could not be read.',
            remedy: $exists === false ? $missingRemedy : null,
        );
    }

    /**
     * One row of a finding, with nothing on it that identifies a person.
     *
     * Product, the two numbers that disagree, and a sentence saying which is which. A customer, an
     * address or an order note on a monitoring page is a copy of shop data in a place nobody
     * remembers to protect, and none of it is needed to fix stock that contradicts itself.
     *
     * @return array<string, mixed>
     */
    private function row(?int $productId, ?string $name, ?int $stock, int|float|null $counted, ?int $units, string $detail, ?string $at): array
    {
        return [
            'product_id' => $productId,
            'product' => $name,
            'stock' => $stock,
            'counted' => $counted,
            'units' => $units,
            'detail' => $detail,
            'at' => $at,
        ];
    }

    /**
     * Findings ranked by the stock behind them.
     *
     * A count is not a priority: forty products sellable at zero matter less than one product whose
     * stock went twice. Where the units are unknown the finding sorts under everything that has a
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

            if ($sort === 'units') {
                $leftUnits = $left['units_known'] ? (float) $left['units'] : null;
                $rightUnits = $right['units_known'] ? (float) $right['units'] : null;

                if ($leftUnits !== $rightUnits) {
                    if ($leftUnits === null) {
                        return 1;
                    }
                    if ($rightUnits === null) {
                        return -1;
                    }

                    return $rightUnits <=> $leftUnits;
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
     * The page's own totals, counted over products rather than over rows.
     *
     * One product can break four checks, and adding the finding counts together would say four
     * products are wrong when one is. Units are deliberately NOT added across checks for the same
     * reason: the same missing stock appears as a negative product, a ledger drift and a variant
     * mismatch, and a single total would count it three times.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function summary(array $findings): array
    {
        $products = [];
        $anonymous = 0;
        $withRows = 0;
        $ran = 0;
        $blocked = 0;
        $unsupported = 0;

        foreach ($findings as $finding) {
            // A question this build cannot ask is neither a check that ran nor a check that broke.
            // Folding it into "could not run" would put a permanent 1 beside a card that is supposed
            // to read zero on a healthy deployment, and the operator would stop looking at it.
            if ($finding['state'] === 'not_supported') {
                $unsupported++;
            } elseif (in_array($finding['state'], ['ok', 'no_data'], true)) {
                $ran++;
            } else {
                $blocked++;
            }

            if (($finding['count'] ?? 0) > 0) {
                $withRows++;
            }

            foreach ($finding['rows'] as $row) {
                if ($row['product_id'] === null) {
                    $anonymous++;

                    continue;
                }

                $products[$row['product_id']] = true;
            }
        }

        // Exact only when every check both ran and finished. A blocked check makes the total a lower
        // bound, and a total presented as exact while a check was blind is a claim nobody measured.
        $exact = array_reduce(
            $findings,
            static fn (bool $carry, array $finding) => $carry && (
                in_array($finding['state'], ['no_data', 'not_supported'], true)
                || ($finding['state'] === 'ok' && $finding['count_exact'] && !$finding['truncated'])
            ),
            true,
        );

        return [
            'checks_total' => count($findings),
            'checks_ran' => $ran,
            'checks_blocked' => $blocked,
            'checks_unsupported' => $unsupported,
            'findings_with_rows' => $withRows,
            'products_implicated' => count($products) + $anonymous,
            'products_implicated_exact' => $exact,
            'note' => 'Counted once per product over the rows this page lists, however many checks that product breaks. Units are not added across checks: one missing unit can show up as a negative product, a ledger drift and a variant mismatch at the same time, and one total would count it three times.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // The cards above the findings

    /**
     * @param  array<string, mixed>  $catalogue
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, Metric>
     */
    private function headline(array $catalogue, array $ledger, array $summary, array $findings): array
    {
        $headline = [];

        $headline['products_with_negative_stock'] = $catalogue['state'] === 'ok' && $catalogue['negative'] !== null
            ? Metric::of(
                value: $catalogue['negative'],
                source: self::PRODUCTS_SOURCE,
                unit: null,
                note: 'Counted exactly from idx_products_current_stock, not from the sample below.',
            )
            : Metric::noData(
                source: self::PRODUCTS_SOURCE,
                note: $catalogue['note'] ?? 'The negative-stock count could not be read.',
            );

        $headline['products_at_zero_stock'] = $catalogue['state'] === 'ok' && $catalogue['zero'] !== null
            ? Metric::of(value: $catalogue['zero'], source: self::PRODUCTS_SOURCE)
            : Metric::noData(source: self::PRODUCTS_SOURCE, note: $catalogue['note'] ?? 'The zero-stock count could not be read.');

        $headline['physical_products_offered_for_sale'] = $catalogue['state'] === 'ok' && $catalogue['sellable'] !== null
            ? Metric::of(
                value: $catalogue['sellable'],
                source: self::PRODUCTS_SOURCE,
                unit: null,
                note: $catalogue['sellable_definition'],
            )
            : Metric::noData(source: self::PRODUCTS_SOURCE, note: $catalogue['note'] ?? 'The sellable count could not be read.');

        $headline['stock_movements_examined'] = match ($ledger['state']) {
            'ok' => Metric::of(
                value: $ledger['examined'],
                source: self::MOVEMENTS_SOURCE,
                unit: null,
                note: $ledger['truncated']
                    ? 'The most recent ' . $ledger['limit'] . ', which is fewer than the ledger holds. Every ledger finding is over these.'
                    : 'Every movement the ledger holds.',
            ),
            'not_configured' => Metric::notConfigured(
                source: self::MOVEMENTS_SOURCE,
                remedy: $ledger['remedy'] ?? '',
                note: $ledger['note'],
            ),
            'failed' => Metric::noData(source: self::MOVEMENTS_SOURCE, note: $ledger['note']),
            default => Metric::noData(source: self::MOVEMENTS_SOURCE, note: $ledger['note'] ?? 'The movement ledger is empty.'),
        };

        // Zero contradictions is only a reading when at least one check actually ran. With every
        // check blocked the same 0 would say the stock is sound, which is the exact claim nothing on
        // this page is in a position to make.
        $blindNote = 'No check could run, so this is not a count of nothing wrong — it is the absence of a count.';
        $partialNote = 'Counted over the ' . $summary['checks_ran'] . ' of ' . $summary['checks_total']
            . ' checks that ran; the rest could not look.';

        $headline['products_whose_stock_contradicts_itself'] = $summary['checks_ran'] === 0
            ? Metric::noData(source: self::PRODUCTS_SOURCE . ', ' . self::MOVEMENTS_SOURCE, note: $blindNote)
            : Metric::of(
                value: $summary['products_implicated'],
                source: self::PRODUCTS_SOURCE . ', ' . self::MOVEMENTS_SOURCE,
                unit: null,
                note: match (true) {
                    $summary['checks_blocked'] > 0 => $partialNote,
                    !$summary['products_implicated_exact'] => 'At least this many: one of the checks stopped at its limit.',
                    default => 'Counted once per product, however many checks it breaks.',
                },
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
     * The gaps that decide how far these findings can be trusted.
     *
     * Published as unconfigured readings rather than left unsaid. Each one is the reason a check
     * above is capped, is a heuristic, or does not exist, and each names the exact change that would
     * remove the caveat.
     *
     * @return array<string, mixed>
     */
    private function gaps(): array
    {
        return [
            'state' => 'not_configured',
            'source' => self::PRODUCTS_SOURCE . ', ' . self::MOVEMENTS_SOURCE,
            'note' => 'Nothing on this deployment records these, so the checks above work around their absence. They are drawn as readings rather than left out, because a caveat nobody states is a caveat nobody applies.',
            'fields' => [
                'stock_reservations' => Metric::notConfigured(
                    source: 'no table',
                    remedy: 'Add a stock_reservations table (product_id, variant, qty, cart_group_id, order_id, expires_at, released_at, status), write it in app/Utils/CartManager.php::add_to_cart and release it on order generation or expiry.',
                    note: 'Nothing reserves stock in this build. Two shoppers can hold the same last unit in their carts, and the loser finds out at checkout.',
                ),
                'restock_writes_no_movement' => Metric::notConfigured(
                    source: self::MOVEMENTS_SOURCE,
                    remedy: 'Call InventoryService::record(type: StockMovement::TYPE_RETURN, …) inside the $restoring branch of app/Utils/OrderManager.php::getStockUpdateOnOrderStatusChange, and log the floor-at-zero clamp beside it as an adjustment with reason count_correction.',
                    note: 'A cancelled order puts its stock back by incrementing the product directly and records nothing, so the ledger is missing one of its two largest movement types. That is why a drift between live stock and the ledger is not by itself evidence of missing stock.',
                ),
                'warehouse_moves_write_no_movement' => Metric::notConfigured(
                    source: self::MOVEMENTS_SOURCE,
                    remedy: 'Emit StockMovement::TYPE_TRANSFER from app/Services/Marketplace/WarehouseService.php::place, ::remove and ::transfer.',
                    note: 'Stock can be moved between warehouses with no record at all. TYPE_TRANSFER is a defined constant that nothing emits.',
                ),
                'movement_uniqueness' => Metric::notConfigured(
                    source: self::MOVEMENTS_SOURCE,
                    remedy: 'ALTER TABLE stock_movements ADD UNIQUE KEY sm_reference_unique (type, reference_type, reference_id, product_id); after clearing the duplicates the check above lists.',
                    note: 'There is no unique key on the reference, which is both why a deduction can be written twice and why this page has to look for it afterwards.',
                ),
                'movement_time_index' => Metric::notConfigured(
                    source: self::MOVEMENTS_SOURCE,
                    remedy: 'ALTER TABLE stock_movements ADD INDEX sm_time_idx (created_at);',
                    note: 'No index leads with created_at, so the ledger cannot be read by time without scanning it. This page reads the most recent movements off the primary key instead, which is why the range control does not narrow the ledger checks.',
                ),
                'variant_stock_index' => Metric::notConfigured(
                    source: 'MySQL product_stocks',
                    remedy: 'ALTER TABLE product_stocks ADD INDEX ps_product_idx (product_id), ADD UNIQUE KEY ps_product_variant_unique (product_id, variant);',
                    note: 'product_stocks has a primary key and nothing else, so a lookup by product reads the whole table. Nothing in app/ writes it either — the live variant quantities are the JSON in products.variation, which is what the variant checks above read.',
                ),
                'stored_inventory_history' => Metric::notConfigured(
                    source: 'monitoring_series',
                    remedy: 'Count these findings in the monitoring flush and write them through BucketWriter::SERIES_PREFIX as inventory.negative_products, inventory.ledger_drift and inventory.double_deductions, and they become chartable and alertable.',
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
     * handler turns into a throw — which would take the whole section down. Anything that is not one
     * of two literals falls back to the default rather than reaching the sort.
     */
    private function sort(Request $request): string
    {
        $value = $request->query('sort', 'units');
        $value = is_string($value) ? trim($value) : 'units';

        return in_array($value, self::SORTS, true) ? $value : 'units';
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * A stored value, with whether it is one this build writes.
     *
     * translate() persists any key it has not already seen into resources/lang/*\/new-messages.php,
     * so a value read out of a column must never reach it — these are free varchars at the database
     * level, and one unrecognised movement type would mint a language key per distinct value.
     *
     * @param  array<int, string>  $allowed
     * @return array{value: string, known: bool}
     */
    private function vocabulary(mixed $stored, array $allowed): array
    {
        $value = is_scalar($stored) ? trim((string) $stored) : '';
        $known = in_array($value, $allowed, true);

        return [
            'value' => $known ? $value : ($this->shortText($value, 40) ?? ''),
            'known' => $known,
        ];
    }

    /** What a movement points at, as a sentence fragment. Echoed, never translated. */
    private function reference(array $movement): string
    {
        $type = $movement['reference_type']['value'] === '' ? 'reference' : $movement['reference_type']['value'];

        return $type . ' ' . ($movement['reference_id'] ?? '?');
    }

    /** A change with its sign kept, so "-3" is not read as "3 units arrived". */
    private function signed(?int $value): string
    {
        if ($value === null) {
            return '?';
        }

        return $value > 0 ? '+' . $value : (string) $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sumUnits(array $rows): ?int
    {
        $units = array_filter(array_column($rows, 'units'), static fn ($value) => $value !== null);

        return $units === [] ? null : (int) array_sum($units);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function unitsKnown(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['units'] === null) {
                return false;
            }
        }

        return $rows !== [];
    }

    /**
     * A stamp the shop wrote, in the timezone the dashboard renders in.
     *
     * The shop writes its timestamps in the process timezone; monitoring stores its own in UTC.
     * Reading a movement's created_at as UTC would put it hours away from the order it belongs to on
     * any deployment whose display timezone is not UTC, which is the class of bug the Clock exists to
     * prevent.
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
            // An unparseable stamp is shown as stored rather than dropped: the movement really
            // happened, and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? $this->shortText((string) $stored, 40) : null;
        }
    }

    /**
     * A UTC bound turned into the literal the shop's own columns are comparable with.
     *
     * The same hours-out error in the other direction: a window computed in UTC and compared against
     * a column written in the shop's zone silently reads the wrong hours.
     */
    private function shopBound(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s');
    }

    /** Today, as the shop's own date columns mean it. An expiry date carries no offset to convert. */
    private function shopDate(): string
    {
        return Clock::now()->setTimezone(date_default_timezone_get())->format('Y-m-d');
    }

    /**
     * A failed read, said in one line that is safe to print.
     *
     * A QueryException carries the statement and its bindings, and an exception message is one of the
     * most reliable places in an application to find a token or a customer's address — so it goes
     * through the redactor and is bounded before it reaches a page an operator can screenshot.
     */
    private function failureNote(\Throwable $exception): string
    {
        return class_basename($exception) . ': '
            . $this->redactor->text(mb_substr($exception->getMessage(), 0, 400));
    }

    /**
     * A count, or null when the row had none to give.
     *
     * Null is preserved rather than cast: (int) null is 0, and a zero in a stock column is the single
     * most misleading value this page could print.
     */
    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function shortText(mixed $value, int $length): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $this->redactor->text(mb_substr($value, 0, $length));
    }
}
