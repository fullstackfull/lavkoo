<?php

namespace App\Services\DeveloperPortal;

/**
 * The portal's sections, and which of them are worth showing on THIS installation.
 *
 * A developer platform with thirty-five navigation entries, twenty of which lead to "not
 * configured", is worse than one with fifteen that all lead somewhere. So a section declares what
 * it needs, and one that needs something this shop does not have is either hidden or shown once
 * with a plain explanation of what would switch it on — never as an empty page with a spinner.
 */
class PortalNavigation
{
    /**
     * section key => [label, group, hint, requires]
     *
     * `requires` is a capability name checked against the live system, not a feature flag: the
     * webhooks section appears when outbound webhooks exist, not when somebody remembered to
     * enable it.
     */
    private const SECTIONS = [
        // Start here.
        'overview' => ['label' => 'overview', 'group' => 'start', 'hint' => 'the_api_at_a_glance_and_where_to_begin'],
        'quick_start' => ['label' => 'quick_start', 'group' => 'start', 'hint' => 'credentials_first_call_errors_and_going_live'],

        // The reference.
        'explorer' => ['label' => 'api_explorer', 'group' => 'reference', 'hint' => 'every_endpoint_read_live_from_the_route_table'],
        'customer_app' => ['label' => 'customer_app_apis', 'group' => 'reference', 'hint' => 'what_the_shopper_app_calls'],
        'vendor_app' => ['label' => 'vendor_app_apis', 'group' => 'reference', 'hint' => 'what_the_seller_app_calls'],
        'delivery_app' => ['label' => 'delivery_app_apis', 'group' => 'reference', 'hint' => 'what_the_delivery_app_calls'],
        'partner' => ['label' => 'partner_apis', 'group' => 'reference', 'hint' => 'endpoints_an_outside_integration_may_use'],
        'models' => ['label' => 'models_and_enums', 'group' => 'reference', 'hint' => 'the_shapes_and_the_allowed_values_behind_them'],

        // The conventions every endpoint shares.
        'authentication' => ['label' => 'authentication', 'group' => 'conventions', 'hint' => 'the_token_types_this_api_issues_and_what_each_one_opens'],
        'errors' => ['label' => 'errors', 'group' => 'conventions', 'hint' => 'the_error_envelope_and_what_each_status_means_here'],
        'rate_limits' => ['label' => 'rate_limits', 'group' => 'conventions', 'hint' => 'the_limits_actually_configured_on_the_routes'],
        'pagination' => ['label' => 'pagination', 'group' => 'conventions', 'hint' => 'how_lists_are_paged_in_this_api'],
        'uploads' => ['label' => 'file_uploads', 'group' => 'conventions', 'hint' => 'sizes_types_and_the_multipart_format'],

        // Change management.
        'versions' => ['label' => 'versions', 'group' => 'change', 'hint' => 'what_each_version_carries_and_who_still_calls_it'],
        'changelog' => ['label' => 'api_changelog', 'group' => 'change', 'hint' => 'generated_from_snapshot_diffs_not_written_by_hand'],
        'deprecations' => ['label' => 'deprecations', 'group' => 'change', 'hint' => 'what_is_going_away_when_and_what_replaces_it'],

        // Tools.
        'openapi' => ['label' => 'openapi', 'group' => 'tools', 'hint' => 'download_the_generated_spec'],
        'postman' => ['label' => 'postman', 'group' => 'tools', 'hint' => 'download_a_generated_collection'],
        'console' => ['label' => 'api_console', 'group' => 'tools', 'hint' => 'send_a_real_request_and_see_the_real_response'],
        'debugger' => ['label' => 'request_debugger', 'group' => 'tools', 'hint' => 'look_up_a_request_id_and_see_what_happened'],

        // Operations.
        'health' => ['label' => 'api_health', 'group' => 'operations', 'hint' => 'traffic_errors_and_latency_per_endpoint'],
        'webhooks' => ['label' => 'webhooks', 'group' => 'operations', 'hint' => 'inbound_and_outbound_event_delivery', 'requires' => 'webhooks'],
        'integrations' => ['label' => 'integrations', 'group' => 'operations', 'hint' => 'the_external_services_this_shop_depends_on'],
        'quality' => ['label' => 'documentation_quality', 'group' => 'operations', 'hint' => 'what_is_undocumented_and_what_is_unclassified'],
        'settings' => ['label' => 'portal_settings', 'group' => 'operations', 'hint' => 'visibility_versions_and_snapshots'],
    ];

    private const GROUPS = [
        'start' => 'getting_started',
        'reference' => 'api_reference',
        'conventions' => 'conventions',
        'change' => 'change_management',
        'tools' => 'tools',
        'operations' => 'operations',
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
     * The navigation, grouped, with sections this installation cannot serve marked rather than
     * silently dropped — a developer looking for webhooks should learn that there are none, not
     * be left wondering where the menu item went.
     *
     * @param  array<string, bool>  $capabilities
     * @return array<string, array<string, mixed>>
     */
    public static function grouped(array $capabilities = []): array
    {
        $grouped = [];

        foreach (self::SECTIONS as $key => $meta) {
            $requirement = $meta['requires'] ?? null;
            $available = $requirement === null || ($capabilities[$requirement] ?? false);

            $grouped[$meta['group']]['label'] = self::GROUPS[$meta['group']] ?? $meta['group'];
            $grouped[$meta['group']]['sections'][$key] = $meta + ['available' => $available];
        }

        return $grouped;
    }
}
