{{--
    One storefront: the state it is in, what it still needs before it can sell, who last changed it and why, and the step the state allows. The steps are shown to an admin who holds
    the lifecycle permission; the service refuses what may not be done, and the screen shows its words.
--}}
@use('App\Services\Storefront\StorefrontLifecycle')
@use('App\Services\Storefront\StorefrontPreflight')
@php
    $country = $storefront['country'];
    $status = $storefront['status'];
    $state = $status['state'];
    $unmet = $storefront['unmet'];
    $tone = match ($state) {
        StorefrontLifecycle::ACTIVE => 'success',
        StorefrontLifecycle::PAUSED => 'warning',
        StorefrontLifecycle::READY => 'info',
        default => 'neutral',
    };
    $prerequisites = [
        StorefrontPreflight::LEGAL_TEXTS => 'its_legal_texts_are_published_and_approved',
        StorefrontPreflight::LEGAL_REVIEW => 'the_independent_review_of_its_legal_texts_is_on_record',
        StorefrontPreflight::CURRENCY => 'the_currency_it_trades_in_is_ready',
        StorefrontPreflight::TAX => 'its_tax_is_approved_or_recorded_as_none',
    ];
    $modesOfThisStorefront = $storefront['is_default'] ? [StorefrontLifecycle::BROWSE_ONLY] : $modes;
    $typed = static fn (string $step, string $field): ?string => old('_step') === $step . '-' . $country ? old($field) : null;
