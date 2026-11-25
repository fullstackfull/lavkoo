<?php

namespace App\Services\Notification;

use App\Models\Cart;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/**
 * Which market's abandoned carts the WhatsApp cart-marketing screen works on (I1-04).
 *
 * The screen listed every registered customer's cart on the platform and offered one button that messaged whoever was ticked. For one market
 * that is right. For six it is wrong in both directions: a Kuwaiti operator is shown Saudi carts whose totals are in another currency and whose
 * products their market does not sell, and a template written for Kuwait reaches a Saudi customer who cannot act on it.
 *
 * So the screen names the market it is working on, and the market it is working on is the one thing the list and the send agree about. Both ask
 * here, because a list narrowed to Kuwait over a send that is not narrowed at all is a screen that shows one thing and does another -- the form
 * carries ids, and an id the list never showed is one `curl` away.
 *
 * **No market chosen means every market the operator may reach**, which for an unrestricted operator is the platform and is exactly the screen
 * of today. For a RESTRICTED operator it is their own markets and never the platform, for the reason {@see BroadcastMarkets} spells out: a form
 * value must not be able to widen a reach their scope does not grant.
 *
 * A cart group whose rows name no country at all is left where it has always been: visible to an operator who may reach every market, and out of
 * a narrowed list, because nothing says which market it belongs to and guessing one is how a Saudi cart ends up in a Kuwaiti campaign.
 */
#[Singleton]
final class CartMarketingMarkets
{
    /** Whether a cart can be placed in a market at all: storefronts are on and the products carry their country. */
    public function applies(): bool
    {
        return StorefrontCatalog::tracksProductCountry();
    }

    /**
     * The markets an operator may work on, narrowed to their own.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        if (!$this->applies()) {
            return [];
        }

        $configured = StorefrontResolver::fromConfig()->configuredCountries();
        $allowed = $this->scope();

        return $allowed === null ? $configured : array_values(array_intersect($configured, $allowed));
    }

    /** Whether this operator may work on every market's carts at once. A restricted operator may not. */
    public function mayChooseEveryMarket(): bool
    {
        return $this->scope() === null;
    }

    /** Whether this operator may use the screen at all: one whose scope grants no market can reach nobody. */
    public function mayMarket(): bool
    {
        return !$this->applies() || $this->mayChooseEveryMarket() || $this->choices() !== [];
    }

    /**
     * The market the screen is showing, as the chooser should render it: the one asked for when the operator may reach it, else none.
     */
    public function chosen(mixed $asked): ?string
    {
        $asked = is_string($asked) ? strtoupper(trim($asked)) : '';

        return $asked !== '' && in_array($asked, $this->choices(), true) ? $asked : null;
    }

    /**
     * The markets a list or a send may actually reach, which is the one answer the two share.
     *
     * Null means "narrow nothing", and it is returned ONLY while markets do not apply or for an operator who may reach every market and has
     * chosen none -- the screen of today. A restricted operator always gets a list of their own markets, never null.
     *
     * @return list<string>|null
     */
    public function inForce(mixed $asked): ?array
    {
        if (!$this->applies()) {
            return null;
        }

        $chosen = $this->chosen($asked);

        if ($chosen !== null) {
            return [$chosen];
        }

        return $this->mayChooseEveryMarket() ? null : $this->choices();
    }

    /**
     * The carts of these markets. A group is kept when every country its rows name is one of them, so a legacy group that spans two markets
     * (CartManager::cartGroupsSharedWithOtherStorefronts) is not half-messaged, and a group that names no country is kept only when nothing is
     * being narrowed.
     *
     * @param Collection<int, Cart> $carts
     * @param list<string>|null $markets
     * @return Collection<int, Cart>
     */
    public function within(Collection $carts, ?array $markets): Collection
    {
        if ($markets === null || $carts->isEmpty()) {
            return $carts;
        }

        $countries = StorefrontCatalog::countriesOfCartGroups(
            cartGroupIds: $carts->pluck('cart_group_id')->unique()->values()->all(),
        );

        return $carts->filter(function (Cart $cart) use ($countries, $markets): bool {
            $named = array_values(array_filter($countries[(string) $cart->cart_group_id] ?? [], static fn (string $country): bool => $country !== ''));

            return $named !== [] && array_diff($named, $markets) === [];
        })->values();
    }

    /** @return list<string>|null null when the operator is not restricted */
    private function scope(): ?array
    {
        return app(StaffCountryScope::class)->countriesOf(admin: auth('admin')->user());
    }
}
