<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Support\PathNormalizer;
use App\Services\Analytics\Support\AnalyticsPolicy;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes events, and is not allowed to matter.
 *
 * The rule that shapes every line here: if analytics fails, the shop does not. Events are buffered
 * in memory during the request and written once, after the response has been sent, in a single
 * bulk insert. Nothing on the checkout path waits for this, nothing on it can throw through to a
 * customer, and an unreachable analytics database costs the merchant a row in a report — never an
 * order.
 *
 * Two other properties are load-bearing:
 *
 *  - IDEMPOTENCE, where it is asked for. Events carry a dedupe key and the table has a unique
 *    index on it. What that key covers decides what is actually collapsed, and the two cases are
 *    not the same strength: an act that names its own key — an order, a payment — is one row
 *    however many times its page is reloaded, for as long as the rows are kept. An act that does
 *    not is collapsed only when the duplicate lands in the SAME few-second bucket, so a
 *    double-submitted form usually produces one row and a retry that straddles a bucket boundary
 *    produces two. That is the deliberate trade — see dedupeKey() — and it is worth stating
 *    plainly, because "duplicates are collapsed" would read as a guarantee it does not make.
 *    Revenue counted twice is worse than revenue not counted at all, because nobody goes looking
 *    for a number that is too high, and that is exactly why the money events carry explicit keys.
 *
 *  - REDACTION. Properties pass through the same redactor the monitoring system uses, so a
 *    password, an OTP, a token or a card number cannot reach this table by way of a well-meaning
 *    instrumentation call somewhere else in the codebase.
 */
class EventRecorder
{
    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    /** @var array<string, float|int> running totals for the session row, applied once on flush */
    private array $sessionDelta = [];

    private bool $dropped = false;

    public function __construct(
        private readonly VisitorContext $context,
        private readonly PathNormalizer $paths,
        private readonly \App\Services\Analytics\Support\PrivacyGate $privacy,
    ) {
    }

    /**
     * Queue an event. Cheap: no query, no validation round trip, no exception.
     */
    public function record(AnalyticsEvent $event, ?Request $request = null): void
    {
        try {
            if (!app(AnalyticsPolicy::class)->enabled()) {
                return;
            }

            $request ??= request();

            // Asked here as well as in the middleware, because commerce instrumentation records
            // from services that never pass through it — an order placed from the POS, a status
            // change made by an administrator — and a privacy setting that half the callers honour
            // is not a privacy setting.
            if (!$this->privacy->allows($request)) {
                return;
            }

            $this->context->resolve($request);

            $visitorId = $this->context->visitorId($request);

            if ($visitorId === null) {
                return;
            }

            if (count($this->buffer) >= (int) config('analytics.buffer_limit', 40)) {
                // A single request producing forty events is a loop, not a customer. The overflow
                // is dropped and RECORDED as dropped, so the data-quality screen can show it
                // rather than the numbers just being quietly short.
                $this->dropped = true;

                return;
            }

            $path = $event->path ?? $this->paths->fromRequest($request);

            $this->buffer[] = [
                'name' => Str::limit($event->name, 48, ''),
                'category' => $event->category(),
                'visitor_id' => $visitorId,
                'session_id' => null,          // filled on flush: one session lookup per request
                'channel' => $this->channel($request),
                'user_type' => $this->context->userType(),
                'user_id' => $this->context->userId(),
                'entity_type' => $event->entityType !== null ? Str::limit($event->entityType, 24, '') : null,
                'entity_id' => $event->entityId !== null ? Str::limit((string) $event->entityId, 64, '') : null,
                'vendor_id' => $event->vendorId,
                'value' => $event->value,
                'currency' => $event->currency !== null ? Str::limit($event->currency, 8, '') : null,
                'quantity' => $event->quantity,
                'path' => $path,
                'properties' => $this->properties($event->properties),
                'is_bot' => $this->context->isBot(),
                // A seller-domain event is the shop's own business, not the merchant browsing their
                // own storefront, so the internal-traffic filter must not reach it — marking these
                // internal is what made payout_requested and kyc_submitted uncountable.
                'is_internal' => !$event->isSellerDomain() && $this->context->isInternal(),
                'dedupe_key' => $this->dedupeKey($event, $visitorId, $path),
                'occurred_at' => Carbon::now(),
            ];

            $this->accumulate($event);
        } catch (\Throwable) {
            // Recording an event must never be the reason a page fails.
        }
    }

