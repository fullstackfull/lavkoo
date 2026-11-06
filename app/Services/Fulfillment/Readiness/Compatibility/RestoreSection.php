<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

/**
 * Was the dump restored completely? The application boots from the database (settings, currencies, the in-house shop, the
 * storefront's banners and categories) inside a block that swallows errors, so a partial restore does not fail loudly: it
 * serves pages with an undefined configuration until the caches are cleared. This lists what must be there before the first
 * process starts, and which of the tables the platform's own code uses, but no migration in this repository creates, the
 * dump actually carries (migrate will NOT create them).
 */
final class RestoreSection implements CompatibilitySection
{
    private const SECTION = 'restore';

    /** Without these the storefront or the admin panel cannot boot. */
    private const BOOT_TABLES = [
        'business_settings', 'currencies', 'login_setups', 'addon_settings', 'social_media', 'shops', 'sellers', 'products', 'categories',
        'brands', 'banners', 'flash_deals', 'business_pages', 'robots_meta_contents', 'analytic_scripts', 'stock_clearance_setups',
        'stock_clearance_products',
    ];

    /** Tables the platform's custom code reads or writes that no migration here creates: they come from the dump. */
    private const TABLES_OUTSIDE_THE_MIGRATIONS = [
        'telemetry_requests', 'telemetry_daily', 'visit_sessions', 'vendor_ledger_entries', 'vendor_settlements', 'vendor_payout_requests',
        'redirects', 'seo_templates', 'product_search_index', 'product_metrics', 'product_collections', 'stock_movements', 'warehouses',
        'warehouse_stock', 'suppliers', 'purchase_orders', 'shipping_zones', 'commission_rules', 'payment_routing_rules', 'audit_logs',
        'abandoned_cart_reminders', 'delivery_syria_parcels', 'order_fulfillments', 'return_shipments', 'exchange_rate_logs', 'api_snapshots',
        'seller_webhooks', 'seller_webhook_deliveries', 'zender_settings', 'notification_template_mappings', 'monitoring_alert_rules',
    ];

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        $missingBoot = array_values(array_filter(self::BOOT_TABLES, static fn (string $table): bool => !$probe->hasTable($table)));
        $present = array_values(array_filter(self::TABLES_OUTSIDE_THE_MIGRATIONS, static fn (string $table): bool => $probe->hasTable($table)));
        $missingOutside = array_values(array_diff(self::TABLES_OUTSIDE_THE_MIGRATIONS, $present));

        return array_values(array_filter([
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'tables the application boots from',
                status: $missingBoot === [] ? CompatibilityCheck::PASS : CompatibilityCheck::FAIL,
                detail: $missingBoot === []
                    ? 'all ' . count(self::BOOT_TABLES) . ' boot-critical tables exist'
                    : count($missingBoot) . ' boot-critical table(s) are missing: ' . implode(', ', $missingBoot) . '. The restore is partial, and the application would fail silently into undefined-configuration errors; restore the whole dump first and never run php artisan migrate to fill the gap.',
                facts: ['checked' => count(self::BOOT_TABLES), 'missing' => $missingBoot],
            ),
            $this->emptyBootTables($probe),
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'tables created outside the migrations',
                status: $missingOutside === [] ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
                detail: $missingOutside === []
                    ? 'all ' . count(self::TABLES_OUTSIDE_THE_MIGRATIONS) . ' tables that only the dump creates are present'
                    : count($missingOutside) . ' of ' . count(self::TABLES_OUTSIDE_THE_MIGRATIONS) . ' tables that only the dump creates are missing: ' . implode(', ', $missingOutside) . '. php artisan migrate will not create them; features that use them will fail or stay empty (the owner should confirm they exist in production).',
                facts: ['expected' => count(self::TABLES_OUTSIDE_THE_MIGRATIONS), 'present' => count($present), 'missing' => $missingOutside],
            ),
        ]));
    }

    private function emptyBootTables(ReadOnlyProbe $probe): ?CompatibilityCheck
    {
        $mustHaveRows = ['business_settings', 'currencies', 'shops'];
        $empty = array_values(array_filter($mustHaveRows, static fn (string $table): bool => $probe->hasTable($table) && $probe->count($table) === 0));
        $inHouseShop = $probe->hasTable('shops') && isset($probe->columns('shops')['author_type']) && $probe->count('shops', static fn ($query) => $query->where('author_type', 'admin')) === 0;
        $problems = [...array_map(static fn (string $table): string => "{$table} has no rows", $empty), ...($inHouseShop ? ['there is no in-house (admin) shop row: the first request would create one'] : [])];

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'boot tables that must hold data',
            status: $problems === [] ? CompatibilityCheck::PASS : CompatibilityCheck::FAIL,
            detail: $problems === [] ? 'business_settings, currencies and the in-house shop hold rows' : implode('; ', $problems) . '. A restore that ends here would be written to by the first request.',
            facts: ['empty' => $empty, 'in_house_shop_missing' => $inHouseShop],
        );
    }
}