@endphp
<x-k.card :title="$country . ' · ' . $storefront['currency']" data-storefront="{{ $country }}" data-state="{{ $state }}">
    <x-slot:actions>
        <x-k.badge :tone="$tone">{{ translate('storefront_state_' . $state) }}@if ($status['mode']) · {{ translate('storefront_stop_mode_' . $status['mode']) }}@endif</x-k.badge>
    </x-slot:actions>

    @if ($storefront['is_default'])
        <p class="fs-12 text-muted">{{ translate('the_default_storefront_is_active_without_a_record_and_can_only_be_paused_browse_only') }}</p>
    @endif

    <ul class="list-unstyled fs-12 mb-3">
        @foreach ($prerequisites as $prerequisite => $label)
            @php $isUnmet = in_array($prerequisite, $unmet, true); @endphp
            <li class="mb-1" data-prerequisite="{{ $prerequisite }}" data-met="{{ $isUnmet ? 'no' : 'yes' }}">
                <x-k.badge :tone="$isUnmet ? 'danger' : 'success'" :dot="false">{{ $isUnmet ? translate('unmet') : translate('met') }}</x-k.badge>
                {{ translate($label) }}
            </li>
        @endforeach
    </ul>

    @if (!$storefront['is_default'] && $canOpenTheLegalTexts)
        <a class="btn btn-outline-secondary btn-sm mb-3" href="{{ route('admin.storefronts.legal-texts.index', $country) }}">{{ translate('legal_texts') }}</a>
    @endif

    @if ($status['changed_by'])
        <p class="fs-12 text-muted mb-1" data-last-change>
            {{ translate('last_change') }}: {{ $status['changed_by'] }}, {{ $status['changed_at'] }}: {{ $status['reason'] }}@if ($status['reference']) ({{ translate('approval') }}: {{ $status['reference'] }})@endif
        </p>
    @endif
    @if ($status['message'])
        <p class="fs-12 mb-1">{{ translate('what_its_customers_are_told') }}: {{ $status['message'] }}</p>
    @endif
    @if ($status['until'])
        <p class="fs-12 mb-1">{{ translate('the_stop_is_expected_to_end') }}: <span dir="ltr">{{ $status['until'] }} UTC</span></p>
    @endif

    @if ($canChangeTheLifecycle)
        <div class="border-top pt-3 mt-3">
            @if ($state === StorefrontLifecycle::DRAFT)
                @if ($unmet === [])
                    <form method="POST" action="{{ route('admin.storefronts.ready', $country) }}">@csrf
                        <input type="hidden" name="_step" value="ready-{{ $country }}">
                        <label class="form-label fs-12" for="ready-{{ $country }}-reason">{{ translate('why_it_is_ready') }}</label>
                        <input class="form-control form-control-sm mb-2" id="ready-{{ $country }}-reason" name="reason" required maxlength="191" value="{{ $typed('ready', 'reason') }}">
                        <button class="btn btn-primary btn-sm" type="submit">{{ translate('mark_ready') }}</button>
                    </form>
                @else
                    <p class="fs-12 text-muted mb-0">{{ translate('a_storefront_can_be_marked_ready_when_everything_above_is_met') }}</p>
                @endif
            @elseif ($state === StorefrontLifecycle::READY)
                <form method="POST" action="{{ route('admin.storefronts.activate', $country) }}">@csrf
                    <input type="hidden" name="_step" value="activate-{{ $country }}">
                    <label class="form-label fs-12" for="activate-{{ $country }}-reference">{{ translate('where_the_approval_is_written_down') }}</label>
                    <input class="form-control form-control-sm mb-2" id="activate-{{ $country }}-reference" name="reference" required maxlength="191" value="{{ $typed('activate', 'reference') }}">
                    <label class="form-label fs-12" for="activate-{{ $country }}-reason">{{ translate('why_it_is_activated') }}</label>
                    <input class="form-control form-control-sm mb-2" id="activate-{{ $country }}-reason" name="reason" required maxlength="191" value="{{ $typed('activate', 'reason') }}">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('activate_storefront') }}</button>
                </form>
            @elseif ($state === StorefrontLifecycle::PAUSED)
                <form method="POST" action="{{ route('admin.storefronts.resume', $country) }}" class="mb-3">@csrf
                    <input type="hidden" name="_step" value="resume-{{ $country }}">
                    <label class="form-label fs-12" for="resume-{{ $country }}-reason">{{ translate('why_it_sells_again') }}</label>
                    <input class="form-control form-control-sm mb-2" id="resume-{{ $country }}-reason" name="reason" required maxlength="191" value="{{ $typed('resume', 'reason') }}">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('resume_selling') }}</button>
                </form>
            @endif

            @if (in_array($state, [StorefrontLifecycle::ACTIVE, StorefrontLifecycle::PAUSED], true))
                <form method="POST" action="{{ route('admin.storefronts.pause', $country) }}">@csrf
                    <input type="hidden" name="_step" value="pause-{{ $country }}">
                    <label class="form-label fs-12" for="pause-{{ $country }}-mode">{{ translate('how_it_is_stopped') }}</label>
                    <select class="form-select form-select-sm mb-2" id="pause-{{ $country }}-mode" name="mode" required>
                        @foreach ($modesOfThisStorefront as $mode)
                            <option value="{{ $mode }}" @selected($typed('pause', 'mode') === $mode)>{{ translate('storefront_stop_mode_' . $mode) }}</option>
                        @endforeach
                    </select>
                    <label class="form-label fs-12" for="pause-{{ $country }}-message">{{ translate('what_its_customers_are_told') }}</label>
                    <textarea class="form-control form-control-sm mb-2" id="pause-{{ $country }}-message" name="message" rows="2" maxlength="500">{{ $typed('pause', 'message') }}</textarea>
                    <label class="form-label fs-12" for="pause-{{ $country }}-until">{{ translate('the_stop_is_expected_to_end') }} (UTC)</label>
                    <input class="form-control form-control-sm mb-2" id="pause-{{ $country }}-until" name="until" maxlength="16" dir="ltr" placeholder="2026-12-01 08:00" value="{{ $typed('pause', 'until') }}">
                    <label class="form-label fs-12" for="pause-{{ $country }}-reason">{{ translate('why_it_is_stopped') }}</label>
                    <input class="form-control form-control-sm mb-2" id="pause-{{ $country }}-reason" name="reason" required maxlength="191" value="{{ $typed('pause', 'reason') }}">
                    <button class="btn btn-outline-danger btn-sm" type="submit">{{ $state === StorefrontLifecycle::PAUSED ? translate('change_how_it_is_stopped') : translate('pause_storefront') }}</button>
                </form>
            @endif
        </div>
    @endif
</x-k.card>