    /**
     * Write everything queued. Called from terminable middleware, after the response has gone out.
     */
    public function flush(?Request $request = null): int
    {
        if ($this->buffer === []) {
            return 0;
        }

        $queued = $this->buffer;
        $delta = $this->sessionDelta;

        // Cleared first: a failure below must not leave events queued to be written twice by a
        // later flush in the same process (queue workers and Octane both reuse the container).
        $this->buffer = [];
        $this->sessionDelta = [];

        try {
            $sessionId = $this->context->sessionId($request ?? request());

            foreach ($queued as $index => $row) {
                $queued[$index]['session_id'] = $sessionId;
                $queued[$index]['properties'] = $row['properties'] === null ? null : json_encode($row['properties']);
            }

            // insertOrIgnore, not insert: the unique index on dedupe_key is the deduplication, and
            // a duplicate must be a no-op rather than an exception on the response path.
            //
            // The id watermark is taken first because the counters below must reflect what was
            // ACTUALLY written. Incrementing them from the in-memory buffer counted every
            // deduplicated event anyway, which put the session and visitor totals permanently
            // above the event table they are supposed to summarise — and since the rollup reads
            // orders and revenue off the session row while reading event counts off the event
            // table, the same day's figures disagreed with each other depending on which screen
            // asked.
            $watermark = (int) ($this->connection()->table('analytics_events')->max('id') ?? 0);
            $written = $this->connection()->table('analytics_events')->insertOrIgnore($queued);

            if ($written < count($queued)) {
                $delta = $this->deltaOfWrittenRows($watermark, $queued, $sessionId);
            }

            $this->applySessionDelta($sessionId, $delta);
            $this->applyVisitorDelta($queued[0]['visitor_id'] ?? null, $delta, max(0, $written));
            $this->health('events_written', $written);

            if ($this->dropped) {
                $this->health('events_dropped_buffer_full', 1);
                $this->dropped = false;
            }

            return $written;
        } catch (\Throwable $exception) {
            $this->health('write_failed', 1, class_basename($exception) . ': ' . $exception->getMessage());

            return 0;
        }
    }

