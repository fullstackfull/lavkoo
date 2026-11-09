<?php

namespace App\Services\SalesChannels\Support;

use InvalidArgumentException;

/**
 * The non-secret settings each channel accepts, validated and completed with the defaults. Unknown keys are dropped (never stored), and a value of the wrong
 * kind is refused rather than coerced into something the connector then trusts.
 */
final class ChannelSettings
{
    public const AMAZON_REGIONS = ['na', 'eu', 'fe'];
    public const TRENDYOL_STOREFRONTS = ['SA', 'AE', 'KW'];
    /** unset: nobody said (nothing is ever created); app: this application creates the shipments; provider_direct: the provider imports the channel's orders itself (Trendyol connected straight to Torod), so the application only follows them. */
    public const SHIPMENT_CREATION = ['unset', 'app', 'provider_direct'];
    public const DISPATCH_MODES = ['manual', 'auto'];

    /** @return array<string, mixed> */
    public static function defaults(string $channel): array
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

        if ($channel === 'trendyol') {
            $storefront = strtoupper(trim((string) $merged['storefront_code']));
            if ($storefront !== '' && !in_array($storefront, self::TRENDYOL_STOREFRONTS, true)) {
                throw new InvalidArgumentException('The storefront code must be one of: ' . implode(', ', self::TRENDYOL_STOREFRONTS) . ', or empty for the Turkish marketplace.');
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

        return $merged;
    }
}
