<?php

namespace App\Services\Analytics\Reporting;

/**
 * The Analytics area's sections.
 *
 * Ordered by the question a merchant is actually asking, not by the shape of the data: how is the
 * shop doing, who is arriving and from where, what are they looking at, where do they stop buying,
 * and is any of this trustworthy. A section only exists here if something real can be shown in it
 * — a navigation entry that always leads to "no data" trains people to stop opening the area.
 */
class AnalyticsNavigation
{
    /** section => [label, group, hint, capability] */
    private const SECTIONS = [
        'overview' => ['label' => 'overview', 'group' => 'shop', 'hint' => 'visits_orders_and_revenue_with_the_previous_period_beside_them'],
        'live' => ['label' => 'live', 'group' => 'shop', 'hint' => 'who_is_on_the_shop_right_now_and_what_they_are_doing'],

        'acquisition' => ['label' => 'acquisition', 'group' => 'audience', 'hint' => 'where_visits_come_from_and_which_sources_actually_sell'],
        'campaigns' => ['label' => 'campaigns', 'group' => 'audience', 'hint' => 'utm_links_short_links_and_what_each_campaign_returned'],
        'audience' => ['label' => 'audience', 'group' => 'audience', 'hint' => 'devices_browsers_languages_and_new_against_returning'],
        'retention' => ['label' => 'retention', 'group' => 'audience', 'hint' => 'how_many_visitors_come_back_week_after_week'],

        'behaviour' => ['label' => 'pages', 'group' => 'behaviour', 'hint' => 'the_pages_people_land_on_read_and_leave_from'],
        'catalogue' => ['label' => 'products_and_categories', 'group' => 'behaviour', 'hint' => 'what_is_viewed_what_is_added_and_what_converts'],
        'search' => ['label' => 'search', 'group' => 'behaviour', 'hint' => 'what_customers_look_for_and_what_the_shop_does_not_stock'],
        'vendors' => ['label' => 'vendors', 'group' => 'behaviour', 'hint' => 'traffic_and_sales_by_shop'],

        'funnel' => ['label' => 'funnel', 'group' => 'commerce', 'hint' => 'from_a_visit_to_an_order_and_where_it_is_lost'],
        'revenue' => ['label' => 'revenue', 'group' => 'commerce', 'hint' => 'orders_average_order_value_and_what_each_source_earned'],
        'timing' => ['label' => 'timing', 'group' => 'commerce', 'hint' => 'the_hours_and_days_this_shop_is_actually_busy'],

        'events' => ['label' => 'event_explorer', 'group' => 'data', 'hint' => 'every_recorded_event_by_name_and_volume'],
        'journeys' => ['label' => 'journeys', 'group' => 'data', 'hint' => 'what_one_visitor_did_in_order'],
        'quality' => ['label' => 'data_quality', 'group' => 'data', 'hint' => 'is_collection_healthy_and_how_much_traffic_is_excluded'],
        'settings' => ['label' => 'analytics_settings', 'group' => 'data', 'hint' => 'exclusions_privacy_and_retention'],
    ];

    private const GROUPS = [
        'shop' => 'the_shop',
        'audience' => 'audience',
        'behaviour' => 'behaviour',
        'commerce' => 'commerce',
        'data' => 'data',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function sections(): array
    {
        return self::SECTIONS;
    }

    public static function has(string $section): bool
    {
        return isset(self::SECTIONS[$section]);
    }

    /** @return array<string, mixed> */
    public static function meta(string $section): array
    {
        return self::SECTIONS[$section] ?? self::SECTIONS['overview'];
    }

    /**
     * The rail, filtered to what this administrator may actually open.
     *
     * Hiding rather than showing-and-refusing: a menu item that always ends in "access denied" is
     * a worse experience than one that was never offered, and it leaks which features exist.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function grouped(?\App\Services\Analytics\AnalyticsPermissionService $permissions = null): array
    {
        $grouped = [];

        foreach (self::SECTIONS as $key => $meta) {
            if ($permissions !== null && !$permissions->can($permissions->capabilityForSection($key))) {
                continue;
            }

            $grouped[$meta['group']]['label'] = self::GROUPS[$meta['group']] ?? $meta['group'];
            $grouped[$meta['group']]['sections'][$key] = $meta;
        }

        return $grouped;
    }
}