    /** How many events are waiting — used by the tests and the debugger, not by the request path. */
    public function pending(): int
    {
        return count($this->buffer);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The identity of an act, so the same act recorded twice is stored once.
     *
     * An explicit key from the caller wins, and it is scoped to the event name and that key ALONE —
     * deliberately, because an order is the same order whoever reloads its confirmation page, and
     * because a guest and their later signed-in self are two visitor ids for one person. The cost
     * is that an explicit key must be globally unique for its event: order:{id} is, "checkout"
     * would not be, and would collapse two customers into one row.
     *
     * Without an explicit key the identity is the visitor, the event, what it was about, the page,
     * and a bucket number — time divided by the dedupe window, NOT a sliding window over the last
     * few seconds. So two identical events collapse when they fall in the same bucket and survive
     * when they straddle its edge, which for a 5-second window catches most accidental repeats and
     * is not claimed to catch all of them. A sliding window would need a read before every insert
     * on the request path; a longer bucket would collapse four genuine pageviews in one visit into
     * one, which is a measurement error rather than deduplication.
     */
    private function dedupeKey(AnalyticsEvent $event, string $visitorId, ?string $path): ?string
    {
        if ($event->dedupeKey !== null) {
            return substr(hash('sha256', $event->name . '|' . $event->dedupeKey), 0, 60);
        }

        // Short, and keyed on the page as well as the entity. The duplicates worth collapsing are
        // accidents seconds apart — a double-clicked button, a page restored from the back/forward
        // cache, a beacon retried on a flaky connection. A window of minutes would instead collapse
        // four genuine pageviews in one visit into one, which is a measurement error, not a
        // deduplication. Anything that must be unique for longer than that says so explicitly:
        // an order carries order:{id} and is one sale however often its page is reloaded.
        $window = max(1, (int) config('analytics.dedupe_window_seconds', 5));
        // Carbon rather than time(), so the clock is the same one every other timestamp in this
        // class reads — and so the boundary behaviour described above is testable at all.
        $bucket = intdiv(Carbon::now()->getTimestamp(), $window);

        return substr(hash('sha256', implode('|', [
            $visitorId,
            $event->name,
            (string) $event->entityType,
            (string) $event->entityId,
            (string) $path,
            (string) $bucket,
        ])), 0, 60);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|null
     */
    private function properties(array $properties): ?array
    {
        if ($properties === []) {
            return null;
        }

        // The monitoring redactor already knows every secret name this project uses; reusing it
        // means analytics cannot drift into leaking something monitoring has learned to hide.
        $clean = Redactor::make()->array($properties);

        return $clean === [] ? null : array_slice($clean, 0, 25, true);
    }

    /**
     * Recompute the counters from the rows the insert actually created.
     *
     * Only runs when deduplication dropped something, which is rare — a double-clicked button, a
     * page restored from the back/forward cache, a retried beacon. One indexed read after the
     * response has already been sent is the right price for totals that agree with the events
     * they summarise.
     *
     * @param  array<int, array<string, mixed>>  $queued  the rows this flush attempted
     * @return array<string, float|int>
     */
    private function deltaOfWrittenRows(int $watermark, array $queued, ?string $sessionId): array
    {
        $delta = [];

        $keys = array_values(array_filter(array_column($queued, 'dedupe_key')));

        if ($keys === []) {
            return $delta;
        }

        try {
            $rows = $this->connection()->table('analytics_events')
                // The id watermark alone is not this flush's rows: flush() runs in terminate(), so
                // between the MAX(id) read and this one, other requests have inserted their own.
                // Counting those put another customer's order and its revenue onto this visitor's
                // session. The rows this flush owns are the ones carrying ITS dedupe keys, on ITS
                // session — everything else above the watermark belongs to somebody else.
                ->where('id', '>', $watermark)
                ->whereIn('dedupe_key', $keys)
                ->when($sessionId !== null, fn ($query) => $query->where('session_id', $sessionId))
                ->get(['name', 'value']);
        } catch (\Throwable) {
            return $delta;
        }

        foreach ($rows as $row) {
            $delta['events'] = ($delta['events'] ?? 0) + 1;

            match ($row->name) {
                AnalyticsEvent::PAGE_VIEWED => $delta['pageviews'] = ($delta['pageviews'] ?? 0) + 1,
                AnalyticsEvent::CART_ADDED => $delta['cart_adds'] = ($delta['cart_adds'] ?? 0) + 1,
                AnalyticsEvent::CHECKOUT_STARTED => $delta['checkouts'] = ($delta['checkouts'] ?? 0) + 1,
                AnalyticsEvent::ORDER_PLACED => $delta = $this->addOrder($delta, (float) ($row->value ?? 0)),
                default => null,
            };
        }

        return $delta;
    }

    /**
     * @param  array<string, float|int>  $delta
     * @return array<string, float|int>
     */
    private function addOrder(array $delta, float $value): array
    {
        $delta['orders'] = ($delta['orders'] ?? 0) + 1;
        $delta['revenue'] = ($delta['revenue'] ?? 0) + $value;

        return $delta;
    }

    private function accumulate(AnalyticsEvent $event): void
    {
        $this->sessionDelta['events'] = ($this->sessionDelta['events'] ?? 0) + 1;

        match ($event->name) {
            AnalyticsEvent::PAGE_VIEWED => $this->sessionDelta['pageviews'] = ($this->sessionDelta['pageviews'] ?? 0) + 1,
            AnalyticsEvent::CART_ADDED => $this->sessionDelta['cart_adds'] = ($this->sessionDelta['cart_adds'] ?? 0) + 1,
            AnalyticsEvent::CHECKOUT_STARTED => $this->sessionDelta['checkouts'] = ($this->sessionDelta['checkouts'] ?? 0) + 1,
            AnalyticsEvent::ORDER_PLACED => $this->countOrder($event),
            default => null,
        };
    }

    private function countOrder(AnalyticsEvent $event): void
    {
        $this->sessionDelta['orders'] = ($this->sessionDelta['orders'] ?? 0) + 1;
        $this->sessionDelta['revenue'] = ($this->sessionDelta['revenue'] ?? 0) + (float) ($event->value ?? 0);
    }

    /**
     * @param  array<string, float|int>  $delta
     */
    private function applySessionDelta(?int $sessionId, array $delta): void
    {
        if ($sessionId === null || $delta === []) {
            return;
        }

        $update = [];
        foreach (['pageviews', 'events', 'cart_adds', 'checkouts', 'orders'] as $column) {
            if (!empty($delta[$column])) {
                $update[$column] = DB::raw($column . ' + ' . (int) $delta[$column]);
            }
        }

        if (!empty($delta['revenue'])) {
            $update['revenue'] = DB::raw('revenue + ' . (float) $delta['revenue']);
        }

        // A second pageview is the definition of not bouncing, applied in the same statement so a
        // report can never see the pageview without the bounce flag that follows from it.
        //
        // Ordered BEFORE the increment on purpose: MySQL evaluates the assignments in an UPDATE
        // left to right, and a later expression reads the value an earlier one has already
        // written. Computing this after the increment double-counted the new pageview and marked
        // every single-page visit as engaged.
        if (!empty($delta['pageviews'])) {
            $total = 'pageviews + ' . (int) $delta['pageviews'];
            $update = [
                'is_bounce' => DB::raw("CASE WHEN {$total} >= 2 THEN 0 ELSE is_bounce END"),
                'is_engaged' => DB::raw("CASE WHEN {$total} >= 2 THEN 1 ELSE is_engaged END"),
            ] + $update;
        }

        if ($update !== []) {
            $this->connection()->table('analytics_sessions')->where('id', $sessionId)->update($update);
        }
    }

    /**
     * @param  array<string, float|int>  $delta
     */
    private function applyVisitorDelta(?string $visitorId, array $delta, int $events): void
    {
        if ($visitorId === null) {
            return;
        }

        $update = [
            'events' => DB::raw('events + ' . $events),
            'last_seen_at' => Carbon::now(),
        ];

        if (!empty($delta['orders'])) {
            $update['orders'] = DB::raw('orders + ' . (int) $delta['orders']);
            $update['revenue'] = DB::raw('revenue + ' . (float) ($delta['revenue'] ?? 0));
        }

        $this->connection()->table('analytics_visitors')->where('visitor_id', $visitorId)->update($update);
    }

    /**
     * Analytics watching itself.
     *
     * A collector that stops has to be able to say so: without this, a broken pipeline draws a
     * flat line that everybody reads as a quiet week.
     */
    /**
     * Record that a visit was not measured, and why.
     *
     * PrivacyGate::reason() was written "for the data-quality screen" and had no caller anywhere, so
     * a shop that switched consent on watched its reported traffic fall with nothing explaining the
     * drop — which is the one circumstance in which an operator concludes analytics is broken and
     * switches the privacy control back off.
     */
    public function recordPrivacyRefusal(string $reason): void
    {
        $this->health('privacy_blocked_' . $reason, 1);
    }

    private function health(string $signal, int $count, ?string $detail = null): void
    {
        try {
            $now = Carbon::now();

            // Update first, insert only if the signal is new: updateOrInsert would carry the raw
            // `count + n` expression into the INSERT, where there is no existing row to add to.
            $updated = $this->connection()->table('analytics_health')
                ->where('signal', $signal)
                ->update([
                    'count' => DB::raw('`count` + ' . max(0, $count)),
                    'detail' => $detail !== null ? Str::limit($detail, 500, '') : null,
                    'occurred_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated === 0) {
                $this->connection()->table('analytics_health')->insertOrIgnore([
                    'signal' => $signal,
                    'count' => max(0, $count),
                    'detail' => $detail !== null ? Str::limit($detail, 500, '') : null,
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Throwable) {
            // The health table failing is itself only worth knowing if it can be recorded.
        }
    }

    private function channel(Request $request): string
    {
        return match (true) {
            $request->headers->has('X-App-Version') => 'app',
            $request->is('api/*') => 'api',
            default => 'web',
        };
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('analytics.connection'));
    }
}
