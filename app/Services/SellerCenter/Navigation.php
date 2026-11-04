<?php

namespace App\Services\SellerCenter;

use App\Services\Marketplace\SellerPrincipal;

/**
 * The Seller Center information architecture, in one place.
 *
 * Thirteen rail groups, each with its own panel items (handoff 02 §2–3). The rail, the section
 * panel, the mobile drawer, the bottom tab bar and the command palette are all renderings of this
 * one structure — a second list of destinations would drift within a week, and a destination a role
 * cannot reach must disappear from every one of them at once (handoff 11 §7 level 1).
 *
 * Navigation hiding is a courtesy, never the enforcement: every route also carries its permission
 * middleware. This registry and that middleware read the same permission keys, so the menu and the
 * server cannot disagree.
 *
 * `legacy` marks a destination that still lives in the classic vendor panel. It is listed rather
 * than hidden because a capability the seller has today must not vanish because its redesign has
 * not shipped yet (implementation brief, part 15).
 */
class Navigation
{
    /**
     * Rail groups in their fixed order.
     *
     * Each item: key, label (translation key), route (named route) or url, icon, permission,
     * badge (counts key), badgeTone, flag (module flag that must be on), legacy.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            [
                'key' => 'home',
                'label' => 'nav_home',
                'icon' => 'gauge',
                'items' => [
                    ['key' => 'home', 'label' => 'nav_seller_home', 'route' => 'seller.home'],
                    ['key' => 'control-tower', 'label' => 'nav_control_tower', 'route' => 'seller.control-tower', 'badge' => 'issues_open', 'badgeTone' => 'issues_severity'],
                    ['key' => 'actions', 'label' => 'nav_action_center', 'route' => 'seller.actions', 'badge' => 'actions_mine'],
                    // The panel's own dashboard, untouched and still where it has always been.
                    // The Seller Center home sits beside it, not over it.
                    ['key' => 'dashboard.classic', 'label' => 'nav_classic_dashboard', 'url' => 'vendor/dashboard', 'legacy' => true],
                ],
            ],
            [
                'key' => 'orders',
                'label' => 'nav_orders',
                'icon' => 'receipt',
                'permission' => 'orders.view',
                'items' => [
                    ['key' => 'orders', 'label' => 'nav_all_orders', 'route' => 'seller.orders.index', 'permission' => 'orders.view'],
                    ['key' => 'orders.ready', 'label' => 'nav_ready_to_ship', 'route' => 'seller.orders.index', 'params' => ['view' => 'ready_to_ship'], 'permission' => 'orders.view', 'badge' => 'orders_ready'],
                    ['key' => 'orders.shipped', 'label' => 'nav_shipped', 'route' => 'seller.orders.index', 'params' => ['view' => 'shipped'], 'permission' => 'orders.view'],
                    ['key' => 'orders.delivered', 'label' => 'nav_delivered', 'route' => 'seller.orders.index', 'params' => ['view' => 'delivered'], 'permission' => 'orders.view'],
                    ['key' => 'orders.cancelled', 'label' => 'nav_cancelled', 'route' => 'seller.orders.index', 'params' => ['view' => 'cancelled'], 'permission' => 'orders.view'],
                    ['key' => 'returns', 'label' => 'nav_returns', 'route' => 'seller.returns.index', 'permission' => 'orders.view', 'badge' => 'returns_open', 'badgeTone' => 'returns_severity'],
                    ['key' => 'refunds', 'label' => 'nav_refunds', 'route' => 'seller.refunds.index', 'permission' => 'orders.view'],
                    ['key' => 'messages', 'label' => 'nav_messages', 'url' => 'vendor/messages/index/customer', 'permission' => 'orders.view', 'legacy' => true],
                ],
            ],
            [
                'key' => 'catalog',
                'label' => 'nav_catalog',
                'icon' => 'tag',
                'permission' => 'products.view',
                'items' => [
                    ['key' => 'products', 'label' => 'nav_products', 'route' => 'seller.products.index', 'permission' => 'products.view'],
                    ['key' => 'products.new', 'label' => 'nav_add_product', 'url' => 'vendor/products/add', 'permission' => 'products.manage', 'legacy' => true],
                    ['key' => 'products.drafts', 'label' => 'nav_drafts', 'route' => 'seller.products.index', 'params' => ['status' => 'draft'], 'permission' => 'products.view', 'badge' => 'products_draft'],
                    ['key' => 'products.issues', 'label' => 'nav_product_issues', 'route' => 'seller.products.index', 'params' => ['issues' => 'any'], 'permission' => 'products.view', 'badge' => 'products_issues', 'badgeTone' => 'high'],
                    ['key' => 'bulk-jobs', 'label' => 'nav_bulk_operations', 'route' => 'seller.bulk-jobs.index', 'permission' => 'products.manage', 'badge' => 'bulk_running'],
                ],
            ],
            [
                'key' => 'inventory',
                'label' => 'nav_inventory',
                'icon' => 'stack',
                'permission' => 'products.view',
                'items' => [
                    ['key' => 'inventory', 'label' => 'nav_overview', 'route' => 'seller.inventory.index', 'permission' => 'products.view'],
                    ['key' => 'inventory.stock', 'label' => 'nav_stock', 'route' => 'seller.inventory.index', 'params' => ['view' => 'stock'], 'permission' => 'products.view'],
                    ['key' => 'inventory.low', 'label' => 'nav_low_stock', 'route' => 'seller.inventory.index', 'params' => ['view' => 'low_stock'], 'permission' => 'products.view', 'badge' => 'inventory_low', 'badgeTone' => 'high'],
                    ['key' => 'inventory.movements', 'label' => 'nav_movements', 'route' => 'seller.inventory.movements', 'permission' => 'products.view'],
                    ['key' => 'warehouse', 'label' => 'nav_warehouse_ops', 'route' => 'seller.warehouse.index', 'permission' => 'inventory.manage', 'flag' => 'warehouses_enabled'],
                ],
            ],
            [
                'key' => 'pricing',
                'label' => 'nav_pricing',
                'icon' => 'price-tag',
                'permission' => 'products.view',
                'items' => [
                    ['key' => 'pricing', 'label' => 'nav_pricing', 'route' => 'seller.pricing.index', 'permission' => 'products.view'],
                    ['key' => 'pricing.scheduled', 'label' => 'nav_scheduled_pricing', 'route' => 'seller.pricing.scheduled', 'permission' => 'products.view', 'badge' => 'pricing_scheduled'],
                    ['key' => 'pricing.history', 'label' => 'nav_price_history', 'route' => 'seller.pricing.history', 'permission' => 'products.view'],
                    ['key' => 'pricing.fees', 'label' => 'nav_fee_simulator', 'route' => 'seller.finance.fees', 'permission' => 'products.view'],
                ],
            ],
            [
                'key' => 'fulfilment',
                'label' => 'nav_fulfilment',
                'icon' => 'truck',
                'permission' => 'orders.view',
                'items' => [
                    ['key' => 'shipments', 'label' => 'nav_shipments', 'route' => 'seller.shipments.index', 'permission' => 'orders.view'],
                    ['key' => 'picking', 'label' => 'nav_picking', 'route' => 'seller.picking.index', 'permission' => 'orders.manage'],
                    ['key' => 'packing', 'label' => 'nav_packing', 'route' => 'seller.packing.index', 'permission' => 'orders.manage'],
                    ['key' => 'shipping', 'label' => 'nav_shipping', 'url' => 'vendor/business-settings/shipping-method/index', 'permission' => 'shop_settings.manage', 'legacy' => true],
                    ['key' => 'shipments.exceptions', 'label' => 'nav_exceptions', 'route' => 'seller.shipments.exceptions', 'permission' => 'orders.view', 'badge' => 'shipping_exceptions', 'badgeTone' => 'critical'],
                ],
            ],
            [
                'key' => 'finance',
                'label' => 'nav_finance',
                'icon' => 'bank',
                'permission' => 'finance.view',
                'items' => [
                    ['key' => 'finance', 'label' => 'nav_overview', 'route' => 'seller.finance.index', 'permission' => 'finance.view'],
                    ['key' => 'finance.transactions', 'label' => 'nav_transactions', 'route' => 'seller.finance.transactions', 'permission' => 'finance.view'],
                    ['key' => 'finance.balance', 'label' => 'nav_balance', 'route' => 'seller.finance.index', 'params' => ['view' => 'balance'], 'permission' => 'finance.view'],
                    ['key' => 'finance.payouts', 'label' => 'nav_payouts', 'route' => 'seller.finance.payouts', 'permission' => 'finance.view'],
                    ['key' => 'finance.statements', 'label' => 'nav_statements', 'route' => 'seller.finance.statements', 'permission' => 'finance.view'],
                    ['key' => 'finance.reconciliation', 'label' => 'nav_reconciliation', 'route' => 'seller.finance.reconciliation', 'permission' => 'finance.view', 'badge' => 'reconciliation_unmatched', 'badgeTone' => 'high'],
                    ['key' => 'finance.fees', 'label' => 'nav_fees', 'route' => 'seller.finance.fees', 'permission' => 'finance.view'],
                ],
            ],
            [
                'key' => 'operations',
                'label' => 'nav_operations',
                'icon' => 'activity',
                'items' => [
                    ['key' => 'issues', 'label' => 'nav_issue_center', 'route' => 'seller.issues.index', 'badge' => 'issues_open', 'badgeTone' => 'issues_severity'],
                    ['key' => 'incidents', 'label' => 'nav_incidents', 'route' => 'seller.incidents.index'],
                    ['key' => 'automation', 'label' => 'nav_automation_rules', 'route' => 'seller.automation.index', 'permission' => 'products.manage'],
                    ['key' => 'automation.scheduled', 'label' => 'nav_scheduled_ops', 'route' => 'seller.automation.scheduled', 'permission' => 'products.manage'],
                    ['key' => 'automation.history', 'label' => 'nav_automation_history', 'route' => 'seller.automation.history', 'permission' => 'products.manage'],
                    ['key' => 'opportunities', 'label' => 'nav_opportunities', 'route' => 'seller.opportunities.index', 'badge' => 'opportunities'],
                ],
            ],
            [
                'key' => 'growth',
                'label' => 'nav_growth',
                'icon' => 'chart-line-up',
                'permission' => 'promotions.manage',
                'items' => [
                    ['key' => 'campaigns', 'label' => 'nav_campaigns', 'url' => 'vendor/clearance-sale', 'permission' => 'promotions.manage', 'legacy' => true],
                    ['key' => 'coupons', 'label' => 'nav_coupons', 'url' => 'vendor/coupon/index', 'permission' => 'promotions.manage', 'legacy' => true],
                    ['key' => 'advertising', 'label' => 'nav_advertising', 'route' => 'seller.advertising.index'],
                    ['key' => 'growth.opportunities', 'label' => 'nav_opportunities', 'route' => 'seller.opportunities.index'],
                ],
            ],
            [
                'key' => 'trust',
                'label' => 'nav_trust',
                'icon' => 'seal-check',
                'items' => [
                    ['key' => 'performance', 'label' => 'nav_seller_performance', 'route' => 'seller.performance.index'],
                    ['key' => 'performance.health', 'label' => 'nav_account_health', 'route' => 'seller.performance.health'],
                    ['key' => 'performance.sla', 'label' => 'nav_sla', 'route' => 'seller.performance.sla'],
                    ['key' => 'brands', 'label' => 'nav_brand_registry', 'route' => 'seller.brands.index'],
                    ['key' => 'brands.authorization', 'label' => 'nav_brand_authorization', 'route' => 'seller.brands.index', 'params' => ['view' => 'authorization'], 'badge' => 'brands_expiring', 'badgeTone' => 'high'],
                    ['key' => 'brands.protection', 'label' => 'nav_brand_protection', 'route' => 'seller.brands.protection'],
                    ['key' => 'compliance', 'label' => 'nav_compliance', 'route' => 'seller.compliance.index', 'badge' => 'compliance_action', 'badgeTone' => 'high'],
                ],
            ],
            [
                'key' => 'platform',
                'label' => 'nav_platform',
                'icon' => 'plugs',
                'items' => [
                    ['key' => 'reports', 'label' => 'nav_reports', 'route' => 'seller.reports.index'],
                    ['key' => 'reports.builder', 'label' => 'nav_report_builder', 'route' => 'seller.reports.builder'],
                    ['key' => 'exports', 'label' => 'nav_exports', 'route' => 'seller.exports.index'],
                    ['key' => 'platform.bulk', 'label' => 'nav_bulk_operations', 'route' => 'seller.bulk-jobs.index', 'permission' => 'products.manage'],
                    ['key' => 'integrations', 'label' => 'nav_connected_apps', 'route' => 'seller.integrations.index', 'permission' => 'shop_settings.manage'],
                    ['key' => 'integrations.api', 'label' => 'nav_api', 'route' => 'seller.integrations.api', 'permission' => 'shop_settings.manage'],
                    ['key' => 'integrations.webhooks', 'label' => 'nav_webhooks', 'route' => 'seller.integrations.webhooks', 'permission' => 'shop_settings.manage', 'badge' => 'webhooks_failing', 'badgeTone' => 'critical'],
                    ['key' => 'integrations.health', 'label' => 'nav_integration_health', 'route' => 'seller.integrations.health', 'permission' => 'shop_settings.manage'],
                ],
            ],
            [
                'key' => 'organization',
                'label' => 'nav_organization',
                'icon' => 'users-three',
                'items' => [
                    ['key' => 'team', 'label' => 'nav_team', 'route' => 'seller.team.index', 'permission' => 'staff.manage'],
                    ['key' => 'team.roles', 'label' => 'nav_roles', 'route' => 'seller.team.roles', 'permission' => 'staff.manage'],
                    ['key' => 'approvals', 'label' => 'nav_approvals', 'route' => 'seller.approvals.index', 'permission' => 'staff.manage', 'badge' => 'approvals_pending'],
                    ['key' => 'audit', 'label' => 'nav_audit', 'route' => 'seller.audit.index', 'permission' => 'staff.manage'],
                    ['key' => 'security', 'label' => 'nav_security', 'route' => 'seller.security.index', 'permission' => 'staff.manage'],
                    ['key' => 'cases', 'label' => 'nav_cases', 'route' => 'seller.cases.index', 'badge' => 'cases_open'],
                    ['key' => 'appeals', 'label' => 'nav_appeals', 'route' => 'seller.appeals.index'],
                ],
            ],
        ];
    }

    /**
     * The groups this principal may see, with their unreachable items removed.
     *
     * @param  array<string, mixed>  $counts       badge values, keyed as in the registry
     * @param  array<string, bool>   $flags        module flags; an absent flag means off
     * @param  ?callable             $routeExists  seam for tests; defaults to the real route table
     * @return array<int, array<string, mixed>>
     */
    public static function for(
        ?SellerPrincipal $principal,
        array $counts = [],
        array $flags = [],
        ?callable $routeExists = null,
        ?callable $urlExists = null,
    ): array {
        $routeExists ??= static fn (string $name): bool => \Illuminate\Support\Facades\Route::has($name);
        $urlExists ??= static fn (string $path): bool => self::pathIsRouted($path);
        $visible = [];

        foreach (self::groups() as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if (isset($item['flag']) && ($flags[$item['flag']] ?? false) !== true) {
                    continue;   // a module the marketplace has not enabled is absent, not disabled
                }
                // A destination whose screen has not shipped yet is absent rather than dead. The
                // product bans dead controls, and a link that goes nowhere is one.
                if (isset($item['route']) && !$routeExists($item['route'])) {
                    continue;
                }
                // The same test for the classic panel's pages, which are named by URL rather than
                // by route. Without it a legacy link rots silently the day that page is renamed —
                // which is exactly what had happened to five of the six of them.
                if (isset($item['url']) && !$urlExists($item['url'])) {
                    continue;
                }
                if (isset($item['permission']) && $principal && !$principal->can($item['permission'])) {
                    continue;
                }
                $items[] = self::decorate($item, $counts);
            }

            if ($items === []) {
                continue;   // never render a group heading with nothing under it
            }

            $group['items'] = $items;
            $group['alert'] = self::groupAlert($items);
            $visible[] = $group;
        }

