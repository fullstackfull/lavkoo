<?php

namespace App\Services\Retention;

use App\Models\AbandonedCartReminder;
use App\Models\Cart;
use App\Models\Order;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingConsentGate;
use App\Services\Marketing\MarketingMarket;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Selects the cart groups that are genuinely abandoned and worth an email (Phase 2.4).
 *
 * All of the difficulty is here rather than in the sending. Getting the *selection* wrong means
 * emailing somebody who already bought, emailing the same person twice, or emailing a cart whose
 * products no longer exist — and every one of those is worse than not sending at all, because it
 * arrives in a real person's inbox and cannot be taken back.
 *
 * The rules, and why each exists:
 *
 *  1. **Idle long enough.** A cart touched five minutes ago is not abandoned, it is being used.
 *  2. **Not too old.** Past the window the message is noise, and the prices in it are stale.
 *  3. **Has a real recipient.** A registered customer's account email, or — only when explicitly
 *     enabled — the address a guest typed at checkout.
 *  4. **The customer has not bought since.** `carts` rows are deleted at order time, so a surviving
 *     group is already good evidence — but `orders` carries no `cart_group_id`, so a group cannot
 *     be matched to an order directly. What it does carry is `customer_id` and `created_at`, which
 *     answers the question that actually matters: has this person ordered since they last touched
 *     this cart? If so, do not nag them.
 *  5. **Not already reminded at this stage**, which is what makes the command safe to re-run.
 *  6. **Still buyable.** A cart whose products are deactivated or deleted is a dead link; sending
 *     it invites the customer to a 404.
 *  7. **Allowed to be written to.** Only while the marketing consent gate is switched on. With it off this rule is
 *     not applied and not even asked about: no consent row is read and no market is resolved, so the recipients and
 *     the queries are exactly what they were before the gate existed.
 */
class AbandonedCartService
{
    public function __construct(private readonly MarketingConsentGate $consentGate)
    {
    }

    /**
     * Guest reminders are a separate, explicit decision, not a volume knob.
     *
     * A registered customer has an account relationship with the store. A guest who typed an email
     * into a checkout form to receive an order confirmation has not asked to be marketed to, and in
     * several jurisdictions that distinction is the difference between a transactional message and
     * an unsolicited one. So it is off unless the store owner turns it on deliberately.
     */
    public const SETTING_ENABLED = 'abandoned_cart_reminder_status';
    public const SETTING_INCLUDE_GUESTS = 'abandoned_cart_reminder_include_guests';
    public const SETTING_IDLE_HOURS = 'abandoned_cart_reminder_idle_hours';
    public const SETTING_MAX_AGE_HOURS = 'abandoned_cart_reminder_max_age_hours';
    public const SETTING_MIN_VALUE = 'abandoned_cart_reminder_minimum_cart_value';

    public const DEFAULT_IDLE_HOURS = 6;
    public const DEFAULT_MAX_AGE_HOURS = 168;   // one week

    /**
     * Is the whole feature switched on? Defaults to **off**: this sends mail to real people, so an
     * upgrade must never start doing it on its own.
     */
    public function isEnabled(): bool
    {
        return (bool) (int) ($this->setting(self::SETTING_ENABLED) ?? 0);
    }

    public function includesGuests(): bool
    {
        return (bool) (int) ($this->setting(self::SETTING_INCLUDE_GUESTS) ?? 0);
    }

    public function idleHours(): int
    {
        return max(1, (int) ($this->setting(self::SETTING_IDLE_HOURS) ?: self::DEFAULT_IDLE_HOURS));
    }

    public function maxAgeHours(): int
    {
        return max($this->idleHours() + 1, (int) ($this->setting(self::SETTING_MAX_AGE_HOURS) ?: self::DEFAULT_MAX_AGE_HOURS));
    }

    public function minimumCartValue(): float
    {
        return max(0, (float) ($this->setting(self::SETTING_MIN_VALUE) ?? 0));
    }

