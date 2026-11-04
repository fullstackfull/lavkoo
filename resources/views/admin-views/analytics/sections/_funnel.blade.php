{{-- The funnel. Two percentages per step and they are different questions: share of everyone who
     arrived, and share of the people who reached the step before. 40% of all visitors reaching
     checkout is healthy; 40% of the people who already had a full basket is a broken payment form. --}}
<x-k.card :title="translate('from_a_visit_to_an_order')">
    @if (($funnel['state'] ?? '') !== 'ok')
        @include('admin-views.analytics.sections._empty', ['state' => $funnel['state'] ?? 'no_traffic'])
    @else
        <div class="ana-funnel">
            @foreach ($funnel['steps'] as $step)
                <div class="ana-funnel__step">
                    <div class="ana-funnel__bar" style="width: {{ max(2, $step['share_of_all']) }}%">
                        <span>{{ number_format($step['sessions']) }}</span>
                    </div>
                    <div class="ana-funnel__label">
                        <strong>{{ translate($step['label']) }}</strong>
                        <small>
                            {{ $step['share_of_all'] }}% {{ translate('of_all_visits') }}
                            @if ($step['step_conversion'] !== null)
                                · {{ $step['step_conversion'] }}% {{ translate('of_the_previous_step') }}
                            @endif
                            @if (($step['dropped'] ?? 0) > 0)
                                · <span class="ana-drop">{{ number_format($step['dropped']) }} {{ translate('lost_here') }}</span>
                            @endif
                        </small>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-k.card>
