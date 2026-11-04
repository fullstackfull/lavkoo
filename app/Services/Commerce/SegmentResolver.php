<?php

namespace App\Services\Commerce;

use App\Models\CustomerSegment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which segments this customer belongs to, right now (Phase 3.4).
 *
 * Deterministic and cheap by construction: the per-customer metrics are two indexed queries
 * cached for ten minutes under a key only the server reads (§42, §64 — nothing customer-specific
 * ever enters a shared cache value; the segment KEYS the response varies on are part of the
 * cache KEY, never data another shopper could receive). Failure means "no segments", which is
 * the base, non-personalised experience — fail open, §44.
 */
class SegmentResolver
{
    private const METRICS_TTL = 600;
    private const KEYS_TTL = 60;

    public function __construct(private readonly SegmentRules $rules)
    {
    }

    /**
     * The segment keys this viewer carries. Guests carry none — the built-in guest/customer
     * audience already answers that question, and a rule-based segment reads order history a
     * guest does not have.
     *
     * @return array<int, string> sorted, so two requests by one customer always agree
     */
    public function segmentsFor(?int $customerId): array
    {
        if ($customerId === null || $customerId <= 0 || !$this->serving()) {
            return [];
        }

        try {
            $segments = $this->liveSegments();

            if ($segments === []) {
                return [];
            }

            $metrics = $this->metricsFor($customerId);
            $keys = [];

            foreach ($segments as $segment) {
                $rows = $segment->ruleRows();

                if ($rows === []) {
                    continue; // a segment with no rules matches nobody, not everybody
                }

                foreach ($rows as $rule) {
                    if (!$this->rules->holds($rule, $metrics)) {
                        continue 2;
                    }
                }

                $keys[] = $segment->key;
            }

            sort($keys);

            return $keys;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Every live segment key — what the builder's audience picker offers and what saved
     * audience tokens are validated against.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        if (!$this->serving()) {
            return [];
        }

        try {
            return Cache::remember('commerce_segment_keys', self::KEYS_TTL, fn () => CustomerSegment::query()
                ->live()
                ->orderBy('key')
                ->pluck('key')
                ->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The numbers the rules read, from records the shop already keeps.
     *
     * @return array<string, int|float|null>
     */
    public function metricsFor(int $customerId): array
    {
        return Cache::remember('commerce_segment_ctx_' . $customerId, self::METRICS_TTL, function () use ($customerId) {
            $orders = DB::table('orders')
                ->where('customer_id', $customerId)
                ->where('is_guest', 0)
                ->selectRaw('COUNT(*) as orders_count, MAX(created_at) as last_order_at, COALESCE(SUM(order_amount), 0) as total_spent')
                ->first();

            $registeredAt = User::query()->whereKey($customerId)->value('created_at');

            return [
                'orders_count'            => (int) ($orders->orders_count ?? 0),
                'days_since_last_order'   => $orders->last_order_at !== null
                    ? (int) floor(Carbon::parse($orders->last_order_at)->diffInDays(now()))
                    : null,
                'days_since_registration' => $registeredAt !== null
                    ? (int) floor(Carbon::parse($registeredAt)->diffInDays(now()))
                    : null,
                'total_spent'             => (float) ($orders->total_spent ?? 0),
            ];
        });
    }

    /**
     * EVERY segment key on record — live or off, engine on or off. This is the save-time
     * vocabulary: a token an admin once targeted must stay valid to SAVE even while the engine
     * is disabled, or the save would silently widen the section to everyone. Serving still uses
     * {@see keys()}, which respects both gates.
     *
     * @return array<int, string>
     */
    public function allKeys(): array
    {
        try {
            if (!Schema::hasTable('customer_segments')) {
                return [];
            }

            return CustomerSegment::query()->orderBy('key')->pluck('key')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Drop the segment list caches so a create/update/delete reaches serving now, not in 60s. */
    public static function forgetLists(): void
    {
        Cache::forget('commerce_segment_keys');
        Cache::forget('commerce_segments_live');
    }

    /** @return \Illuminate\Support\Collection<int, CustomerSegment> */
    private function liveSegments()
    {
        return Cache::remember('commerce_segments_live', self::KEYS_TTL, fn () => CustomerSegment::query()
            ->live()
            ->orderBy('key')
            ->get());
    }

    private function serving(): bool
    {
        if (!config('commerce.enabled', true)) {
            return false;
        }

        try {
            return Schema::hasTable('customer_segments');
        } catch (\Throwable) {
            return false;
        }
    }
}
