{{-- Why a section is empty, in the words that tell a merchant what to DO.
     Four different situations produce the same blank screen, and only one of them means the shop
     had a quiet week — so each one says which it is. --}}
@php($reason = $state ?? 'no_traffic')
<x-k.empty
    :title="match ($reason) {
        'not_installed' => translate('analytics_is_not_installed'),
        'disabled' => translate('analytics_collection_is_switched_off'),
        'no_events' => translate('nothing_has_been_recorded_yet'),
        'rollup_never_ran' => translate('the_rollup_has_never_run'),
        'unknown_dimension' => translate('that_breakdown_does_not_exist'),
        default => translate('no_traffic_in_this_period'),
    }"
    :text="match ($reason) {
        'not_installed' => translate('run_php_artisan_migrate_to_create_the_analytics_tables'),
        'disabled' => translate('set_analytics_enabled_true_in_env_and_clear_the_config_cache'),
        'no_events' => translate('visit_the_storefront_once_if_nothing_appears_check_the_recordanalytics_middleware_is_in_the_web_group'),
        'rollup_never_ran' => translate('events_are_being_collected_but_analytics_rollup_has_never_run_install_the_scheduler_cron'),
        default => translate('this_is_a_real_zero_collection_is_healthy_and_nobody_did_this_in_the_chosen_period'),
    }" />
