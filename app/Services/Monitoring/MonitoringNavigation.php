<?php

namespace App\Services\Monitoring;

/**
 * The map of the operations centre: which sections exist, what each is for, and who may see it.
 *
 * One declaration rather than a list repeated in the router, the sidebar and the controller — so a
 * section cannot exist in the menu without a route, or be reachable without a permission decision
 * having been made about it.
 *
 * Sections are grouped the way an investigation actually moves: start at the overview, narrow to
 * the layer that looks wrong, then drill into the evidence.
 */
class MonitoringNavigation
{
    /**
     * @return array<string, array{label: string, group: string, icon: string, hint: string}>
     */
    public static function sections(): array
    {
        return [
            // ---- where you start ------------------------------------------------------------
            'overview' => ['label' => 'overview', 'group' => 'situation', 'icon' => 'dashboard', 'hint' => 'system_status_health_score_and_every_service_at_a_glance'],
            'live' => ['label' => 'live_traffic', 'group' => 'situation', 'icon' => 'trend-up', 'hint' => 'who_is_on_the_site_right_now_and_what_they_are_hitting'],
            'incidents' => ['label' => 'incidents', 'group' => 'situation', 'icon' => 'alert', 'hint' => 'many_signals_grouped_into_one_problem_with_its_timeline'],
            'timeline' => ['label' => 'timeline', 'group' => 'situation', 'icon' => 'clock', 'hint' => 'deploys_alerts_incidents_and_scheduler_runs_on_one_axis'],

            // ---- the application ------------------------------------------------------------
            'application' => ['label' => 'application', 'group' => 'application', 'icon' => 'settings', 'hint' => 'runtime_versions_opcache_and_configuration_that_affects_speed'],
            'requests' => ['label' => 'requests', 'group' => 'application', 'icon' => 'external', 'hint' => 'percentiles_per_route_slowest_and_most_failing_endpoints'],
            'errors' => ['label' => 'errors', 'group' => 'application', 'icon' => 'alert', 'hint' => 'exceptions_grouped_by_fingerprint_with_stack_traces'],
            'traces' => ['label' => 'traces', 'group' => 'application', 'icon' => 'reports', 'hint' => 'where_a_single_slow_request_actually_spent_its_time'],
            'logs' => ['label' => 'logs', 'group' => 'application', 'icon' => 'orders', 'hint' => 'searchable_logs_pivoted_by_correlation_id'],

            // ---- the machinery --------------------------------------------------------------
            'database' => ['label' => 'database', 'group' => 'infrastructure', 'icon' => 'reports', 'hint' => 'connections_throughput_locks_slow_queries_and_size'],
            'redis' => ['label' => 'redis_and_cache', 'group' => 'infrastructure', 'icon' => 'settings', 'hint' => 'memory_hit_ratio_evictions_and_what_actually_uses_redis'],
            'queues' => ['label' => 'queues', 'group' => 'infrastructure', 'icon' => 'orders', 'hint' => 'pending_work_oldest_waiting_job_workers_and_failures'],
            'scheduler' => ['label' => 'scheduler', 'group' => 'infrastructure', 'icon' => 'clock', 'hint' => 'every_scheduled_task_with_its_last_run_and_next_due'],
            'server' => ['label' => 'server', 'group' => 'infrastructure', 'icon' => 'dashboard', 'hint' => 'cpu_memory_processes_and_pressure'],
            'network' => ['label' => 'network', 'group' => 'infrastructure', 'icon' => 'external', 'hint' => 'bandwidth_packets_tcp_state_and_dns'],
            'storage' => ['label' => 'storage', 'group' => 'infrastructure', 'icon' => 'catalog', 'hint' => 'disks_inodes_io_and_the_application_own_storage'],
            'webserver' => ['label' => 'web_server', 'group' => 'infrastructure', 'icon' => 'settings', 'hint' => 'nginx_php_fpm_connections_and_worker_pools'],
            'energy' => ['label' => 'energy_and_hardware', 'group' => 'infrastructure', 'icon' => 'trend-up', 'hint' => 'temperatures_power_draw_and_electricity_cost_where_the_hardware_allows'],

            // ---- the clients ----------------------------------------------------------------
            'web-vitals' => ['label' => 'web_performance', 'group' => 'clients', 'icon' => 'customers', 'hint' => 'what_real_shoppers_experience_lcp_inp_cls_and_ttfb'],
            'android' => ['label' => 'android', 'group' => 'clients', 'icon' => 'customers', 'hint' => 'crash_free_sessions_api_latency_and_version_health'],
            'ios' => ['label' => 'ios', 'group' => 'clients', 'icon' => 'customers', 'hint' => 'crash_free_sessions_api_latency_and_version_health'],
            'apis' => ['label' => 'apis', 'group' => 'clients', 'icon' => 'external', 'hint' => 'the_store_own_api_surface_by_version_and_endpoint'],

            // ---- the business ---------------------------------------------------------------
            'payments' => ['label' => 'payments', 'group' => 'business', 'icon' => 'reports', 'hint' => 'attempts_success_rate_gateway_latency_and_webhooks'],
            'orders' => ['label' => 'order_integrity', 'group' => 'business', 'icon' => 'orders', 'hint' => 'orders_that_contradict_themselves_paid_but_missing_stuck_duplicated'],
            'inventory' => ['label' => 'inventory_integrity', 'group' => 'business', 'icon' => 'catalog', 'hint' => 'negative_stock_double_deductions_and_stuck_reservations'],
            'search' => ['label' => 'search_index', 'group' => 'business', 'icon' => 'catalog', 'hint' => 'how_much_of_the_catalogue_storefront_search_can_actually_find'],
            'integrations' => ['label' => 'integrations', 'group' => 'business', 'icon' => 'external', 'hint' => 'every_outbound_service_with_its_latency_and_failures'],

            // ---- keeping it running ---------------------------------------------------------
            'security' => ['label' => 'security', 'group' => 'operations', 'icon' => 'settings', 'hint' => 'failed_logins_admin_activity_and_suspicious_sources'],
            'deployments' => ['label' => 'deployments', 'group' => 'operations', 'icon' => 'trend-up', 'hint' => 'every_release_with_before_and_after_comparison'],
            'backups' => ['label' => 'backups', 'group' => 'operations', 'icon' => 'catalog', 'hint' => 'age_size_outcome_and_when_a_restore_was_last_tested'],
            'synthetics' => ['label' => 'synthetic_tests', 'group' => 'operations', 'icon' => 'clock', 'hint' => 'scripted_checks_that_run_whether_or_not_anyone_is_shopping'],
            'sla' => ['label' => 'sla_and_uptime', 'group' => 'operations', 'icon' => 'reports', 'hint' => 'availability_per_service_with_mttd_and_mttr'],
            'alerts' => ['label' => 'alerts', 'group' => 'operations', 'icon' => 'alert', 'hint' => 'rules_thresholds_and_what_is_currently_firing'],
            'settings' => ['label' => 'monitoring_settings', 'group' => 'operations', 'icon' => 'settings', 'hint' => 'thresholds_retention_privacy_and_energy_pricing'],
        ];
    }

