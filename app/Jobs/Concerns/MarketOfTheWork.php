<?php

namespace App\Jobs\Concerns;

use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;

/**
 * Which market the RECORD a queued job carries belongs to (A1-14, M-504/M-768/M-888).
 *
 * {@see CarriesItsStorefront} records the market of the request that dispatched a job, and for work a customer or an operator started that
 * request IS the market. For three kinds of work it is not:
 *
 *  - **A provider webhook.** `api/fulfillment-webhooks` is deliberately served across every country
 *    ({@see StorefrontCatalog}), so nothing is bound on it: a shipment of Kuwait and a shipment of Saudi Arabia arrive at the same address
 *    and the dispatching request can say nothing about either. Taking the market from the address the provider happened to call is the
 *    defect that documentation already names; the record is the only honest source.
 *  - **The recovery sweep and the scheduler.** `fulfillment:recover` runs every minute as a console process and dispatches work for every
 *    market at once. Each job it queues belongs to one market, and the sweep belongs to none.
 *  - **A panel.** An operator sees every country, so a shipment they push is queued with no market bound, and the market of the person
 *    pressing the button was never the market of the shipment anyway.
 *
 * So a job that names a record asks this instead, and falls back to the dispatching request's market when the record cannot be read. The
 * fallback is what keeps it safe: an installation whose orders have no market column, a record deleted between dispatch and run, a payload
 * from before this existed — each answers null and the job runs exactly as it runs today.
 *
 * **Off until `gcc.storefronts.queues.market_from_record` is on**, and dead while storefronts themselves are off. Both gates are checked
 * here rather than in each job, so a job cannot be wired up and ship live by accident.
 *
 * Every read goes through the QUERY BUILDER, never a model. `App\Models\Order` and `App\Models\ProviderFulfillment` both carry
 * `StaffCountryOrderScope`, so a restricted employee running a job through a model would find no row and the market would silently fall
 * back to the dispatcher's with nothing saying so — the trap `StorefrontCurrency::withinOrder` already documents.
 *
 * Nothing is memoised. A schema answer cached statically outlives the schema on a real server, where every test drops the tables, and a
 * job runs once: two extra reads per job is the right side of that trade.
 */
final class MarketOfTheWork
{
    private const COUNTRY_CODE = '/^[A-Z]{2}$/';

    /** Whether a job may read its market off its record at all: the switch is on and storefronts exist. */
    public static function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.queues.market_from_record')
            && StorefrontResolver::fromConfig()->isEnabled();
    }

    /** The market an order was sold in, or null when it records none, when the column does not exist, or while the switch is off. */
    public static function ofOrder(?int $orderId): ?string
    {
        if ($orderId === null || !self::isEnabled() || !app(OrderStorefrontSnapshot::class)->columnReady()) {
            return null;
        }

        return self::asACountry(DB::table('orders')->where('id', $orderId)->value(OrderStorefrontSnapshot::COLUMN));
    }

    /** The market of the order a shipment belongs to. A shipment never carries a market of its own: it is the order's. */
    public static function ofFulfillment(?int $fulfillmentId): ?string
    {
        if ($fulfillmentId === null || !self::isEnabled() || !FulfillmentSchema::ready()) {
            return null;
        }

        return self::ofOrder(orderId: self::asAnId(DB::table('provider_fulfillments')->where('id', $fulfillmentId)->value('order_id')));
    }

    /**
     * The market of the order a stored provider event is about: the event names a shipment, the shipment names the order, the order names
     * the market. This is the read a webhook cannot make for itself.
     */
    public static function ofFulfillmentEvent(?int $eventId): ?string
    {
        if ($eventId === null || !self::isEnabled() || !FulfillmentSchema::ready()) {
            return null;
        }

        $fulfillmentId = DB::table('provider_fulfillment_events')->where('id', $eventId)->value('provider_fulfillment_id');

        return self::ofFulfillment(fulfillmentId: self::asAnId($fulfillmentId));
    }

    /**
     * The market a product belongs to, for work whose audience is the people who asked about that one product. A product belongs to exactly
     * one country (GCC-02/03), so the notice about it is written in that country's market and in no other.
     */
    public static function ofProduct(?int $productId): ?string
    {
        if ($productId === null || !self::isEnabled() || !StorefrontCatalog::columnReady()) {
            return null;
        }

        return self::asACountry(DB::table('products')->where('id', $productId)->value(StorefrontCatalog::COLUMN));
    }

    /** A recorded market, upper-cased as every other reader of these columns reads it, or null when the row holds nothing usable. */
    private static function asACountry(mixed $recorded): ?string
    {
        $code = strtoupper(trim((string) $recorded));

        return preg_match(self::COUNTRY_CODE, $code) === 1 ? $code : null;
    }

    /** A foreign key as a positive id, or null: a row whose key is missing or zero names no record to follow. */
    private static function asAnId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
