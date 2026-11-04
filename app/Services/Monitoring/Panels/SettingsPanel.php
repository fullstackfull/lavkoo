<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Ingest\MetricSink;
use App\Services\Monitoring\Operations\MonitoringConfiguration;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What monitoring has been told to do, where each instruction came from, and what it is costing.
 *
 * Every other section shows a measurement. This one shows the rules those measurements were taken
 * under, and it exists because almost every "the dashboard is wrong" report turns out to be a
 * setting: a section with two traces on a busy afternoon is showing the sample rate, an empty
 * 30-day chart is showing retention, and a threshold nobody remembers moving is why an alert never
 * fired. None of that is discoverable from the sections themselves.
 *
 * Three rules shape it.
 *
 * A value is worthless without its origin. The same number means different things depending on
 * whether an operator typed it here, an environment variable set it at deploy time, or nobody has
 * ever touched it — so every row names its source, and where the source cannot be told apart (a
 * cached configuration does not load .env) the row says that rather than guessing.
 *
 * An override that is not in effect is reported as such. Only the keys the running code reads back
 * through MonitoringSettings can be changed from the database; a stored row for anything else is
 * dead weight, and showing it as the live value would be a lie about how the system behaves.
 *
 * Secrets are never printed. The Prometheus token and the OTLP headers decide who may read this
 * shop's metrics, so this page states only whether one exists — a monitoring screen must not become
 * the place a credential leaks.
 *
 * The panel is read-only: saving arrives with the write action, and until then nothing here can
 * change the system it describes.
 */
class SettingsPanel implements Panel
{
    /**
     * Settings the running code reads back through MonitoringSettings, and therefore the only ones
     * a stored row actually changes.
     *
     * Everything else is read straight from config() by whatever consumes it — the sink, the
     * rollup command, the recorders — so a database row for those keys would be stored and then
     * ignored. Checked against the callers: Checks/*, StoragePanel and EnergyCollector.
     *
     * `ssl_url` and `synthetics` are not prefixes but whole keys, and both are genuinely read back
     * — SslCheck and SyntheticCheck ask MonitoringSettings for them — so omitting them told an
     * operator their stored rows were dead weight when those rows are the only way to configure
     * either check.
     */
    private const OVERRIDABLE_PREFIXES = ['thresholds.', 'energy.', 'ssl_url', 'synthetics'];

    /** The settings table is bounded by its unique key; this is the guard against a surprise. */
    private const MAX_STORED_ROWS = 200;

    /**
     * The groups, in the order an operator reads them: what is on, how long it is kept, what counts
     * as bad, then the three areas with their own consequences — cost, privacy and exposure.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'general' => 'the_master_switches_and_the_shape_of_everything_that_is_ingested',
        'retention' => 'how_long_each_kind_of_row_survives_before_the_rollup_command_prunes_it',
        'thresholds' => 'the_numbers_that_decide_whether_a_reading_is_healthy_degraded_or_critical',
        'tracing' => 'how_much_of_the_traffic_is_recorded_span_by_span_and_where_those_traces_go',
        'privacy' => 'what_is_removed_from_live_traffic_before_any_of_it_is_written_down',
        'energy' => 'whether_power_draw_may_be_estimated_and_what_a_kilowatt_hour_is_priced_at',
        'integrations' => 'the_outside_endpoints_monitoring_reads_from_and_the_one_it_exposes',
    ];

    /**
     * Retention entries: which environment variable sets each, and what it really governs.
     *
     * The tables named here are the ones MonitoringRollup::prune() actually deletes from, not the
     * ones the key's name suggests — hour_days quietly governs check results and scheduled runs
     * too, and an operator shortening it needs to know that before the scheduler page empties.
     *
     * @var array<string, array{env: ?string, what: string, note: ?string}>
     */
    private const RETENTION = [
        'minute_days' => [
            'env' => 'MONITORING_RETENTION_MINUTE_DAYS',
            'what' => 'how_long_one_minute_buckets_are_kept_which_is_the_only_resolution_that_can_answer_what_happened_at_02_11',
            'note' => 'Prunes minute rows from monitoring_request_buckets, monitoring_series and monitoring_dependency_buckets.',
        ],
        'hour_days' => [
            'env' => 'MONITORING_RETENTION_HOUR_DAYS',
            'what' => 'how_long_hourly_rollups_are_kept_which_is_what_every_window_longer_than_six_hours_is_drawn_from',
            'note' => 'Also prunes monitoring_slow_queries, monitoring_check_results and monitoring_scheduled_runs, which have no resolution of their own.',
        ],
        'day_days' => [
            'env' => 'MONITORING_RETENTION_DAY_DAYS',
            'what' => 'how_long_daily_rollups_are_kept_which_is_the_only_thing_a_year_over_year_comparison_can_read',
            'note' => null,
        ],
        'trace_days' => [
            'env' => 'MONITORING_RETENTION_TRACE_DAYS',
            'what' => 'how_long_full_span_trees_are_kept_spans_are_the_largest_table_monitoring_writes_so_the_default_is_deliberately_short',
            'note' => 'Prunes monitoring_traces; monitoring_spans follows its trace so no orphan outlives it.',
        ],
        'error_days' => [
            'env' => 'MONITORING_RETENTION_ERROR_DAYS',
            'what' => 'how_long_individual_exception_occurrences_and_their_closed_groups_are_kept',
            'note' => 'An error group that is still open is never pruned, however old its last occurrence is.',
        ],
        'log_days' => [
            'env' => 'MONITORING_RETENTION_LOG_DAYS',
            'what' => 'intended_lifetime_for_stored_log_lines',
            'note' => 'Nothing reads this today: the Logs section reads Laravel\'s own files, which are rotated by logging.channels.daily.days rather than by monitoring.',
        ],
        'incident_days' => [
            'env' => 'MONITORING_RETENTION_INCIDENT_DAYS',
            'what' => 'how_long_the_event_stream_behind_incidents_and_the_timeline_is_kept',
            'note' => 'Prunes monitoring_events, monitoring_backups, monitoring_deployments and RESOLVED incidents. An incident nobody closed is never pruned, however old it is.',
        ],
    ];

