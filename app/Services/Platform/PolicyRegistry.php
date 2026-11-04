<?php

namespace App\Services\Platform;

/**
 * Every rule the platform applies to itself, declared in one place.
 *
 * The control-surface audit found the same shape of defect ninety times over: a number that decides
 * something a marketplace would want to decide — what counts as low stock, how long a courier may go
 * quiet, how many attempts a webhook gets, how short a password may be — living as a private constant
 * inside whichever class happened to need it. Three consequences follow every time.
 *
 * It cannot be changed without a deploy, which makes it a build rather than a policy. It cannot be
 * seen, so nobody knows it is there until it fires. And because each one was written where it was
 * needed, the same idea acquired several disagreeing values: three definitions of low stock, four of
 * a late order, two minimum password lengths.
 *
 * This registry is the declaration; `Policy` is the reader; one admin screen renders it. A rule that
 * is declared here is settable, bounded, labelled and audited by construction, and adding one is a
 * single entry rather than a page, a controller and a migration.
 *
 * Defaults are the values the constants already held, so an install that never opens the screen
 * behaves exactly as it does today.
 */
class PolicyRegistry
{
    /**
     * group => [title, help, module, policies[key => definition]]
     *
     * A definition is:
     *   type     int | decimal | ratio | choice | multi_choice | time | toggle
     *   default  the shipped value — what the constant held
     *   min/max  bounds for the numeric types; a stored value outside them is clamped, not obeyed
     *   options  for choice / multi_choice
     *   label    translation key
     *   help     translation key, optional
     *   unit     translation key shown after the input, optional
     */
    public const GROUPS = [
        'operations' => [
            'title' => 'operations_windows',
            'help' => 'these_windows_are_what_the_action_center_raises_by_and_what_the_countdown_colours_by',
            'icon' => 'clock',
            'policies' => [
                'ops_stuck_order_hours' => [
                    'type' => 'int', 'default' => 72, 'min' => 1, 'max' => 720,
                    'label' => 'hours_without_movement_before_an_order_is_raised_as_stuck',
                ],
                'ops_stuck_stop_after_days' => [
                    'type' => 'int', 'default' => 45, 'min' => 1, 'max' => 365,
                    'label' => 'stop_raising_a_stuck_order_after_days',
                ],
                'ops_sla_urgent_fraction' => [
                    'type' => 'ratio', 'default' => 0.25, 'min' => 0.05, 'max' => 0.9,
                    'label' => 'call_an_order_urgent_when_this_share_of_its_window_is_left',
                ],
                'ops_sla_closing_minutes' => [
                    'type' => 'int', 'default' => 120, 'min' => 5, 'max' => 1440,
                    'label' => 'minutes_left_when_the_countdown_turns_red',
                ],
                'ops_sla_soon_minutes' => [
                    'type' => 'int', 'default' => 480, 'min' => 10, 'max' => 10080,
                    'label' => 'minutes_left_when_the_countdown_turns_amber',
                ],
                'ops_returns_response_hours' => [
                    'type' => 'int', 'default' => 48, 'min' => 1, 'max' => 720,
                    'label' => 'hours_to_answer_a_refund_request',
                ],
                'ops_returns_processing_hours' => [
                    'type' => 'int', 'default' => 72, 'min' => 1, 'max' => 720,
                    'label' => 'hours_to_process_an_authorised_return',
                ],
                'ops_finance_grace_hours' => [
                    'type' => 'int', 'default' => 6, 'min' => 1, 'max' => 168,
                    'label' => 'hours_after_delivery_before_a_missing_earning_is_raised',
                ],
                'ops_batch_expiry_days' => [
                    'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365,
                    'label' => 'days_ahead_expiring_stock_is_surfaced',
                ],
            ],
        ],

        'inventory' => [
            'title' => 'stock_policy',
            'help' => 'one_definition_of_low_stock_read_by_the_briefing_the_inventory_screen_and_the_opportunity_cards',
            'icon' => 'inventory',
            'policies' => [
                // One ladder, in days of remaining cover, rather than four numbers in four classes.
                // The steps are different actions, not different opinions: colour the row red, colour
                // it amber, interrupt the seller in the briefing, suggest a restock as an opportunity.
                'stock_cover_critical_days' => [
                    'type' => 'decimal', 'default' => 1.0, 'min' => 0.1, 'max' => 60,
                    'label' => 'days_of_cover_below_which_stock_is_called_critical',
                ],
                'stock_cover_low_days' => [
                    'type' => 'decimal', 'default' => 3.0, 'min' => 0.1, 'max' => 90,
                    'label' => 'days_of_cover_below_which_stock_is_called_low',
                ],
                'stock_cover_raise_days' => [
                    'type' => 'decimal', 'default' => 7.0, 'min' => 0.1, 'max' => 180,
                    'label' => 'days_of_cover_below_which_a_restock_is_raised_in_the_briefing',
                ],
                'stock_cover_opportunity_days' => [
                    'type' => 'decimal', 'default' => 14.0, 'min' => 0.1, 'max' => 365,
                    'label' => 'days_of_cover_below_which_a_restock_is_offered_as_an_opportunity',
                ],
                'stock_velocity_days' => [
                    'type' => 'int', 'default' => 14, 'min' => 1, 'max' => 365,
                    'label' => 'days_of_sales_used_to_work_out_how_fast_stock_moves',
                    'help' => 'every_cover_figure_on_every_screen_is_measured_over_this_window',
                ],
                'stock_stale_days' => [
                    'type' => 'int', 'default' => 90, 'min' => 7, 'max' => 730,
                    'label' => 'days_unsold_before_stock_is_called_dead_capital',
                ],
                'stock_stale_minimum_units' => [
                    'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 1000,
                    'label' => 'units_on_hand_before_unsold_stock_is_worth_raising',
                ],
            ],
        ],

        'catalog' => [
            'title' => 'catalogue_policy',
            'help' => 'the_quality_bar_a_listing_must_clear_and_the_limits_a_merchandiser_works_within',
            'icon' => 'catalog',
            'policies' => [
                'catalog_quality_bar' => [
                    'type' => 'int', 'default' => 70, 'min' => 0, 'max' => 100,
                    'label' => 'listing_score_below_which_a_product_is_raised_for_improvement',
                ],
                'catalog_price_swing_ratio' => [
                    'type' => 'ratio', 'default' => 0.5, 'min' => 0.05, 'max' => 1,
                    'label' => 'share_of_the_previous_price_a_change_must_exceed_to_be_called_extreme',
                ],
                'catalog_price_swing_hours' => [
                    'type' => 'int', 'default' => 48, 'min' => 1, 'max' => 720,
                    'label' => 'hours_a_price_change_is_watched_for',
                ],
            ],
        ],

        'commerce' => [
            'title' => 'merchandising_limits',
            'help' => 'how_large_a_curated_collection_campaign_or_experiment_may_get',
            'icon' => 'promotion',
            'policies' => [
                'commerce_max_pins' => [
                    'type' => 'int', 'default' => 12, 'min' => 1, 'max' => 200,
                    'label' => 'pinned_products_per_collection',
                ],
                'commerce_max_exclusions' => [
                    'type' => 'int', 'default' => 100, 'min' => 1, 'max' => 2000,
                    'label' => 'excluded_products_per_collection',
                ],
                'commerce_max_boosts' => [
                    'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 500,
                    'label' => 'boosted_products_per_collection',
                ],
                'commerce_max_boost_weight' => [
                    'type' => 'int', 'default' => 1000, 'min' => 1, 'max' => 100000,
                    'label' => 'highest_boost_weight_allowed',
                ],
                'commerce_max_chain' => [
                    'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 20,
                    'label' => 'how_deep_a_fallback_chain_may_go',
                ],
                'commerce_max_collection_rules' => [
                    'type' => 'int', 'default' => 12, 'min' => 1, 'max' => 100,
                    'label' => 'rules_per_collection',
                ],
                'commerce_max_segment_rules' => [
                    'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 100,
                    'label' => 'rules_per_segment',
                ],
                'commerce_max_campaign_overrides' => [
                    'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 100,
                    'label' => 'overrides_per_campaign',
                ],
                'commerce_max_variants' => [
                    'type' => 'int', 'default' => 4, 'min' => 2, 'max' => 20,
                    'label' => 'variants_per_storefront_experiment',
                ],
            ],
        ],

        'shipping' => [
            'title' => 'fulfilment_policy',
            'help' => 'how_long_a_parcel_may_go_without_courier_movement_before_it_is_an_exception',
            'icon' => 'shipping',
            'policies' => [
                'shipping_silent_hours' => [
                    'type' => 'int', 'default' => 72, 'min' => 1, 'max' => 720,
                    'label' => 'hours_of_courier_silence_before_a_shipment_is_raised',
                ],
                'shipping_stop_after_days' => [
                    'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365,
                    'label' => 'stop_raising_a_silent_shipment_after_days',
                ],
            ],
        ],

        'compliance' => [
            'title' => 'seller_standing',
            'help' => 'the_notice_a_seller_gets_before_a_document_expires_and_the_bands_that_label_their_account',
            'icon' => 'shield',
            'policies' => [
                'compliance_expiry_notice_days' => [
                    'type' => 'int', 'default' => 45, 'min' => 1, 'max' => 365,
                    'label' => 'days_of_notice_before_a_verification_document_expires',
                ],
                'health_watch_cancellation_rate' => [
                    'type' => 'ratio', 'default' => 0.05, 'min' => 0, 'max' => 1,
                    'label' => 'cancellation_rate_that_puts_a_seller_on_watch',
                ],
                'health_watch_return_rate' => [
                    'type' => 'ratio', 'default' => 0.05, 'min' => 0, 'max' => 1,
                    'label' => 'return_rate_that_puts_a_seller_on_watch',
                ],
                'health_watch_refund_rate' => [
                    'type' => 'ratio', 'default' => 0.05, 'min' => 0, 'max' => 1,
                    'label' => 'refund_rate_that_puts_a_seller_on_watch',
                ],
                'health_watch_rating' => [
                    'type' => 'decimal', 'default' => 4.0, 'min' => 0, 'max' => 5,
                    'label' => 'rating_below_which_a_seller_is_on_watch',
                ],
                'health_watch_strikes' => [
                    'type' => 'int', 'default' => 1, 'min' => 1, 'max' => 50,
                    'label' => 'strikes_that_put_a_seller_on_watch',
                ],
                'health_at_risk_cancellation_rate' => [
                    'type' => 'ratio', 'default' => 0.15, 'min' => 0, 'max' => 1,
                    'label' => 'cancellation_rate_that_puts_a_seller_at_risk',
                ],
                'health_at_risk_return_rate' => [
                    'type' => 'ratio', 'default' => 0.10, 'min' => 0, 'max' => 1,
                    'label' => 'return_rate_that_puts_a_seller_at_risk',
                ],
                'health_at_risk_refund_rate' => [
                    'type' => 'ratio', 'default' => 0.15, 'min' => 0, 'max' => 1,
                    'label' => 'refund_rate_that_puts_a_seller_at_risk',
                ],
                'health_at_risk_rating' => [
                    'type' => 'decimal', 'default' => 3.0, 'min' => 0, 'max' => 5,
                    'label' => 'rating_below_which_a_seller_is_at_risk',
                ],
                'health_at_risk_strikes' => [
                    'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 50,
                    'label' => 'strikes_that_put_a_seller_at_risk',
                ],
            ],
        ],

        'security' => [
            'title' => 'access_policy',
            'help' => 'one_password_rule_and_one_brute_force_tolerance_for_every_surface',
            'icon' => 'lock',
            'policies' => [
                'password_minimum_length' => [
                    'type' => 'int', 'default' => 8, 'min' => 6, 'max' => 64,
                    'label' => 'shortest_password_the_platform_accepts',
                    'help' => 'applies_to_every_sign_up_reset_and_staff_account_across_web_and_api',
                ],
                'auth_attempts_per_minute' => [
                    'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 600,
                    'label' => 'sign_in_attempts_allowed_per_minute',
                ],
                'recaptcha_minimum_score' => [
                    'type' => 'ratio', 'default' => 0.5, 'min' => 0.0, 'max' => 1.0,
                    'label' => 'lowest_recaptcha_score_a_visitor_may_have_and_still_be_let_through',
                    'help' => 'higher_turns_away_more_bots_and_more_people',
                ],
                'api_requests_per_minute' => [
                    'type' => 'int', 'default' => 3000, 'min' => 60, 'max' => 100000,
                    'label' => 'api_requests_allowed_per_minute_per_client',
                ],
            ],
        ],

        'analytics' => [
            'title' => 'analytics_and_privacy',
            'help' => 'what_is_measured_about_live_customer_traffic_who_is_excluded_and_how_long_it_is_kept',
            'icon' => 'reports',
            'policies' => [
                'analytics_enabled' => [
                    'type' => 'toggle', 'default' => true,
                    'label' => 'measure_visits_at_all',
                ],
                'analytics_respect_do_not_track' => [
                    'type' => 'toggle', 'default' => false,
                    'label' => 'honour_the_do_not_track_header',
                    'help' => 'refused_visits_are_counted_on_the_data_quality_screen_so_the_drop_has_an_explanation',
                ],
                'analytics_require_consent' => [
                    'type' => 'toggle', 'default' => false,
                    'label' => 'measure_nothing_until_a_visitor_accepts_cookies',
                ],
                'analytics_mask_ip' => [
                    'type' => 'toggle', 'default' => true,
                    'label' => 'mask_the_ip_to_its_network_before_hashing_it',
                ],
                'analytics_store_country' => [
                    'type' => 'toggle', 'default' => false,
                    'label' => 'store_the_visitors_country',
                ],
                'analytics_exclude_bots' => [
                    'type' => 'toggle', 'default' => true,
                    'label' => 'leave_bots_out_of_the_reports',
                ],
                'analytics_exclude_internal' => [
                    'type' => 'toggle', 'default' => true,
                    'label' => 'leave_your_own_staffs_browsing_out_of_the_reports',
                ],
                'analytics_session_gap_minutes' => [
                    'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 1440,
                    'label' => 'minutes_of_inactivity_that_end_a_visit',
                ],
                'analytics_engaged_after_seconds' => [
                    'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 3600,
                    'label' => 'seconds_on_the_shop_before_a_visit_counts_as_engaged',
                ],
                'analytics_retention_event_days' => [
                    'type' => 'int', 'default' => 90, 'min' => 1, 'max' => 3650,
                    'label' => 'days_individual_events_are_kept',
                ],
                'analytics_retention_session_days' => [
                    'type' => 'int', 'default' => 400, 'min' => 1, 'max' => 3650,
                    'label' => 'days_sessions_are_kept',
                ],
                'analytics_retention_daily_days' => [
                    'type' => 'int', 'default' => 1100, 'min' => 1, 'max' => 3650,
                    'label' => 'days_the_daily_rollups_are_kept',
                    'help' => 'the_rollups_are_small_and_are_what_every_long_range_chart_reads',
                ],
            ],
        ],

        'developer' => [
            'title' => 'developer_portal',
            'help' => 'what_the_api_console_may_do_and_whether_response_shapes_are_learned_from_traffic',
            'icon' => 'code',
            'policies' => [
                'developer_console_enabled' => [
                    'type' => 'toggle', 'default' => true,
                    'label' => 'the_api_console_is_available',
                ],
                'developer_console_allow_writes' => [
                    'type' => 'toggle', 'default' => false,
                    'label' => 'the_console_may_send_writes',
                    'help' => 'off_by_default_everywhere_a_console_on_an_admin_panel_sends_real_requests_at_the_shop_that_takes_the_orders',
                ],
                'developer_console_rate_limit' => [
                    'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 600,
                    'label' => 'console_requests_per_minute_per_administrator',
                ],
                'developer_record_response_shapes' => [
                    'type' => 'toggle', 'default' => true,
                    'label' => 'learn_response_shapes_from_real_traffic',
                    'help' => 'only_keys_and_types_are_stored_never_a_value_from_any_response',
                ],
            ],
        ],

        'integrations' => [
            'title' => 'webhook_delivery',
            'help' => 'how_hard_the_platform_tries_before_a_sellers_event_is_lost',
            'icon' => 'plug',
            'policies' => [
                'webhook_max_attempts' => [
                    'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 20,
                    'label' => 'delivery_attempts_before_a_webhook_is_given_up_on',
                ],
                'webhook_timeout_seconds' => [
                    'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 120,
                    'label' => 'seconds_to_wait_for_the_receiving_endpoint',
                ],
                'webhook_backoff_minutes' => [
                    'type' => 'int', 'default' => 2, 'min' => 1, 'max' => 240,
                    'label' => 'minutes_before_the_first_retry_doubling_each_attempt',
                ],
            ],
        ],

        'finance' => [
            'title' => 'payment_terms',
            'help' => 'what_the_marketplace_promises_its_sellers_about_when_they_are_paid',
            'icon' => 'wallet',
            'policies' => [
                'payout_holding_days' => [
                    'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 180,
                    'label' => 'days_an_earning_is_held_before_it_becomes_available',
                    'help' => 'a_floor_on_top_of_the_category_return_window_zero_leaves_the_return_window_alone',
                ],
                'payout_minimum_amount' => [
                    'type' => 'decimal', 'default' => 0.0, 'min' => 0, 'max' => 1000000,
                    'label' => 'smallest_balance_a_seller_may_request_a_payout_for',
                ],
                'payout_dual_control_amount' => [
                    'type' => 'decimal', 'default' => 0.0, 'min' => 0, 'max' => 100000000,
                    'label' => 'payout_amount_above_which_a_second_approver_is_required',
                    'help' => 'zero_switches_the_second_approver_off',
                ],
                'payout_bank_change_freeze_hours' => [
                    'type' => 'int', 'default' => 24, 'min' => 0, 'max' => 720,
                    'label' => 'hours_payouts_are_frozen_after_a_seller_changes_their_bank_details',
                    'help' => 'the_platforms_anti_account_takeover_hold_the_length_is_what_a_risk_team_retunes_after_an_incident',
                ],
                'reconciliation_lookback_days' => [
                    'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 730,
                    'label' => 'days_a_sellers_reconciliation_looks_back_by_default',
                ],
            ],
        ],

        'platform' => [
            'title' => 'sweep_and_page_limits',
            'help' => 'how_much_the_platform_looks_at_in_one_pass_raise_these_as_the_marketplace_grows',
            'icon' => 'layers',
            'policies' => [
                'limit_automation_sweep' => [
                    'type' => 'int', 'default' => 200, 'min' => 10, 'max' => 10000,
                    'label' => 'automation_rules_evaluated_in_one_sweep',
                ],
                'limit_audit_rows' => [
                    'type' => 'int', 'default' => 200, 'min' => 20, 'max' => 5000,
                    'label' => 'audit_rows_a_seller_may_page_back_through',
                ],
                'limit_control_tower_rows' => [
                    'type' => 'int', 'default' => 500, 'min' => 50, 'max' => 20000,
                    'label' => 'open_issues_and_deadlines_read_per_control_tower_load',
                ],
                'limit_admin_seller_rollup' => [
                    'type' => 'int', 'default' => 200, 'min' => 20, 'max' => 10000,
                    'label' => 'sellers_included_in_the_admin_issue_rollup',
                ],
            ],
        ],
    ];

    /** @return array<string, array<string, mixed>> every policy definition, keyed by setting name */
    public static function definitions(): array
    {
        static $flat = null;

        if ($flat === null) {
            $flat = [];
            foreach (self::GROUPS as $group => $meta) {
                foreach ($meta['policies'] as $key => $definition) {
                    $flat[$key] = $definition + ['group' => $group];
                }
            }
        }

        return $flat;
    }

    public static function definition(string $key): ?array
    {
        return self::definitions()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    /** @return array<int, string> the keys in one group, in declared order */
    public static function keysIn(string $group): array
    {
        return array_keys(self::GROUPS[$group]['policies'] ?? []);
    }
}