    /**
     * The cart groups eligible for a reminder at the given stage, newest abandonment first.
     *
     * @return Collection<int, array{cart_group_id: string, customer_id: int|null, is_guest: bool, email: string, market: string|null, cart_value: float, item_count: int, last_activity: Carbon}>
     */
    public function findAbandoned(int $stage = 1, ?Carbon $now = null): Collection
    {
        if (!Schema::hasTable('carts')) {
            return collect();
        }

        $now = $now ? $now->copy() : Carbon::now();
        $idleBefore = $now->copy()->subHours($this->idleHours());
        $olderThan = $now->copy()->subHours($this->maxAgeHours());

        // Only lines whose product is still active and orderable. A reminder pointing at a
        // deactivated product is worse than silence.
        $rows = Cart::query()
            ->whereHas('product', fn ($query) => $query->active())
            ->where('updated_at', '<=', $idleBefore)
            ->where('updated_at', '>=', $olderThan)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $alreadySent = AbandonedCartReminder::query()
            ->where('stage', $stage)
            ->whereIn('cart_group_id', $rows->pluck('cart_group_id')->unique()->all())
            ->pluck('cart_group_id')
            ->flip();

        $minimum = $this->minimumCartValue();
        $includeGuests = $this->includesGuests();

        // Asked once, not once per cart: with the gate off the closure below never touches consent at all.
        $consentRequired = $this->consentGate->enabled();

        return $rows->groupBy('cart_group_id')
            ->map(function (Collection $lines, string $groupId) use ($alreadySent, $minimum, $includeGuests, $consentRequired) {
                if ($alreadySent->has($groupId)) {
                    return null;
                }

                $first = $lines->first();
                $isGuest = (bool) $first->is_guest;

                if ($isGuest && !$includeGuests) {
                    return null;
                }

                $email = $this->recipientFor($first->customer_id, $isGuest);
                if (!$email) {
                    return null;
                }

                $lastActivity = $lines->max('updated_at');
                if ($this->hasOrderedSince($first->customer_id, $isGuest, $lastActivity)) {
                    return null;
                }

                // The cart's own stored price, not a live recomputation: the email quotes what the
                // customer saw, and the checkout will price it again for real anyway.
                $value = (float) $lines->sum(fn ($line) => ((float) $line->price - (float) $line->discount) * (int) $line->quantity);
                if ($value <= 0 || $value < $minimum) {
                    return null;
                }

                // The market is resolved ONCE, here, and travels with the row: the gate's answer and the unsubscribe
                // link in the message have to be about the same market, and the reminder row cannot resolve it again
                // because it does not keep a guest's id. Null while the switch is off, where nothing resolves one.
                $market = $consentRequired ? $this->marketOf(customerId: $first->customer_id, isGuest: $isGuest) : null;

                if ($market !== null && !$this->mayRemind(market: $market, customerId: $first->customer_id, isGuest: $isGuest, email: $email)) {
                    return null;
                }

                return [
                    'cart_group_id' => $groupId,
                    'customer_id' => $isGuest ? null : (int) $first->customer_id,
                    'is_guest' => $isGuest,
                    'email' => $email,
                    'market' => $market,
                    'cart_value' => round($value, 2),
                    'item_count' => (int) $lines->sum('quantity'),
                    'last_activity' => $lastActivity,
                ];
            })
            ->filter()
            ->sortByDesc('last_activity')
            ->values();
    }

    /** The market this cart's owner belongs to: a guest's own typed address counts as much as a customer's. */
    private function marketOf($customerId, bool $isGuest): string
    {
        return MarketingMarket::forCustomer(
            customerId: $customerId ? (int) $customerId : null,
            isGuest: $isGuest,
        );
    }

    /**
     * Has this person agreed to a cart reminder by e-mail in that market?
     *
     * A registered customer is asked about by account, so the answer follows them whatever address they used. A guest
     * has no account, so the address they typed is the subject — which is also the only thing an unsubscribe link in
     * that message can name.
     */
    private function mayRemind(string $market, $customerId, bool $isGuest, string $email): bool
    {
        if ($isGuest || !$customerId) {
            return $this->consentGate->allowsEmail(
                email: $email,
                market: $market,
                channel: MarketingConsent::CHANNEL_EMAIL,
                purpose: MarketingConsent::PURPOSE_ABANDONED_CART,
            );
        }

        return $this->consentGate->allowsCustomer(
            customerId: (int) $customerId,
            market: $market,
            channel: MarketingConsent::CHANNEL_EMAIL,
            purpose: MarketingConsent::PURPOSE_ABANDONED_CART,
        );
    }

    /**
     * Where a reminder for this cart should go, or null when there is nobody to write to.
     */
    private function recipientFor($customerId, bool $isGuest): ?string
    {
        if (!$customerId) {
            return null;
        }

        if (!$isGuest) {
            $email = User::where('id', $customerId)->value('email');

            return $this->validEmail($email);
        }

        if (!Schema::hasTable('shipping_addresses')) {
            return null;
        }

        // A guest only has an address if they got as far as the checkout form. Reaching that far
        // and stopping is exactly the cart worth recovering — but see SETTING_INCLUDE_GUESTS.
        $email = ShippingAddress::where(['customer_id' => $customerId, 'is_guest' => 1])
            ->whereNotNull('email')
            ->latest('id')
            ->value('email');

        return $this->validEmail($email);
    }

    private function validEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Has this customer ordered since they last touched the cart?
     *
     * Cart rows are deleted at order time, so a surviving group is usually proof enough on its own.
     * This is the backstop for a row left behind, because sending "you forgot something" to
     * somebody who has just paid is the single worst outcome the feature can produce.
     *
     * `orders` has no `cart_group_id`, so the match is by customer and time rather than by group.
     * Guests are keyed by the same `customer_id` column, which for them holds the guest id — hence
     * `is_guest` being part of the match rather than a separate branch.
     */
    private function hasOrderedSince($customerId, bool $isGuest, $lastActivity): bool
    {
        if (!$customerId || !Schema::hasTable('orders')) {
            return false;
        }

        return Order::where('customer_id', $customerId)
            ->where('is_guest', $isGuest ? 1 : 0)
            ->where('created_at', '>=', $lastActivity)
            ->exists();
    }

    /**
     * Reads a business setting without assuming the table or the row exists — this runs from a
     * console command, which may execute before any of it has been configured.
     */
    private function setting(string $type): mixed
    {
        try {
            return getWebConfig(name: $type);
        } catch (\Throwable) {
            return null;
        }
    }
}