    /**
     * What each shipped threshold decides, and the unit it is expressed in.
     *
     * Kept as data rather than derived from the key's suffix: "warning" and "critical" say which
     * side of the line a reading falls on, never what is being measured, and a table of numbers
     * whose meaning has to be guessed at is how a threshold ends up set to the wrong scale.
     *
     * @var array<string, array{what: string, unit: ?string}>
     */
    private const THRESHOLDS = [
        'cpu_warning' => ['what' => 'cpu_use_at_or_above_this_is_drawn_as_degraded', 'unit' => '%'],
        'cpu_critical' => ['what' => 'cpu_use_at_or_above_this_is_drawn_as_critical', 'unit' => '%'],
        'memory_warning' => ['what' => 'memory_use_at_or_above_this_is_drawn_as_degraded', 'unit' => '%'],
        'memory_critical' => ['what' => 'memory_use_at_or_above_this_is_drawn_as_critical', 'unit' => '%'],
        'disk_warning' => ['what' => 'disk_use_at_or_above_this_is_drawn_as_degraded_and_reported_by_the_storage_check', 'unit' => '%'],
        'disk_critical' => ['what' => 'disk_use_at_or_above_this_is_drawn_as_critical_and_reported_by_the_storage_check', 'unit' => '%'],
        'error_rate_warning' => ['what' => 'share_of_requests_returning_5xx_at_which_a_channel_stops_being_called_healthy', 'unit' => '%'],
        'error_rate_critical' => ['what' => 'share_of_requests_returning_5xx_at_which_a_channel_is_called_critical', 'unit' => '%'],
        'p95_warning_ms' => ['what' => 'p95_response_time_at_which_latency_is_treated_as_degraded', 'unit' => 'ms'],
        'p95_critical_ms' => ['what' => 'p95_response_time_at_which_latency_is_treated_as_critical', 'unit' => 'ms'],
        'db_latency_warning_ms' => ['what' => 'round_trip_to_the_database_at_which_the_database_check_reports_degraded', 'unit' => 'ms'],
        'db_latency_critical_ms' => ['what' => 'round_trip_to_the_database_at_which_the_database_check_reports_critical', 'unit' => 'ms'],
        'redis_latency_warning_ms' => ['what' => 'redis_ping_at_which_the_cache_check_reports_degraded', 'unit' => 'ms'],
        'redis_latency_critical_ms' => ['what' => 'redis_ping_at_which_the_cache_check_reports_critical', 'unit' => 'ms'],
        'queue_lag_warning_seconds' => ['what' => 'age_of_the_oldest_waiting_job_at_which_the_queue_is_called_degraded_lag_not_depth', 'unit' => 'seconds'],
        'queue_lag_critical_seconds' => ['what' => 'age_of_the_oldest_waiting_job_at_which_the_queue_is_called_critical', 'unit' => 'seconds'],
        'scheduler_late_minutes' => ['what' => 'how_far_past_its_due_time_a_scheduled_task_may_run_before_it_counts_as_late', 'unit' => 'minutes'],
        'ssl_expiry_warning_days' => ['what' => 'how_close_the_certificate_may_get_to_expiry_before_the_ssl_check_warns', 'unit' => 'days'],
        'backup_age_warning_hours' => ['what' => 'how_old_the_newest_successful_backup_may_be_before_the_backup_check_warns', 'unit' => 'hours'],
        'stuck_order_hours' => ['what' => 'how_long_an_order_may_sit_in_the_same_state_before_order_integrity_calls_it_stuck', 'unit' => 'hours'],
        'payment_failure_rate_warning' => ['what' => 'share_of_payment_attempts_failing_at_which_the_payments_section_warns', 'unit' => '%'],
    ];

