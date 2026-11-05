{{-- What must hold before this provider receives orders without approval per order. Expects $goLive (GoLiveReport); sits inside the settings form. --}}
<div class="border rounded p-3 mb-3" id="go-live-checklist">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
        <strong>{{ translate('go_live_checklist') }}</strong>
        <x-k.badge :tone="$goLive->ready() ? 'success' : 'warning'">{{ translate($goLive->ready() ? 'go_live_ready' : 'go_live_not_ready') }} · {{ $goLive->metCount() }}/{{ count($goLive->items) }}</x-k.badge>
    </div>
    <p class="fs-12 text-muted">{{ translate('go_live_checklist_help') }}</p>
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
        <div class="form-check border-top pt-3 mt-1">
            <input class="form-check-input" type="checkbox" id="go_live_override" name="go_live_override" value="1">
            <label class="form-check-label" for="go_live_override">{{ translate('go_live_override') }}</label>
            <div class="fs-12 text-muted">{{ translate('go_live_override_help') }}</div>
        </div>
    @endunless
</div>
