{{-- One routing decision, with the reason in words, the rule as it was, and what it was based on. Expects $decision, $labels. --}}
@use('App\Enums\Fulfillment\ProviderCode')
@php
    $inputs = (array) ($decision->inputs ?? []);
    $rule = (array) ($decision->rule_snapshot ?? []);
    $wantedProvider = $inputs['wanted_provider'] ?? null;
    $actionTone = match ($decision->action) {
        'route_to_provider' => 'success',
        'monitor_only', 'channel_fulfills' => 'info',
        'hold' => 'warning',
        default => 'warning',
    };
@endphp
<div class="border rounded p-3 mb-3 {{ $decision->is_current ? 'border-primary' : '' }}" id="decision-{{ $decision->version }}">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
        <strong>{{ translate('version') }} {{ $decision->version }}</strong>
        @if ($decision->is_current)<x-k.badge tone="accent" :dot="false">{{ translate('current_decision') }}</x-k.badge>@endif
        <x-k.badge :tone="$actionTone">{{ $labels->routingAction($decision->action) }}</x-k.badge>
        @if ($decision->provider)<span class="fs-12">{{ ProviderCode::tryFrom($decision->provider)?->label() ?? $decision->provider }}</span>@endif
        <span class="fs-12 text-muted">{{ $decision->created_at?->format('Y-m-d H:i') }} · {{ $decision->decided_by === 'admin' ? translate('decided_by_an_administrator') : translate('decided_by_the_system') }}</span>
        <x-k.badge :tone="$decision->applied ? 'success' : 'neutral'" :dot="false">{{ $decision->applied ? translate('applied_to_the_order') : translate('not_applied') }}</x-k.badge>
    </div>

    <p class="mb-1">{{ $labels->orderReason($decision->reason_code) }}</p>

    @if ($decision->reason_code === 'managed_dispatch_not_available')
        <div class="alert alert-warning fs-12 py-2 mb-2" role="note">
            <strong>{{ translate('what_the_rule_wanted') }}:</strong>
            {{ $labels->routingAction($inputs['wanted_action'] ?? null) }}
            @if ($wantedProvider) · {{ ProviderCode::tryFrom($wantedProvider)?->label() ?? $wantedProvider }}@endif
            <div class="mt-1">{{ translate('managed_dispatch_not_available_explained') }}</div>
        </div>
    @elseif (isset($inputs['wanted_action']))
        <div class="fs-12 text-muted mb-1">{{ translate('what_the_rule_wanted') }}: {{ $labels->routingAction($inputs['wanted_action']) }}@if ($wantedProvider) · {{ ProviderCode::tryFrom($wantedProvider)?->label() ?? $wantedProvider }}@endif</div>
    @endif

    <div class="fs-12 text-muted mb-1">
        {{ translate('rule') }}:
        @if ($rule !== [])
            {{ $rule['name'] ?? '#' . ($rule['id'] ?? '') }} · {{ translate('priority') }} {{ $rule['priority'] ?? '—' }}
        @else
            {{ translate('no_rule_decided_this') }}
        @endif
    </div>

    <details class="fs-12">
        <summary>{{ translate('what_the_decision_was_based_on') }}</summary>
        <div class="mt-2">
            <div class="text-muted text-break">{{ translate('original_reason_text') }}: {{ $decision->reason }}</div>
            <dl class="row mb-2 mt-2">
                <dt class="col-sm-4">{{ translate('fulfillment_mode') }}</dt><dd class="col-sm-8">{{ $labels->fulfillmentMode($inputs['fulfillment_mode'] ?? null) }}</dd>
                <dt class="col-sm-4">{{ translate('status') }}</dt><dd class="col-sm-8">{{ $labels->orderStatus($inputs['order_status'] ?? null) }}</dd>
                <dt class="col-sm-4">{{ translate('who_pays_the_carrier') }}</dt><dd class="col-sm-8">{{ $labels->carrierPayer($inputs['carrier_payer'] ?? null) }}</dd>
                <dt class="col-sm-4">{{ translate('cash_on_delivery') }}</dt><dd class="col-sm-8">{{ ($inputs['cod'] ?? false) ? translate('yes') : translate('no') }}</dd>
                <dt class="col-sm-4">{{ translate('destination') }}</dt><dd class="col-sm-8">{{ collect([$inputs['city'] ?? null, $inputs['country'] ?? null])->filter()->implode(', ') ?: '—' }}</dd>
                <dt class="col-sm-4">{{ translate('order_total') }}</dt><dd class="col-sm-8">{{ $inputs['order_total'] ?? '—' }}</dd>
            </dl>
            @if (! empty($inputs['lines']))
                <div class="k-table-wrap table-responsive">
                    <table class="k-table k-table--compact">
                        <thead><tr><th>{{ translate('line') }}</th><th>{{ translate('sku') }}</th><th>{{ translate('product_id') }}</th><th>{{ translate('quantity') }}</th><th>{{ translate('provider') }}</th><th>{{ translate('stock_confirmed') }}</th></tr></thead>
                        <tbody>
                        @foreach ($inputs['lines'] as $line)
                            <tr>
                                <td>{{ $line['line'] ?? '—' }}@if (! empty($line['cancelled'])) <x-k.badge tone="danger" :dot="false">{{ translate('cancelled') }}</x-k.badge>@endif</td>
                                <td>{{ $line['sku'] ?? '—' }}</td>
                                <td>{{ $line['product_id'] ?? '—' }}</td>
                                <td>{{ $line['quantity'] ?? '—' }}</td>
                                <td>{{ $line['provider'] ?? '—' }}</td>
                                <td>{{ ($line['stock_confirmed'] ?? false) ? translate('yes') : translate('no') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </details>

    @if (! empty($decision->line_decisions))
        <details class="fs-12 mt-1">
            <summary>{{ translate('decision_per_line') }}</summary>
            <div class="k-table-wrap table-responsive mt-2">
                <table class="k-table k-table--compact">
                    <thead><tr><th>{{ translate('line') }}</th><th>{{ translate('product_id') }}</th><th>{{ translate('quantity') }}</th><th>{{ translate('decision') }}</th><th>{{ translate('provider') }}</th></tr></thead>
                    <tbody>
                    @foreach ($decision->line_decisions as $lineDecision)
                        <tr>
                            <td>{{ $lineDecision['line'] ?? '—' }}</td>
                            <td>{{ $lineDecision['product_id'] ?? '—' }}</td>
                            <td>{{ $lineDecision['quantity'] ?? '—' }}</td>
                            <td>{{ $labels->routingAction($lineDecision['action'] ?? null) }}</td>
                            <td>{{ ($lineDecision['provider'] ?? null) ? (ProviderCode::tryFrom($lineDecision['provider'])?->label() ?? $lineDecision['provider']) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