    public function __construct(
        private readonly MonitoringSettings $settings,
        private readonly MetricSink $sink,
        private readonly SeriesReader $reader,
        private readonly PanelRegistry $registry,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $stored = $this->storedOverrides();
        $failures = [];
        $groups = [];

        foreach (self::GROUPS as $key => $why) {
            try {
                $groups[] = ['key' => $key, 'why' => $why, 'rows' => $this->rowsFor($key, $stored['rows'])];
            } catch (\Throwable $exception) {
                // One group that cannot be built must not blank the other six. PanelRegistry would
                // catch this and replace the whole section with a single error, which tells an
                // operator far less than six correct tables and one named failure.
                $failures[] = [
                    'part' => $key,
                    'message' => Metric::describeFailure($exception),
                ];
            }
        }

        // Resolved before the payload is assembled: it can add to the failure list, and a list read
        // in the same literal that fills it is a trap for whoever reorders these keys next.
        $self = $this->selfHealth($failures);

        return [
            'read_only' => false,
            'groups' => $groups,
            'overrides' => $this->overrideSummary($stored, $groups),
            'environment' => $this->environmentVisibility(),
            'self' => $self,
            'failures' => $failures,
            'generated' => [
                'at' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(string $group, array $stored): array
    {
        return match ($group) {
            'general' => $this->generalRows($stored),
            'retention' => $this->retentionRows($stored),
            'thresholds' => $this->thresholdRows($stored),
            'tracing' => $this->tracingRows($stored),
            'privacy' => $this->privacyRows($stored),
            'energy' => $this->energyRows($stored),
            'integrations' => $this->integrationRows($stored),
            default => [],
        };
    }

    // -------------------------------------------------------------------------------------------
    // Groups
    // -------------------------------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function generalRows(array $stored): array
    {
        return [
            $this->row(
                stored: $stored,
                path: 'enabled',
                label: 'collection_enabled',
                what: 'the_master_switch_and_with_it_off_nothing_is_measured_while_every_section_says_so_rather_than_showing_the_last_numbers_it_saw',
                env: 'MONITORING_ENABLED',
            ),
            $this->connectionRow($stored),
            $this->bufferRow($stored),
            $this->row(
                stored: $stored,
                path: 'flush_interval_seconds',
                label: 'flush_interval',
                what: 'how_often_the_buffered_counters_are_drained_into_rows_by_the_scheduled_flush_command',
                unit: 'seconds',
                // Stated as documentation rather than as a control: the cadence is everyMinute() in
                // bootstrap/app.php and nothing reads this value, so an operator who changed it
                // would have got a number that changed nothing.
                note: 'Documentation only. The cadence is the schedule: `monitoring:flush` runs every minute in bootstrap/app.php, and changing this value does not move it.',
            ),
            $this->displayTimezoneRow($stored),
            $this->row(
                stored: $stored,
                path: 'stale_after_seconds',
                label: 'stale_after',
                what: 'how_long_the_header_may_go_without_fresh_telemetry_before_it_stops_claiming_the_shop_is_healthy_and_reports_that_monitoring_itself_is_blind',
                unit: 'seconds',
            ),
            $this->row(
                stored: $stored,
                path: 'timeout_threshold_ms',
                label: 'timeout_threshold',
                what: 'a_request_slower_than_this_is_counted_as_a_timeout_even_when_it_eventually_returned',
                env: 'MONITORING_TIMEOUT_MS',
                unit: 'ms',
            ),
            $this->row(
                stored: $stored,
                path: 'max_series_per_minute',
                label: 'max_series_per_minute',
                what: 'the_high_cardinality_guard_so_that_anything_past_this_many_distinct_series_in_one_minute_is_folded_into_other_instead_of_exploding_the_table',
                unit: 'series',
            ),
            $this->row(
                stored: $stored,
                path: 'latency_buckets_ms',
                label: 'latency_histogram_buckets',
                what: 'the_edges_every_percentile_on_this_dashboard_is_interpolated_from_which_is_what_makes_real_percentiles_cost_a_fixed_amount_of_memory',
                unit: 'ms',
                note: 'Changing these does not rewrite history: buckets already stored keep the edges they were written with.',
            ),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function retentionRows(array $stored): array
    {
        $rows = [];

        // Iterated from configuration rather than listed here, so a retention window added later
        // cannot quietly govern the store without ever appearing on this page.
        foreach (array_keys((array) config('monitoring.retention', [])) as $name) {
            $meta = self::RETENTION[$name] ?? null;

            $rows[] = $this->row(
                stored: $stored,
                path: 'retention.' . $name,
                label: $name,
                what: $meta['what'] ?? 'how_long_rows_of_this_kind_are_kept_before_the_rollup_command_prunes_them',
                env: $meta['env'] ?? null,
                unit: 'days',
                note: $meta['note'] ?? 'This window is not one of the shipped entries, so what it prunes depends on the code that reads it.',
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function thresholdRows(array $stored): array
    {
        $names = array_keys((array) config('monitoring.thresholds', []));

        // A threshold stored in the database with no shipped default still governs the system, so
        // the list is the union of both rather than whatever config happens to ship.
        foreach (array_keys($stored) as $key) {
            if (str_starts_with($key, 'thresholds.')) {
                $names[] = substr($key, strlen('thresholds.'));
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        $rows = [];
        foreach ($names as $name) {
            $meta = self::THRESHOLDS[$name] ?? null;

            $rows[] = $this->row(
                stored: $stored,
                path: 'thresholds.' . $name,
                label: $name,
                what: $meta['what'] ?? 'a_threshold_read_by_whichever_check_or_panel_asks_for_it_by_name',
                unit: $meta['unit'] ?? null,
                note: $meta === null ? 'Not one of the shipped thresholds, so its meaning comes from the code that reads it.' : null,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function tracingRows(array $stored): array
    {
        $sampleRate = (float) config('monitoring.tracing.sample_rate', 0);

        return [
            $this->row(
                stored: $stored,
                path: 'tracing.enabled',
                label: 'tracing_enabled',
                what: 'whether_a_span_tree_is_recorded_at_all_and_therefore_whether_the_traces_section_can_show_anything_however_slow_a_request_was',
                env: 'MONITORING_TRACING',
            ),
            $this->row(
                stored: $stored,
                path: 'tracing.sample_rate',
                label: 'sample_rate',
                what: 'the_share_of_ordinary_requests_kept_as_a_full_trace_because_recording_every_request_of_a_live_store_would_cost_more_than_the_store',
                env: 'MONITORING_TRACE_SAMPLE_RATE',
                note: 'Currently ' . rtrim(rtrim(number_format($sampleRate * 100, 3, '.', ''), '0'), '.') . '% of requests that are neither slow nor failed.',
            ),
            $this->row(
                stored: $stored,
                path: 'tracing.always_trace_slower_than_ms',
                label: 'always_trace_slower_than',
                what: 'a_request_slower_than_this_is_traced_whatever_the_sample_rate_says_because_it_is_the_one_somebody_will_come_looking_for',
                env: 'MONITORING_TRACE_SLOW_MS',
                unit: 'ms',
            ),
            $this->row(
                stored: $stored,
                path: 'tracing.always_trace_errors',
                label: 'always_trace_errors',
                what: 'whether_a_failed_request_is_traced_regardless_of_the_sample_rate',
            ),
            $this->row(
                stored: $stored,
                path: 'tracing.max_spans_per_trace',
                label: 'max_spans_per_trace',
                what: 'the_ceiling_on_one_span_tree_so_a_runaway_loop_cannot_write_an_unbounded_trace',
                unit: 'spans',
            ),
            $this->row(
                stored: $stored,
                path: 'tracing.slow_query_ms',
                label: 'slow_query_threshold',
                what: 'a_query_slower_than_this_is_normalised_and_fingerprinted_into_the_slow_query_table',
                env: 'MONITORING_SLOW_QUERY_MS',
                unit: 'ms',
            ),
            $this->row(
                stored: $stored,
                path: 'tracing.otlp_endpoint',
                label: 'otlp_endpoint',
                // No OTLP exporter exists in this codebase: these three keys are the shape a future
                // one would read, and nothing sends anywhere today. Said here, because a row that
                // reads like a live export path is a claim that traces are leaving this server.
                note: 'Reserved. No exporter is installed in this build, so nothing is posted anywhere whatever this is set to.',
                what: 'where_finished_traces_are_posted_as_otlp_over_http_and_while_it_is_empty_tracing_stays_entirely_inside_this_application',
                env: 'OTEL_EXPORTER_OTLP_ENDPOINT',
            ),
            $this->row(
                stored: $stored,
                path: 'tracing.otlp_headers',
                label: 'otlp_headers',
                what: 'the_headers_sent_with_each_export_and_since_that_is_where_the_collector_credential_lives_only_its_presence_is_shown',
                env: 'OTEL_EXPORTER_OTLP_HEADERS',
                secret: true,
            ),
            $this->row(
                stored: $stored,
                path: 'tracing.service_name',
                label: 'service_name',
                what: 'the_name_this_application_reports_itself_under_in_an_exported_trace',
                env: 'OTEL_SERVICE_NAME',
            ),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function privacyRows(array $stored): array
    {
        return [
            $this->row(
                stored: $stored,
                path: 'privacy.mask_ip',
                label: 'mask_ip_addresses',
                what: 'whether_a_client_address_is_masked_before_it_is_stored_against_an_error_or_a_trace',
                env: 'MONITORING_MASK_IP',
            ),
            $this->row(
                stored: $stored,
                path: 'privacy.store_user_id',
                label: 'store_user_id',
                what: 'whether_the_identifier_of_the_signed_in_customer_is_kept_which_is_what_lets_an_error_be_told_how_many_people_it_hit',
                env: 'MONITORING_STORE_USER_ID',
            ),
            $this->row(
                stored: $stored,
                path: 'privacy.extra_redacted_keys',
                label: 'extra_redacted_keys',
                what: 'additional_field_names_stripped_from_captured_traffic_on_top_of_the_built_in_secret_names_which_can_never_be_switched_off',
                note: 'Additive only. Names are listed here, never the values they matched.',
            ),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function energyRows(array $stored): array
    {
        return [
            $this->row(
                stored: $stored,
                path: 'energy.estimated_mode',
                label: 'estimated_mode',
                what: 'whether_power_draw_may_be_modelled_from_cpu_load_where_the_hardware_exposes_no_real_counter',
                env: 'MONITORING_ENERGY_ESTIMATED',
                note: 'An estimate is always labelled Estimated, never Measured, and is drawn between the idle and full-load figures below.',
            ),
            $this->row(
                stored: $stored,
                path: 'energy.price_per_kwh',
                label: 'price_per_kwh',
                what: 'what_a_kilowatt_hour_costs_and_without_it_the_energy_section_reports_power_but_never_money',
                env: 'MONITORING_ENERGY_PRICE',
            ),
            $this->row(
                stored: $stored,
                path: 'energy.currency',
                label: 'currency',
                what: 'the_currency_the_electricity_price_is_quoted_in',
                env: 'MONITORING_ENERGY_CURRENCY',
            ),
            $this->row(
                stored: $stored,
                path: 'energy.estimate_idle_watts',
                label: 'estimate_idle_watts',
                what: 'draw_of_this_machine_at_rest_used_only_in_estimated_mode_as_the_floor_of_the_model',
                unit: 'W',
            ),
            $this->row(
                stored: $stored,
                path: 'energy.estimate_max_watts',
                label: 'estimate_max_watts',
                what: 'draw_of_this_machine_at_full_load_used_only_in_estimated_mode_as_the_ceiling_of_the_model',
                unit: 'W',
            ),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function integrationRows(array $stored): array
    {
        $exposed = (bool) config('monitoring.prometheus.enabled', false);
        $tokenSet = trim((string) config('monitoring.prometheus.token', '')) !== '';

        return [
            $this->row(
                stored: $stored,
                path: 'node_exporter_url',
                label: 'node_exporter_url',
                what: 'an_external_host_metrics_endpoint_for_the_cases_proc_cannot_answer_such_as_another_machine_or_sensors_behind_a_bmc',
                env: 'MONITORING_NODE_EXPORTER_URL',
                note: 'Host metrics are read from /proc directly, so this stays empty on a normal single server deployment.',
            ),
            $this->row(
                stored: $stored,
                path: 'nginx_status_url',
                label: 'nginx_status_url',
                what: 'the_nginx_stub_status_page_which_is_the_only_source_for_active_connections_and_accepted_request_counts',
                env: 'MONITORING_NGINX_STATUS_URL',
            ),
            $this->row(
                stored: $stored,
                path: 'php_fpm_status_url',
                label: 'php_fpm_status_url',
                what: 'the_php_fpm_status_page_which_is_the_only_source_for_pool_saturation_and_the_listen_queue',
                env: 'MONITORING_PHP_FPM_STATUS_URL',
            ),
            $this->row(
                stored: $stored,
                path: 'prometheus.enabled',
                label: 'prometheus_exposition',
                what: 'whether_the_text_exposition_endpoint_is_served_for_a_prometheus_scrape_with_the_format_generated_here_rather_than_by_a_package',
                env: 'MONITORING_PROMETHEUS',
                note: $exposed && !$tokenSet
                    ? 'The endpoint is on with no token set, so anything that can reach this host can read the shop\'s metrics. Set MONITORING_PROMETHEUS_TOKEN in .env.'
                    : null,
            ),
            $this->row(
                stored: $stored,
                path: 'prometheus.token',
                label: 'prometheus_token',
                what: 'the_bearer_token_a_scrape_must_present_of_which_only_the_presence_is_ever_shown_here',
                env: 'MONITORING_PROMETHEUS_TOKEN',
                secret: true,
            ),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Rows
    // -------------------------------------------------------------------------------------------

    /**
     * One setting: its effective value, where that value came from, and what it decides.
     *
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<string, mixed>
     */
    private function row(
        array $stored,
        string $path,
        string $label,
        string $what,
        ?string $env = null,
        ?string $unit = null,
        bool $secret = false,
        ?string $note = null,
    ): array {
        $value = $this->effectiveValue($path);
        $source = $this->sourceFor($path, $env, $stored);

        $row = [
            'key' => $path,
            'label' => $label,
            'what' => $what,
            'unit' => $unit,
            'secret' => $secret,
            'source' => $source['kind'],
            'source_detail' => $source['detail'],
            'changed_at' => $source['changed_at'],
            'note' => $this->joinNotes($note, $source['note']),
            // Whether the page may offer an input for this row. A secret is never editable here —
            // a monitoring screen must not become the place a credential is typed — and neither is
            // a key the running code does not read back, because saving it would do nothing.
            'editable' => !$secret && MonitoringConfiguration::isWritable($path),
            'shipped' => config('monitoring.' . $path),
        ];

        if ($secret) {
            // Presence is a real reading and a useful one; the value itself is never a row on a
            // page that anybody with dashboard access can open.
            return array_merge($row, [
                'state' => 'ok',
                'value' => null,
                'configured' => $this->isPresent($value),
                'remedy' => null,
            ]);
        }

        $present = $this->isPresent($value);

        return array_merge($row, [
            // Empty is not zero. A missing electricity price is a setting nobody has filled in,
            // and rendering it as 0 would put a free-electricity figure on the energy page.
            'state' => $present ? 'ok' : 'not_configured',
            'value' => $present ? $value : null,
            'configured' => $present,
            'remedy' => $present ? null : $this->remedyFor($path, $env),
        ]);
    }

    /**
     * Which database monitoring writes to, and what that permits.
     *
     * Worth its own row because the answer decides a rule the whole system obeys: nothing may join
     * a monitoring table to a shop table in SQL unless they are provably the same database.
     *
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<string, mixed>
     */
    private function connectionRow(array $stored): array
    {
        $name = (string) config('monitoring.connection', 'monitoring');
        $monitoringDatabase = config('database.connections.' . $name . '.database');
        $shopDatabase = config('database.connections.' . config('database.default') . '.database');

        $note = is_scalar($monitoringDatabase) && is_scalar($shopDatabase) && $monitoringDatabase !== $shopDatabase
            ? 'Writes to its own database (' . $monitoringDatabase . '), separate from the shop.'
            : 'Points at the shop\'s own database (' . (is_scalar($monitoringDatabase) ? $monitoringDatabase : 'unknown') . '), which is the default and needs nothing installed.';

        return $this->row(
            stored: $stored,
            path: 'connection',
            label: 'database_connection',
            what: 'which_connection_every_monitoring_table_is_written_to_so_that_moving_the_store_to_its_own_host_is_a_setting_rather_than_a_migration',
            env: 'MONITORING_DB_CONNECTION',
            note: $note,
        );
    }

    /**
     * The configured buffer, together with the one the process actually resolved to.
     *
     * `auto` is the shipped value, so the configured setting on its own answers nothing — what
     * matters is which of redis, APCu or a direct upsert every request is currently paying for.
     *
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<string, mixed>
     */
    private function bufferRow(array $stored): array
    {
        return $this->row(
            stored: $stored,
            path: 'buffer',
            label: 'buffer_driver',
            what: 'where_the_in_flight_minute_of_counters_lives_before_it_is_folded_into_a_row',
            env: 'MONITORING_BUFFER',
            note: 'The shipped value `auto` takes Redis when it is genuinely reachable, then APCu, then a direct bucket upsert. In use right now: ' . $this->sink->driver() . ' — ' . $this->sink->describe() . '.',
        );
    }

    /**
     * The timezone this dashboard renders in.
     *
     * Shipped configuration has no entry for it, so the effective value normally comes from
     * app.timezone. The row states which of the two answered rather than printing a timezone with
     * no provenance — this is the one setting on the page whose confusion once emptied every chart.
     *
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<string, mixed>
     */
    private function displayTimezoneRow(array $stored): array
    {
        $configured = (string) config('monitoring.display_timezone', '');
        $effective = Clock::displayTimezone();
        $row = $this->row(
            stored: $stored,
            path: 'display_timezone',
            label: 'display_timezone',
            what: 'the_timezone_this_page_converts_stored_timestamps_to_for_reading_and_nothing_else',
            note: $configured === ''
                ? 'Not set for monitoring, so the application timezone answers instead. Everything is stored and compared in UTC either way.'
                : 'Everything is stored and compared in UTC; this only changes what is printed.',
        );

        if ($row['state'] === 'ok') {
            return $row;
        }

        // An unset monitoring.display_timezone is not a hole: the fallback is a real, effective
        // value, and naming where it came from is more useful than a "not configured" row.
        return array_merge($row, [
            'state' => 'ok',
            'value' => $effective,
            'configured' => true,
            'remedy' => null,
            'source' => 'config',
            'source_detail' => 'config/app.php app.timezone',
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Provenance
    // -------------------------------------------------------------------------------------------

    /**
     * The value the running system uses for this key.
     *
     * Resolved the way its consumer resolves it — through MonitoringSettings only where the code
     * genuinely reads it back that way, and from config() everywhere else. Answering every key
     * from the database would show overrides that are stored and then ignored as if they were live.
     */
    private function effectiveValue(string $path): mixed
    {
        return $this->isOverridable($path)
            ? $this->settings->get($path)
            : config('monitoring.' . $path);
    }

    /**
     * Where the effective value came from: a stored override, the environment, or the shipped file.
     *
     * @param  array<string, array<string, mixed>>  $stored
     * @return array{kind: string, detail: string, changed_at: ?string, note: ?string}
     */
    private function sourceFor(string $path, ?string $env, array $stored): array
    {
        $override = $stored[$path] ?? null;

        if ($override !== null && $this->isOverridable($path)) {
            return [
                'kind' => 'database',
                'detail' => 'monitoring_settings.' . $path,
                'changed_at' => $override['changed_at'],
                'note' => null,
            ];
        }

        $ignored = $override === null
            ? null
            : 'A row for this key exists in monitoring_settings, but the code that uses it reads configuration directly, so the stored value is not in effect.';

        if ($env !== null && $this->environmentHas($env)) {
            return ['kind' => 'env', 'detail' => $env, 'changed_at' => null, 'note' => $ignored];
        }

        if ($env !== null && !$this->environmentIsReadable()) {
            // A cached configuration is loaded without .env, so an environment override and the
            // shipped default are indistinguishable from in here. The value above is still exact.
            return [
                'kind' => 'unknown',
                'detail' => $env . ' / config/monitoring.php',
                'changed_at' => null,
                'note' => $ignored,
            ];
        }

        return ['kind' => 'config', 'detail' => 'config/monitoring.php', 'changed_at' => null, 'note' => $ignored];
    }

    private function isOverridable(string $path): bool
    {
        foreach (self::OVERRIDABLE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an environment variable is set for this key.
     *
     * env() rather than $_ENV: the repository Laravel loaded is the one whose answer decided the
     * configured value, and real process variables count as much as lines in .env. This is the one
     * place in the monitoring code that reads env() at runtime, and it reads it for provenance
     * only — never for a value.
     */
    private function environmentHas(string $name): bool
    {
        return env($name) !== null;
    }

    private function environmentIsReadable(): bool
    {
        return !app()->configurationIsCached();
    }

    /** @return array<string, mixed> */
    private function environmentVisibility(): array
    {
        $readable = $this->environmentIsReadable();

        return [
            'readable' => $readable,
            'note' => $readable
                ? null
                : 'The configuration is cached, so this process never loaded .env. Effective values are exact, but an environment override cannot be told apart from the shipped default; run `php artisan config:clear` to see the origins again.',
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Stored overrides
    // -------------------------------------------------------------------------------------------

    /**
     * Everything sitting in monitoring_settings, keyed by setting.
     *
     * No WHERE clause, deliberately: the table is one row per setting behind a unique key — a few
     * dozen rows by construction — and this page needs every one of them to answer "is this value
     * stored or shipped". The LIMIT is the guard against that assumption ever being wrong, and the
     * ordering rides the unique index rather than a filesort.
     *
     * @return array{state: string, rows: array<string, array<string, mixed>>, message: ?string}
     */
    private function storedOverrides(): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_settings')
                ->orderBy('key')
                ->limit(self::MAX_STORED_ROWS)
                ->get(['key', 'value', 'type', 'updated_at']);

            $stored = [];
            foreach ($rows as $row) {
                $stored[(string) $row->key] = [
                    'type' => (string) $row->type,
                    'value' => $row->value,
                    'changed_at' => $row->updated_at === null ? null : Clock::display($row->updated_at)->toDateTimeString(),
                ];
            }

            return ['state' => 'ok', 'rows' => $stored, 'message' => null];
        } catch (\Throwable $exception) {
            // Before the monitoring migrations run there is no table, which means "defaults only"
            // rather than a broken page — but the difference has to be visible, or every row would
            // claim to come from configuration on a deployment whose overrides simply could not be
            // read.
            return [
                'state' => 'unavailable',
                'rows' => [],
                'message' => Metric::describeFailure($exception),
            ];
        }
    }

    /**
     * What is stored, and what is stored that this page does not present.
     *
     * The second half matters: a key in the table that no group renders is either housekeeping or
     * a setting this panel has fallen behind on, and silently dropping it would hide the second.
     *
     * @param  array{state: string, rows: array<string, array<string, mixed>>, message: ?string}  $stored
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function overrideSummary(array $stored, array $groups): array
    {
        $presented = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $presented[$row['key']] = true;
            }
        }

        $applied = 0;
        $unmapped = [];
        foreach ($stored['rows'] as $key => $row) {
            if (isset($presented[$key])) {
                if ($this->isOverridable($key)) {
                    $applied++;
                }

                continue;
            }

            $unmapped[] = [
                'key' => $key,
                'type' => $row['type'],
                'secret' => $this->looksSecret($key),
                'value' => $this->looksSecret($key) ? null : Str::limit((string) $row['value'], 60),
                'configured' => $row['value'] !== null && $row['value'] !== '',
                'changed_at' => $row['changed_at'],
            ];
        }

        return [
            'state' => $stored['state'],
            'message' => $stored['message'],
            'total' => count($stored['rows']),
            'applied' => $applied,
            'unmapped' => $unmapped,
            'truncated' => count($stored['rows']) >= self::MAX_STORED_ROWS,
        ];
    }

    /**
     * Monitoring's own health and footprint, so the page that lists the rules also shows the bill.
     *
     * @param  array<int, array<string, mixed>>  $failures
     * @return array<string, mixed>|null
     */
    private function selfHealth(array &$failures): ?array
    {
        try {
            return $this->registry->selfHealth();
        } catch (\Throwable $exception) {
            $failures[] = [
                'part' => 'self_health',
                'message' => Metric::describeFailure($exception),
            ];

            return null;
        }
    }

    // -------------------------------------------------------------------------------------------
    // Small helpers
    // -------------------------------------------------------------------------------------------

    /**
     * Whether a setting has a value at all.
     *
     * false, 0 and an empty list are readings; null and an empty string are the absence of one.
     */
    private function isPresent(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
    }

    private function remedyFor(string $path, ?string $env): string
    {
        $where = $env !== null
            ? 'Set ' . $env . ' in .env, then run `php artisan config:clear`.'
            : 'Add monitoring.' . $path . ' to config/monitoring.php.';

        return $this->isOverridable($path)
            ? $where . ' It can also be stored as `' . $path . '` in monitoring_settings, which takes precedence.'
            : $where;
    }

    private function joinNotes(?string ...$notes): ?string
    {
        $joined = trim(implode(' ', array_filter($notes, static fn (?string $note) => $note !== null && $note !== '')));

        return $joined === '' ? null : $joined;
    }

    /** A key whose value must never be printed, whether or not this panel knows the setting. */
    private function looksSecret(string $key): bool
    {
        return preg_match('/token|secret|password|passwd|api_key|_key$|credential|headers/i', $key) === 1;
    }
}
