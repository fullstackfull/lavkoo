{{-- Read-only live verification. Expects $card, $integrationMode, $verificationRuns, $latestVerification. --}}
<div id="live-verification">
    <x-k.card :title="translate('live_verification')" class="mb-3">
        <p class="fs-12 text-muted">{{ translate('live_verification_help') }}</p>
        <div class="alert alert-{{ $integrationMode->canWrite() ? 'warning' : 'info' }} fs-12 py-2">{{ translate('integration_mode') }}: {{ translate($integrationMode->labelKey()) }}</div>

        <form method="POST" action="{{ route('admin.fulfillment-providers.verification.run', $card['slug']) }}" class="row g-2 align-items-end mb-3">
            @csrf
            @if ($card['code'] === \App\Enums\Fulfillment\ProviderCode::TorodPoint)
                <div class="col-md-8">
                    <label class="form-label fs-12" for="verify-skus">{{ translate('counted_product_skus') }}</label>
                    <input class="form-control form-control-sm" id="verify-skus" type="text" name="skus" maxlength="300" placeholder="13222, 13223, 13224" autocomplete="off">
                    <div class="fs-11 text-muted">{{ translate('counted_product_skus_help') }}</div>
                </div>
            @endif
            <div class="col-auto">
                <button class="btn btn-primary btn-sm" type="submit">{{ translate('run_read_only_checks') }}</button>
            </div>
        </form>

        @if ($latestVerification === null)
            <p class="fs-12 text-muted mb-0">{{ translate('verification_never_run') }}</p>
        @else
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                <strong class="fs-12">{{ translate('verification_latest_run') }} · {{ $latestVerification['started_at'] }}</strong>
                <a class="fs-12" href="{{ route('admin.fulfillment-providers.verification.download', [$card['slug'], $latestVerification['run_id']]) }}">{{ translate('download_evidence') }}</a>
            </div>
            <div class="k-table-wrap mb-2">
                <table class="k-table">
                    <thead><tr><th>#</th><th>{{ translate('check') }}</th><th>{{ translate('status') }}</th><th>{{ translate('http') }}</th><th>{{ translate('duration') }}</th><th>{{ translate('result') }}</th></tr></thead>
                    <tbody>
                    @foreach ($latestVerification['checks'] as $check)
                        <tr>
                            <td>{{ $check['id'] }}</td>
                            <td>{{ translate($check['label']) }}</td>
                            <td><x-k.badge :tone="['pass' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'skipped' => 'neutral'][$check['status']] ?? 'neutral'">{{ translate('verification_status_' . $check['status']) }}</x-k.badge></td>
                            <td>{{ $check['http_status'] ?? '—' }}</td>
                            <td>{{ $check['duration_ms'] }} ms</td>
                            <td class="fs-12">
                                {{ $check['summary'] }}
                                @if (!empty($check['findings']))
                                    <details class="mt-1"><summary class="text-primary" style="cursor:pointer">{{ translate('what_the_response_told_us') }}</summary>
                                        <pre class="bg-light rounded p-2 mb-0 fs-11" style="max-height:220px;overflow:auto">{{ json_encode($check['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="fs-11 text-muted mb-2">{{ translate('verification_evidence_note') }}</p>
            @if (count($verificationRuns) > 1)
                <div class="fs-12">
                    <span class="fw-semibold">{{ translate('verification_history') }}:</span>
                    @foreach ($verificationRuns as $run)
                        <a class="me-3" href="{{ route('admin.fulfillment-providers.verification.download', [$card['slug'], $run['run_id']]) }}">{{ $run['started_at'] }} ({{ $run['summary']['pass'] ?? 0 }}/{{ $run['summary']['warn'] ?? 0 }}/{{ $run['summary']['fail'] ?? 0 }})</a>
                    @endforeach
                </div>
            @endif
        @endif
    </x-k.card>
</div>
