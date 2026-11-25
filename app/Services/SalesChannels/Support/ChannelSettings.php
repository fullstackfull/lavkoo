<?php

namespace App\Services\SalesChannels\Support;

use App\Services\Storefront\StorefrontResolver;
use InvalidArgumentException;

/**
 * The non-secret settings each channel accepts, validated and completed with the defaults. Unknown keys are dropped (never stored), and a value of the wrong
 * kind is refused rather than coerced into something the connector then trusts.
 */
final class ChannelSettings
{
    public const AMAZON_REGIONS = ['na', 'eu', 'fe'];

    /**
     * The storefront country an account DECLARES it sells in (H2-06, M-744).
     *
     * Until this, the country of a channel account was only ever DERIVED from whichever setting each channel happens to keep it under --
     * Trendyol's `storefront_code`, noon's `country_code`, the countries of an Amazon account's marketplace ids -- and nothing validated the
     * answer against anything. So an account of two countries' marketplaces, or of a marketplace this platform does not know, had no
     * country at all and was therefore narrowed to no market, which is the one state in which two countries' accounts can meet in one stock
     * pool. This is the account SAYING which storefront it sells in, and {@see ChannelAccountCountry} prefers it to the derived answer.
     *
     * An empty declaration says nothing and changes nothing: the derived answer stands, which is today's behaviour and the behaviour of
     * every account that exists. A declaration that CONTRADICTS the account's own marketplace is refused outright -- an Amazon account of
     * the Emirati marketplace cannot declare the Saudi storefront -- because such an account would otherwise be given a market's products
     * to publish on another market's marketplace, and the figures would add up.
     */
    public const MARKET = 'storefront_country';

    /**
     * The Gulf storefronts a Trendyol account may name.
     *
     * The list it shipped with, kept as the DEFAULT and no longer as the answer: `sales_channels.markets.trendyol.serves` is the answer
     * (H2-07), so opening Qatar is a list to edit rather than a constant to change, and the configuration also records where the list came
     * from and that nobody has checked it against Trendyol's own documentation.
     */
    public const TRENDYOL_STOREFRONTS = ['SA', 'AE', 'KW'];
    /**
     * The countries a noon account may name.
     *
     * noon's own notes in this integration name three, and `country_code` was validated against NOTHING: the default `sa` was accepted,
     * anything else was stored as typed, and `NoonConnector::publishPrices()` refused only an EMPTY value -- so a typo became a price
     * published against a country noon does not serve, or against no country at all. The configuration is the answer
     * (`sales_channels.markets.noon.serves`); this is what it falls back to.
     */
    public const NOON_COUNTRIES = ['SA', 'AE', 'EG'];

    /** unset: nobody said (nothing is ever created); app: this application creates the shipments; provider_direct: the provider imports the channel's orders itself (Trendyol connected straight to Torod), so the application only follows them. */
    public const SHIPMENT_CREATION = ['unset', 'app', 'provider_direct'];
    public const DISPATCH_MODES = ['manual', 'auto'];

