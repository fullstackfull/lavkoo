<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Support\PathNormalizer;
use Illuminate\Http\Request;

/**
 * The one door into analytics.
 *
 * Every instrumentation point in the application — a controller, a service, a listener — calls a
 * named method here rather than building an event by hand. That is the difference between a schema
 * and a convention: with one door, "add to cart" has a single spelling, a single set of properties
 * and a single place to change when the shop changes. Without it, six controllers invent six
 * shapes and the funnel silently stops joining up.
 *
 * Every method is safe to call from anywhere, including inside a database transaction and inside a
 * queued job: nothing here queries, nothing throws, and nothing is written until the response has
 * already been sent.
 */
class Analytics
{
    public function __construct(
        private readonly EventRecorder $recorder,
        private readonly PathNormalizer $paths,
    ) {
    }

    /** A page a visitor looked at. Recorded by middleware, not by hand in every controller. */
    public function pageViewed(Request $request): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::PAGE_VIEWED,
            path: $this->paths->fromRequest($request),
        ), $request);
    }

    public function productViewed(int $productId, ?int $vendorId = null, ?float $price = null, array $properties = []): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::PRODUCT_VIEWED,
            entityType: 'product',
            entityId: (string) $productId,
            vendorId: $vendorId,
            value: $price,
            currency: $this->currency(),
            properties: $properties,
        ));
    }

    public function categoryViewed(int $categoryId, array $properties = []): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::CATEGORY_VIEWED,
            entityType: 'category',
            entityId: (string) $categoryId,
            properties: $properties,
        ));
    }

    public function shopViewed(int $shopId, ?int $vendorId = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::SHOP_VIEWED,
            entityType: 'shop',
            entityId: (string) $shopId,
            vendorId: $vendorId,
        ));
    }

    public function brandViewed(int $brandId): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::BRAND_VIEWED,
            entityType: 'brand',
            entityId: (string) $brandId,
        ));
    }

    /**
     * A search, and how many results it found.
     *
     * The result count is the point: a search term with a healthy volume and zero results is a
     * product the shop should stock, and it is invisible to every report that only counts searches.
     */
    public function searchPerformed(string $term, int $results, array $properties = []): void
    {
        $term = $this->searchTerm($term);

        if ($term === null) {
            return;
        }

        $this->recorder->record(new AnalyticsEvent(
            name: $results > 0 ? AnalyticsEvent::SEARCH_PERFORMED : AnalyticsEvent::SEARCH_NO_RESULTS,
            entityType: 'search_term',
            entityId: $term,
            quantity: $results,
            properties: $properties,
        ));
    }

    public function cartAdded(int $productId, int $quantity, float $value, ?int $vendorId = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::CART_ADDED,
            entityType: 'product',
            entityId: (string) $productId,
            vendorId: $vendorId,
            value: $value,
            currency: $this->currency(),
            quantity: $quantity,
        ));
    }

    public function cartRemoved(int $productId, ?int $quantity = null, ?float $value = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::CART_REMOVED,
            entityType: 'product',
            entityId: (string) $productId,
            value: $value,
            currency: $this->currency(),
            quantity: $quantity,
        ));
    }

    /** A step of checkout was reached. The step name is what makes the funnel a funnel. */
    public function checkoutStep(string $step, ?float $cartValue = null, array $properties = []): void
    {
        $name = match ($step) {
            'started' => AnalyticsEvent::CHECKOUT_STARTED,
            'address' => AnalyticsEvent::CHECKOUT_ADDRESS,
            'shipping' => AnalyticsEvent::CHECKOUT_SHIPPING,
            'payment' => AnalyticsEvent::CHECKOUT_PAYMENT,
            default => null,
        };

        if ($name === null) {
            return;
        }

        $this->recorder->record(new AnalyticsEvent(
            name: $name,
            value: $cartValue,
            currency: $this->currency(),
            properties: $properties,
        ));
    }

    public function couponApplied(string $code, bool $accepted, ?float $discount = null, ?string $reason = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: $accepted ? AnalyticsEvent::COUPON_APPLIED : AnalyticsEvent::COUPON_REJECTED,
            entityType: 'coupon',
            entityId: mb_strtoupper(mb_substr($code, 0, 40)),
            value: $discount,
            currency: $this->currency(),
            properties: $reason !== null ? ['reason' => $reason] : [],
        ));
    }

    public function paymentAttempted(string $gateway, string $outcome, float $amount, ?int $orderId = null, ?string $failureReason = null): void
    {
        $name = match ($outcome) {
            'succeeded' => AnalyticsEvent::PAYMENT_SUCCEEDED,
            'failed' => AnalyticsEvent::PAYMENT_FAILED,
            default => AnalyticsEvent::PAYMENT_STARTED,
        };

        $this->recorder->record(new AnalyticsEvent(
            name: $name,
            entityType: 'gateway',
            entityId: mb_substr($gateway, 0, 40),
            value: $amount,
            currency: $this->currency(),
            properties: array_filter([
                'order_id' => $orderId,
                // The gateway's own words, redacted on the way in like everything else.
                'reason' => $failureReason,
            ], static fn ($value) => $value !== null),
            // One payment attempt per order per gateway per outcome: a customer refreshing the
            // return-from-gateway page must not turn one payment into four.
            dedupeKey: $orderId !== null ? "payment:{$orderId}:{$gateway}:{$outcome}" : null,
        ));
    }

    /**
     * An order was placed. The single most important event in the system: it is what every
     * conversion rate, every attribution figure and every campaign's revenue is computed from.
     */
    public function orderPlaced(int $orderId, float $amount, ?int $vendorId = null, ?int $items = null, array $properties = []): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::ORDER_PLACED,
            entityType: 'order',
            entityId: (string) $orderId,
            vendorId: $vendorId,
            value: $amount,
            currency: $this->currency(),
            quantity: $items,
            properties: $properties,
            // The order id IS the identity: however many times the confirmation page is reloaded,
            // this is one sale.
            dedupeKey: "order:{$orderId}",
        ));
    }

    public function orderStatusChanged(int $orderId, string $status, ?float $amount = null): void
    {
        $name = match ($status) {
            'canceled', 'cancelled' => AnalyticsEvent::ORDER_CANCELLED,
            'delivered' => AnalyticsEvent::ORDER_DELIVERED,
            'returned', 'failed' => AnalyticsEvent::REFUND_REQUESTED,
            default => null,
        };

        if ($name === null) {
            return;
        }

        $this->recorder->record(new AnalyticsEvent(
            name: $name,
            entityType: 'order',
            entityId: (string) $orderId,
            value: $amount,
            currency: $this->currency(),
            dedupeKey: "order:{$orderId}:{$status}",
        ));
    }

    public function signedUp(int $customerId, string $method = 'form'): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::SIGNED_UP,
            entityType: 'customer',
            entityId: (string) $customerId,
            properties: ['method' => $method],
            dedupeKey: "signup:{$customerId}",
        ));
    }

    public function signedIn(int $customerId, string $method = 'password'): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::SIGNED_IN,
            entityType: 'customer',
            entityId: (string) $customerId,
            properties: ['method' => $method],
        ));
    }

    /** A line in the cart whose quantity changed. */
    public function cartUpdated(int $productId, int $quantity, ?float $value = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::CART_UPDATED,
            entityType: 'product',
            entityId: (string) $productId,
            value: $value,
            currency: $value !== null ? $this->currency() : null,
            quantity: $quantity,
        ));
    }

    /**
     * The cart page, opened.
     *
     * The middle of the funnel: how many people who filled a cart went on to look at it is the
     * question that separates "nobody adds to cart" from "everybody abandons the cart page". The
     * value is read from the cart the server already has in hand, never from the client — a figure
     * a browser could choose is not a figure.
     */
    public function cartViewed(int $items, ?float $value = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::CART_VIEWED,
            value: $value,
            currency: $value !== null ? $this->currency() : null,
            quantity: $items,
        ));
    }

    public function compareAdded(int $productId, ?int $vendorId = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::COMPARE_ADDED,
            entityType: 'product',
            entityId: (string) $productId,
            vendorId: $vendorId,
        ));
    }

    public function wishlistAdded(int $productId, ?int $vendorId = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::WISHLIST_ADDED,
            entityType: 'product',
            entityId: (string) $productId,
            vendorId: $vendorId,
        ));
    }

    public function reviewSubmitted(int $productId, int $rating, ?int $vendorId = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::REVIEW_SUBMITTED,
            entityType: 'product',
            entityId: (string) $productId,
            vendorId: $vendorId,
            quantity: $rating,
        ));
    }

    public function campaignClicked(int $campaignId, string $code): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::CAMPAIGN_CLICKED,
            entityType: 'campaign',
            entityId: (string) $campaignId,
            properties: ['code' => $code],
        ));
    }

    /**
     * An event reported by a browser or a mobile app.
     *
     * Only the names on the client allow-list are accepted, and no value the client sends is
     * trusted for money: a page that could post an order with a value of its choosing would make
     * revenue analytics a suggestion box.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fromClient(string $name, array $payload, Request $request): bool
    {
        if (!AnalyticsEvent::isClientAllowed($name)) {
            return false;
        }

        $this->recorder->record(new AnalyticsEvent(
            name: $name,
            entityType: isset($payload['entity_type']) ? mb_substr((string) $payload['entity_type'], 0, 24) : null,
            entityId: isset($payload['entity_id']) ? mb_substr((string) $payload['entity_id'], 0, 64) : null,
            properties: is_array($payload['properties'] ?? null) ? $payload['properties'] : [],
            dedupeKey: isset($payload['dedupe_key']) ? mb_substr((string) $payload['dedupe_key'], 0, 64) : null,
            path: isset($payload['path']) ? $this->paths->normalise((string) $payload['path']) : null,
        ), $request);

        return true;
    }

    public function flush(?Request $request = null): int
    {
        return $this->recorder->flush($request);
    }

    /**
     * A seller asked to withdraw from their ledger balance.
     *
     * Recorded in PayoutService, so the vendor panel and the seller app produce one row each and
     * the reference is the identity — a retried submit is still one request.
     */
    public function payoutRequested(int $sellerId, string $reference, float $amount, ?string $method = null): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::PAYOUT_REQUESTED,
            entityType: 'payout',
            entityId: $reference,
            vendorId: $sellerId,
            value: $amount,
            currency: $this->currency(),
            properties: array_filter(['method' => $method], static fn ($value) => $value !== null && $value !== ''),
            dedupeKey: "payout:{$reference}",
        ));
    }

    /** A seller submitted a KYC document for review. */
    public function kycSubmitted(int $sellerId, int $documentId, string $documentType): void
    {
        $this->recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::KYC_SUBMITTED,
            entityType: 'seller_verification_document',
            entityId: (string) $documentId,
            vendorId: $sellerId,
            properties: ['document_type' => $documentType],
            dedupeKey: "kyc:{$documentId}",
        ));
    }

    /**
     * A search term worth storing.
     *
     * Search terms are typed by customers, which means they occasionally contain a phone number, an
     * email address or a pasted password. Length is capped, digits-only strings are dropped, and
     * anything that looks like contact details never becomes a row.
     */
    private function searchTerm(string $term): ?string
    {
        $term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');

        if (mb_strlen($term) < 2 || mb_strlen($term) > 64) {
            return null;
        }

        if (preg_match('/^[\d\s+()-]+$/', $term) === 1 || filter_var($term, FILTER_VALIDATE_EMAIL) !== false) {
            return null;
        }

        return mb_strtolower($term);
    }

    private function currency(): ?string
    {
        $currency = config('currency_code') ?? getWebConfig(name: 'currency_model');

        return is_string($currency) ? mb_substr($currency, 0, 8) : null;
    }
}
