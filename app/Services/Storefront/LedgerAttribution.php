<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What stands behind a ledger row (E-05): the order, order group, refund or payment it came from, the staff member who made it, and the storefront, currency and selling
 * company of the market it was made in. Every writer of the wallet ledger and of the loyalty ledger asks it for the columns to add to the row it is about to save, so the six of
 * them record the same thing the same way.
 *
 * The market is copied from the order's own record (the sale wrote it: GCC-05 and GCC-13), through the refund for a refund. A gateway top-up has no order, and the gateway reports
 * back in a request that names no storefront, so it reads the storefront the payment was started in (PaymentStorefront), never the request. A movement with no record behind it
 * takes the storefront of the request while storefronts are on, and nothing for a panel, a queue or a console command; with storefronts off the market stays empty. The actor
 * is whoever is signed in under the staff guard the caller names, never a value the request sent.
 *
 * It does nothing before the ledger migration has run: the table is asked first, remembered, and nothing else is read, so a writer saves the row exactly as it always did. A
 * "yes" lasts for the process, a "no" is asked again after half a minute so a worker started before the migration notices it without a restart.
 */
#[Singleton]
final class LedgerAttribution
{
    private const COLUMNS = ['source_type', 'source_id', 'actor_type', 'actor_id', 'storefront_country', 'currency_code', 'legal_entity_id'];

    private const MARKET_COLUMNS = ['storefront_country', 'currency_code', 'legal_entity_id'];

    /** The one of those three that says which market a movement belongs to. */
    private const MARKET_COLUMN = 'storefront_country';

    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array<string, array{columns: list<string>, at: int}> */
    private array $listings = [];

    public function __construct(
        private readonly StorefrontCurrency $storefrontCurrency,
        private readonly LegalEntityRegistry $legalEntities,
    ) {
    }

    /**
     * @return array<string, int|string> the columns of $table that this movement fills: only those the table has, and only those with a value
     */
    public function of(string $table, ?LedgerOrigin $origin = null): array
    {
        $columns = $this->columnsOf(table: $table, wanted: self::COLUMNS);

        if ($columns === []) {
            return [];
        }

        $order = $this->orderOf(origin: $origin);
        $values = [
            ...$this->sourceOf(origin: $origin, order: $order),
            ...$this->actorOf(origin: $origin),
            ...$this->marketOf(origin: $origin, order: $order),
        ];

        return array_intersect_key(array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== ''), array_flip($columns));
    }

    /** Forgets what was remembered about the tables, after a migration ran or was rolled back in this process. */
    /**
     * Whether this ledger records the market a movement belongs to, which is the column a per-market statement is narrowed by (E-04).
     *
     * The same cached listing the attribution itself reads, so a reader that asks this costs nothing the writer has not already paid, and
     * a ledger whose migration has not run answers false -- a statement is then not narrowed at all, which is the platform of today.
     */
    public function marketIsRecordedIn(string $table): bool
    {
        return in_array(self::MARKET_COLUMN, $this->columnsOf($table, self::MARKET_COLUMNS), true);
    }

    public function flush(): void
    {
        $this->listings = [];
    }

    /** The order whose record the movement copies: the order itself, the first order of a checkout, or the order a refund is for. */
    private function orderOf(?LedgerOrigin $origin): ?object
    {
        $orderId = match ($origin?->sourceType) {
            LedgerOrigin::ORDER, LedgerOrigin::ORDER_GROUP => $origin->subject,
            LedgerOrigin::REFUND => DB::table('refund_requests')->where('id', $origin->subject)->value('order_id'),
            default => null,
        };

        if ($orderId === null) {
            return null;
        }

        $columns = $this->columnsOf(table: 'orders', wanted: ['order_group_id', ...self::MARKET_COLUMNS]);

        return $columns === [] ? null : DB::table('orders')->where('id', $orderId)->first($columns);
    }

    /** @return array<string, int|string|null> */
    private function sourceOf(?LedgerOrigin $origin, ?object $order): array
    {
        return match ($origin?->sourceType) {
            LedgerOrigin::ORDER, LedgerOrigin::REFUND, LedgerOrigin::PAYMENT => ['source_type' => $origin->sourceType, 'source_id' => (string) $origin->subject],
            LedgerOrigin::ORDER_GROUP => $order?->order_group_id === null ? [] : ['source_type' => $origin->sourceType, 'source_id' => $order->order_group_id],
            default => [],
        };
    }

    /** @return array<string, int|string> */
    private function actorOf(?LedgerOrigin $origin): array
    {
        $actorId = $origin?->actor?->signedInId();

        return $actorId === null ? [] : ['actor_type' => $origin->actor->value, 'actor_id' => $actorId];
    }

    /** @return array<string, int|string|null> */
    private function marketOf(?LedgerOrigin $origin, ?object $order): array
    {
        if ($origin?->sourceType === LedgerOrigin::PAYMENT) {
            return $this->marketOfPayment(paymentId: $origin->subject);
        }

        $recorded = Arr::only((array) $order, self::MARKET_COLUMNS);

        return array_filter($recorded, static fn (mixed $value): bool => $value !== null) === [] ? $this->marketOfRequest() : $recorded;
    }

    /** The storefront the payment was started in and the currency it was taken in; nothing when it was started with storefronts off. */
    private function marketOfPayment(int|string $paymentId): array
    {
        $payment = DB::table('payment_requests')->where('id', $paymentId)->first(['additional_data', 'currency_code']);
        $additionalData = json_decode((string) $payment?->additional_data, true);
        $recorded = is_array($additionalData) ? ($additionalData[PaymentStorefront::KEY] ?? null) : null;
        $country = StorefrontResolver::fromConfig()->contextOfRecordedCountry(is_string($recorded) ? $recorded : null)?->country;

        if ($country === null) {
            return [];
        }

        return ['storefront_country' => $country, 'currency_code' => $payment->currency_code, 'legal_entity_id' => $this->legalEntities->forCountry($country)?->id];
    }

    /** The storefront the request is served from, with the currency and the company the sale there would carry. */
    private function marketOfRequest(): array
    {
        $country = StorefrontCatalog::country();

        if ($country === null) {
            return [];
        }

        return [
            'storefront_country' => $country,
            'currency_code' => $this->storefrontCurrency->isEnabled() ? $this->storefrontCurrency->currencyOfSales($country)?->code : null,
            'legal_entity_id' => $this->legalEntities->forCountry($country)?->id,
        ];
    }

    /**
     * @param list<string> $wanted
     *
     * @return list<string> the columns of $wanted that the table has
     */
    private function columnsOf(string $table, array $wanted): array
    {
        $listing = $this->listings[$table] ?? null;

        if ($listing === null || (array_intersect($wanted, $listing['columns']) === [] && time() - $listing['at'] >= self::NEGATIVE_ANSWER_SECONDS)) {
            $listing = $this->listings[$table] = ['columns' => Schema::getColumnListing($table), 'at' => time()];
        }

        return array_values(array_intersect($wanted, $listing['columns']));
    }
}
