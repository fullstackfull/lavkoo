<?php

namespace App\Services\Shipping;

use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this storefront may sell into that country at all (H1-01).
 *
 * The question before every other shipping question. {@see ProductDestinationPermission} asks whether a PRODUCT may reach a country,
 * {@see \App\Services\Fulfillment\Support\ProviderRouteSupport} whether a CARRIER serves the route; neither asks whether the SHOP is
 * allowed to sell there, and a shop selling into a country it holds no registration for is the kind of mistake that is discovered by a
 * regulator rather than by a test.
 *
 * It ships CLOSED: the list of pairs is empty, so a storefront sells inside its own country and nowhere else. A source selling into its
 * own country is always open and needs no entry, because that is not a cross-border sale.
 *
 * Three things decide one pair, in this order:
 *
 *  1. `gcc.shipping.cross_border.closed` -- the kill switch. True refuses every pair, whatever anybody has written or opened. It fails
 *     CLOSED, which is why it exists separately from the engine's own switch: turning the ENGINE off stops the refusals as well, since an
 *     engine that is off answers "local, allowed" for everything.
 *  2. a row of `storefront_destination_pairs` for that exact pair -- what a person opened or closed, with their name on it
 *     ({@see CrossBorderPairs}). It wins over the configuration for that pair alone.
 *  3. `gcc.shipping.cross_border.pairs` -- what the owner wrote down before the release. `*` on a source's list means every destination.
 *
 * Reads go through the QUERY BUILDER and not a model: this is asked once per line on the checkout path, the table has no model to boot,
 * and a model read here would pick up whatever global scopes the platform adds to its models later -- which for a RULE is the wrong
 * direction, since a rule narrowed by the reader's own permissions would let a refusal depend on who is looking.
 */
final class CrossBorderPolicy
{
    public const TABLE = 'storefront_destination_pairs';

    /** A source whose list holds this sells into every destination the platform serves. */
    public const EVERY_DESTINATION = '*';

    /**
     * The reason a refused pair answers with.
     *
     * It names {@see ShippingRefusal::DESTINATION_UNKNOWN}'s key on purpose and not a key of its own: a reason has to read in Arabic and
     * in English before a customer can be shown it ({@see \Tests\Feature\Storefront\ShippingRefusalTextTest}), and the dedicated text --
     * "This store does not sell to that country" -- is not written yet. The existing key is true of a closed pair rather than merely
     * convenient: from this storefront's side a destination nobody opened is not a country it knows how to serve. Giving the refusal its
     * own key is one line here once the text exists.
     */
    public const REFUSAL = ShippingRefusal::DESTINATION_UNKNOWN;

    /** @var array<string, bool>|null the stored pairs, read once per request: a basket of twenty lines asks this twenty times */
    private static ?array $stored = null;

    /** Whether a sale from this market into that country is allowed. A sale inside one country always is. */
    public static function allows(?string $source, ?string $destination): bool
    {
        $from = DestinationCountryCode::of($source) ?? StorefrontResolver::fromConfig()->defaultCountry();
        $to = DestinationCountryCode::of($destination);

        if ($to === null) {
            return false;
        }

        if ($from === $to) {
            return true;
        }

        if (self::isClosed()) {
            return false;
        }

        return self::storedPairs()[$from . ':' . $to] ?? self::configured($from, $to);
    }

    /** The kill switch: every cross-border pair refused, whatever the lists say. */
    public static function isClosed(): bool
    {
        return (bool) config('gcc.shipping.cross_border.closed', false);
    }

    /**
     * The destinations this market may sell into, its own country included, in the platform's own order.
     *
     * What a screen offers and what an address list is judged against (A2-12), so that one reading of the rule serves the refusal and the
     * offer alike -- a chooser built from a second reading is how a customer is offered a country the checkout then refuses.
     *
     * @return list<string>
     */
    public static function destinationsOpenTo(?string $source): array
    {
        $from = DestinationCountryCode::of($source) ?? StorefrontResolver::fromConfig()->defaultCountry();
        $open = [];

        foreach (StorefrontResolver::fromConfig()->configuredCountries() as $country) {
            if (self::allows($from, $country)) {
                $open[] = $country;
            }
        }

        return $open;
    }

    /** Every pair a person has recorded, as `SOURCE:DESTINATION` => open, or an empty set before the table exists. */
    private static function storedPairs(): array
    {
        if (self::$stored !== null) {
            return self::$stored;
        }

        if (!Schema::hasTable(self::TABLE)) {
            return self::$stored = [];
        }

        $pairs = [];

        foreach (DB::table(self::TABLE)->get(['source_country', 'destination_country', 'is_open']) as $row) {
            $pairs[strtoupper((string) $row->source_country) . ':' . strtoupper((string) $row->destination_country)] = (bool) $row->is_open;
        }

        return self::$stored = $pairs;
    }

    /** What the owner wrote down: the source's own list, or its `*`. */
    private static function configured(string $source, string $destination): bool
    {
        $listed = config('gcc.shipping.cross_border.pairs.' . $source);

        if (!is_array($listed)) {
            return false;
        }

        $destinations = array_map(static fn (mixed $code): string => strtoupper(trim((string) $code)), $listed);

        return in_array($destination, $destinations, true) || in_array(self::EVERY_DESTINATION, $destinations, true);
    }

    /** Drops the per-request read of the stored pairs. Called when a pair is recorded, and by the test base between tests. */
    public static function forget(): void
    {
        self::$stored = null;
    }
}
