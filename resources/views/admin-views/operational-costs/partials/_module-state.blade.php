{{-- The state of the cost module's own switches, at the top of every cost screen: with the master switch off nothing is calculated by itself; with only the schedule off the scheduled runs are absent. --}}
@use('App\Services\OperationalCosts\Support\OperationalCostsSwitch')
@if (! OperationalCostsSwitch::enabled())
    <div class="alert alert-warning fs-12" role="alert" data-cost-module-state="off">
        <strong>{{ translate('operational_costs_are_switched_off_banner') }}</strong>
        <span class="d-block">{{ translate('operational_costs_are_switched_off_help') }}</span>
    </div>
@elseif (! OperationalCostsSwitch::scheduled())
    <div class="alert alert-info fs-12" role="note" data-cost-module-state="schedule-off">
        <strong>{{ translate('operational_costs_schedule_is_switched_off_banner') }}</strong>
        <span class="d-block">{{ translate('operational_costs_schedule_is_switched_off_help') }}</span>
    </div>
@endif