        return $visible;
    }

    /**
     * Whether the classic panel really serves this path.
     *
     * Asked of the router rather than fetched: a legacy destination is a GET page in this same
     * application, so whether it exists is something the route table already knows.
     */
    public static function pathIsRouted(string $path): bool
    {
        $request = \Illuminate\Http\Request::create('/' . trim($path, '/'), 'GET');

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            // Matched as the router would match it, not compared as a string: a page whose path
            // carries a parameter — `messages/index/{type}` — is served, and a literal comparison
            // would call it dead.
            if ($route->matches($request, includingMethod: false)) {
                return true;
            }
        }

        return false;
    }

    /** Resolve an item's href and badge once, so every renderer agrees. */
    private static function decorate(array $item, array $counts): array
    {
        $item['href'] = self::href($item);
        $item['badgeValue'] = null;
        $item['badgeToneValue'] = 'neutral';

        if (isset($item['badge'])) {
            $value = $counts[$item['badge']] ?? null;
            // Zero renders no badge — never a `0` chip (handoff 06 §6).
            if (is_numeric($value) && (int) $value > 0) {
                $item['badgeValue'] = (int) $value > 99 ? '99+' : (string) (int) $value;
                $tone = $item['badgeTone'] ?? 'neutral';
                // A tone can itself be data-driven: the issue badge takes the highest open severity.
                $item['badgeToneValue'] = $counts[$tone] ?? $tone;
            }
        }

        return $item;
    }

    private static function href(array $item): string
    {
        if (isset($item['url'])) {
            return url($item['url']);
        }

        if (isset($item['route']) && \Illuminate\Support\Facades\Route::has($item['route'])) {
            return route($item['route'], $item['params'] ?? []);
        }

        return '#';
    }

    /**
     * The rail dot. Only critical and high produce one (handoff 02 §2) — an ordinary count does not
     * earn a mark that means "something is wrong".
     */
    private static function groupAlert(array $items): ?string
    {
        $tones = array_column($items, 'badgeToneValue');

        if (in_array('critical', $tones, true)) {
            return 'critical';
        }

        return in_array('high', $tones, true) ? 'high' : null;
    }

    /**
     * Which group and item the current request is inside.
     *
     * Matched on the path rather than the route name so a saved-view query (`?view=late`) still
     * resolves to its list, and a detail page still lights its parent.
     *
     * @return array{group: ?string, item: ?string}
     */
    public static function active(string $path, array $groups): array
    {
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '', '/');
        $best = ['group' => null, 'item' => null, 'length' => -1];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $href = '/' . trim(parse_url($item['href'] ?? '', PHP_URL_PATH) ?: '', '/');
                if ($href === '/' || $href === '') {
                    continue;
                }

                $matches = $path === $href || str_starts_with($path, rtrim($href, '/') . '/');
                if ($matches && strlen($href) > $best['length']) {
                    $best = ['group' => $group['key'], 'item' => $item['key'], 'length' => strlen($href)];
                }
            }
        }

        return ['group' => $best['group'], 'item' => $best['item']];
    }
}
