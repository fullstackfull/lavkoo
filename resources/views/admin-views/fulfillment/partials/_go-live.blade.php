{{-- What must hold before this provider receives orders without approval per order. Expects $goLive (GoLiveReport), $canOverrideGoLive and $config; sits inside the settings form. --}}
<div class="border rounded p-3 mb-3" id="go-live-checklist">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
        <strong>{{ translate('go_live_checklist') }}</strong>
        <x-k.badge :tone="$goLive->ready() ? 'success' : 'warning'">{{ translate($goLive->ready() ? 'go_live_ready' : 'go_live_not_ready') }} · {{ $goLive->metCount() }}/{{ count($goLive->items) }}</x-k.badge>
    </div>
    <p class="fs-12 text-muted">{{ translate('go_live_checklist_help') }}</p>
    @if ($override = $config->get('go_live_override'))
        <div class="alert alert-warning fs-12 py-2" data-go-live-override-record>
            {{ str_replace([':by', ':at', ':unmet', ':reason'], [$override['by'] ?? '', $override['at'] ?? '', collect($override['unmet'] ?? [])->map(fn ($key) => translate('go_live_item_' . $key))->implode(', '), $override['reason'] ?? ''], translate('go_live_override_active')) }}
        </div>
    @endif
    <ul class="list-unstyled mb-0">
        @foreach ($goLive->items as $item)
            <li class="d-flex gap-2 py-2 border-top" data-go-live-item="{{ $item->key }}" data-status="{{ $item->status }}">
                <div class="flex-shrink-0">
                    <x-k.badge :tone="['met' => 'success', 'unmet' => 'danger', 'advisory' => 'warning'][$item->status] ?? 'neutral'">{{ translate('go_live_status_' . $item->status) }}</x-k.badge>
                </div>
                <div class="fs-12">
                    <div class="fw-semibold">{{ translate($item->labelKey()) }}</div>
                    @if ($item->status !== \App\Services\Fulfillment\Readiness\GoLiveItem::MET)
                        <div class="text-muted">{{ translate($item->helpKey()) }}</div>
                    @endif
                    @if ($item->reasonKey)
                        <div class="fs-10 text-muted">{{ translate($item->reasonKey) }}</div>
                    @endif
                    @if ($item->evidence !== '')
                        <div class="fs-10 text-muted text-break">{{ $item->evidence }}</div>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
    @unless ($goLive->ready())
        <div class="border-top pt-3 mt-1">
            @if ($canOverrideGoLive)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="go_live_override" name="go_live_override" value="1" @checked(old('go_live_override'))>
                    <label class="form-check-label" for="go_live_override">{{ translate('go_live_override') }}</label>
                    <div class="fs-12 text-muted">{{ translate('go_live_override_help') }}</div>
                </div>
                <label class="form-label fs-12 mt-2" for="go_live_reason">{{ translate('go_live_override_reason') }}</label>
                <textarea class="form-control form-control-sm" id="go_live_reason" name="go_live_reason" rows="2" maxlength="500">{{ old('go_live_reason') }}</textarea>
            @else
                <div class="fs-12 text-muted" data-go-live-master-only>{{ translate('go_live_override_master_admin_only') }}</div>
            @endif
        </div>
    @endunless
</div>