    /**
     * @return array<string, string> group key => translation key
     */
    public static function groups(): array
    {
        return [
            'situation' => 'situation',
            'application' => 'application',
            'infrastructure' => 'infrastructure',
            'clients' => 'clients',
            'business' => 'business',
            'operations' => 'operations',
        ];
    }

    public static function exists(string $section): bool
    {
        return array_key_exists($section, self::sections());
    }

    /**
     * The sidebar, already filtered to what this admin may open.
     *
     * Filtering here rather than in the view means a section the user cannot enter is not
     * advertised to them, and the controller's own permission check is the second lock rather than
     * the only one.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function visibleGroups(MonitoringPermissionService $permissions, string $current): array
    {
        $grouped = [];

        foreach (self::sections() as $key => $section) {
            if (!$permissions->can($permissions->capabilityForTab($key))) {
                continue;
            }
            $grouped[$section['group']][] = [
                'key' => $key,
                'label' => $section['label'],
                'icon' => $section['icon'],
                'hint' => $section['hint'],
                'active' => $key === $current,
                // Seventeen of these thirty-three have no panel behind them yet. The page they
                // open says so honestly, but the rail did not, so more than half the sidebar was
                // an invitation to a dead end. Marked here rather than guessed at in the view.
                'built' => \App\Services\Monitoring\Panels\PanelRegistry::has($key),
            ];
        }

        return $grouped;
    }
}