    /**
     * The markets a channel serves, from the configuration, falling back to the list the integration shipped with (H2-07).
     *
     * @return list<string>
     */
    public static function marketsOf(string $channel): array
    {
        $listed = config('sales_channels.markets.' . $channel . '.serves');
        $codes = [];

        foreach (is_array($listed) ? $listed : self::shippedMarketsOf($channel) as $code) {
            $code = strtoupper(trim((string) $code));

            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** Whether anybody has checked a channel's market list against the vendor's own documentation. False everywhere, honestly. */
    public static function marketsAreVerified(string $channel): bool
    {
        return (bool) config('sales_channels.markets.' . $channel . '.verified', false);
    }

    /** @return list<string> */
    private static function shippedMarketsOf(string $channel): array
    {
        return match ($channel) {
            'trendyol' => self::TRENDYOL_STOREFRONTS,
            'noon' => self::NOON_COUNTRIES,
            default => [],
        };
    }

    /**
     * Whether an account may declare its storefront country at all. The switch alone, because the declaration lives in the account's own
     * settings and not in a column: `StorefrontMarketRows` answers for a TABLE's market, and there is no table here to ask about.
     */
    public static function marketMayBeDeclared(string $channel): bool
    {
        return (bool) config('gcc.storefronts.enabled', false) && self::shippedDefaults($channel) !== [];
    }

    /** @return array<string, mixed> */
    public static function defaults(string $channel): array
    {
        return self::shippedDefaults($channel) + (self::marketMayBeDeclared($channel) ? [self::MARKET => ''] : []);
    }

    /** @return array<string, mixed> */
    private static function shippedDefaults(string $channel): array
    {
        return match ($channel) {
            'amazon' => [
                'region' => 'eu',
                'marketplace_ids' => [],
                'sync_fba_inventory' => true,
                'request_buyer_data' => false,
                'auto_link_exact_sku' => false,
                'route_cod_orders' => false,
                'shipment_creation' => 'unset',
                'dispatch_mode' => 'manual',
            ],
            'trendyol' => [
                'storefront_code' => 'SA',
                'integration_name' => 'SelfIntegration',
                'accept_language' => 'ar',
                'auto_link_exact_barcode' => false,
                'prices_include_tax' => true,
                'route_cod_orders' => false,
                // An owner's decision (off by default): the units of a claim the channel accepted go back on the shelf by themselves (ReturnDecisionService).
                'auto_restock_accepted_returns' => false,
                'torod_reference_field' => 'order_number',
                'carrier_codes' => [],
                'shipment_creation' => 'unset',
                'dispatch_mode' => 'manual',
            ],
            'noon' => [
                // The sandbox is a drop-in replacement for every call but the sign-in, which always goes to production.
                'use_sandbox' => false,
                // noon addresses stock by a (warehouse, partner_sku) pair, so a default warehouse is what a one-figure-per-product view would mean here. Empty until the
                // warehouses can be listed, which needs a request shape noon does not publish.
                'warehouse_code' => '',
                // A noon price belongs to a COUNTRY (`ae`, `sa`, `eg`), not to an account: the same SKU carries a different price per marketplace.
                'country_code' => 'sa',
                // FBN units sit in noon's own fulfilment centre: they are noon's stock, not ours, and must never join the shared pool. Off, and here to be read rather than
                // assumed, exactly as Amazon's `sync_fba_inventory` is.
                'sync_fbn_inventory' => false,
                'route_cod_orders' => false,
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate(string $channel, array $input): array
    {
        $merged = array_replace(self::defaults($channel), array_intersect_key($input, self::defaults($channel)));

        foreach (['sync_fba_inventory', 'sync_fbn_inventory', 'use_sandbox', 'request_buyer_data', 'auto_link_exact_sku', 'auto_link_exact_barcode', 'prices_include_tax', 'route_cod_orders', 'auto_restock_accepted_returns'] as $flag) {
            if (array_key_exists($flag, $merged)) {
                $merged[$flag] = filter_var($merged[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('shipment_creation', $merged)) {
            $allowed = $channel === 'trendyol' ? self::SHIPMENT_CREATION : ['unset', 'app'];
            if (!in_array($merged['shipment_creation'], $allowed, true)) {
                throw new InvalidArgumentException('Who creates the shipments of this account must be one of: ' . implode(', ', $allowed) . '.');
            }
            if (!in_array($merged['dispatch_mode'], self::DISPATCH_MODES, true)) {
                throw new InvalidArgumentException('The dispatch mode must be manual or auto.');
            }
        }

        if ($channel === 'amazon') {
            if (!in_array($merged['region'], self::AMAZON_REGIONS, true)) {
                throw new InvalidArgumentException('The Amazon region must be one of: ' . implode(', ', self::AMAZON_REGIONS) . '.');
            }
            $ids = array_values(array_unique(array_filter(array_map(static fn ($id): string => strtoupper(trim((string) $id)), (array) $merged['marketplace_ids']))));
            foreach ($ids as $id) {
                if (preg_match('/^[A-Z0-9]{8,16}$/', $id) !== 1) {
                    throw new InvalidArgumentException("'{$id}' is not a marketplace id.");
                }
            }
            $merged['marketplace_ids'] = $ids;
        }

        if ($channel === 'noon') {
            // Lower case, because that is how noon writes a country and how every call of this connector sends it; the list is compared
            // in upper case, as every market code on this platform is.
            $country = strtolower(trim((string) $merged['country_code']));
            $serves = self::marketsOf('noon');
            if (!in_array(strtoupper($country), $serves, true)) {
                throw new InvalidArgumentException('The noon country must be one of: ' . implode(', ', array_map('strtolower', $serves)) . '.');
            }
            $merged['country_code'] = $country;
        }

        if ($channel === 'trendyol') {
            $storefront = strtoupper(trim((string) $merged['storefront_code']));
            $serves = self::marketsOf('trendyol');
            if ($storefront !== '' && !in_array($storefront, $serves, true)) {
                throw new InvalidArgumentException('The storefront code must be one of: ' . implode(', ', $serves) . ', or empty for the Turkish marketplace.');
            }
            $merged['storefront_code'] = $storefront;
            $name = trim((string) $merged['integration_name']);
            if (preg_match('/^[A-Za-z0-9]{1,30}$/', $name) !== 1) {
                throw new InvalidArgumentException('The integration name is letters and digits only, 30 at most (Trendyol puts it in the User-Agent).');
            }
            $merged['integration_name'] = $name;
            if (!in_array($merged['accept_language'], ['ar', 'en', 'tr'], true)) {
                throw new InvalidArgumentException('The response language must be ar, en or tr.');
            }
            if (!in_array($merged['torod_reference_field'], ['order_number', 'package_id', 'tracking_number'], true)) {
                throw new InvalidArgumentException('The Torod reference field must be order_number, package_id or tracking_number.');
            }
            $merged['carrier_codes'] = array_map(static fn ($code): string => trim((string) $code), array_filter((array) $merged['carrier_codes'], static fn ($code): bool => trim((string) $code) !== ''));
        }

        if (array_key_exists(self::MARKET, $merged)) {
            $merged[self::MARKET] = self::validatedMarket($channel, $merged);
        }

        return $merged;
    }

    /**
     * The declared storefront country, checked against the platform's storefronts and against the marketplace the account's own settings
     * name. Empty is kept empty: an account that declares nothing is left to the derived answer.
     *
     * @param array<string, mixed> $merged the settings as they have just been normalised, so the marketplace fields read as they will be stored
     */
    private static function validatedMarket(string $channel, array $merged): string
    {
        $declared = strtoupper(trim((string) $merged[self::MARKET]));

        if ($declared === '') {
            return '';
        }

        $configured = StorefrontResolver::fromConfig()->configuredCountries();

        if (!in_array($declared, $configured, true)) {
            throw new InvalidArgumentException('The storefront country must be one of: ' . implode(', ', $configured) . ', or empty.');
        }

        foreach (self::marketplaceCountriesOf($channel, $merged) as $marketplace => $country) {
            if ($country !== $declared) {
                throw new InvalidArgumentException("This account's marketplace {$marketplace} sells in {$country}, so it cannot declare the {$declared} storefront.");
            }
        }

        return $declared;
    }

    /**
     * The country of each marketplace the account's own settings name, as far as this platform knows them.
     *
     * A marketplace whose country is UNKNOWN is left out rather than refused: a new Amazon marketplace id is a fact about Amazon's
     * catalogue, not a contradiction, and refusing it would stop an account the day Amazon opens a marketplace we have not listed yet.
     *
     * @param array<string, mixed> $merged
     * @return array<string, string> country by marketplace, as a person would name it
     */
    private static function marketplaceCountriesOf(string $channel, array $merged): array
    {
        $named = [];

        if ($channel === 'noon') {
            $named['noon ' . strtolower((string) $merged['country_code'])] = strtoupper((string) $merged['country_code']);
        }

        if ($channel === 'trendyol') {
            // Empty is not silence here: Trendyol's own setting means the TURKISH marketplace, which this platform has no storefront for.
            $code = (string) $merged['storefront_code'];
            $named[$code === '' ? 'Trendyol Turkey' : 'Trendyol ' . $code] = $code === '' ? 'TR' : $code;
        }

        if ($channel === 'amazon') {
            foreach ((array) $merged['marketplace_ids'] as $id) {
                $country = ChannelAccountCountry::countryOfMarketplace((string) $id);

                if ($country !== '') {
                    $named[(string) $id] = $country;
                }
            }
        }

        return $named;
    }
}
