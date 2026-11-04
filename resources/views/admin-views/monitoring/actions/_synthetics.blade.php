{{--
    The journeys the prober fetches, added from here rather than from a shell.

    Only http(s) URLs are accepted and never a cloud metadata address — the same rule the check
    itself applies, applied at the point of entry so a refusal names its reason instead of the
    journey silently never running.
--}}
@if ($permissions->canEditSettings())
    <x-k.card :title="translate('probe_a_customer_journey')">
        <form action="{{ route('admin.monitoring.actions.synthetics.add') }}" method="post" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1" for="journey-name">{{ translate('name') }}</label>
                <input id="journey-name" name="name" class="form-control form-control-sm" maxlength="96" required
                       placeholder="{{ translate('checkout_page') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fs-12 mb-1" for="journey-url">{{ translate('url') }}</label>
                <input id="journey-url" name="url" type="url" class="form-control form-control-sm" required
                       placeholder="https://">
            </div>
            <div class="col-md-1">
                <label class="form-label fs-12 mb-1" for="journey-status">{{ translate('status') }}</label>
                <input id="journey-status" name="expect_status" type="number" min="100" max="599" value="200" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="journey-contains">{{ translate('body_must_contain') }}</label>
                <input id="journey-contains" name="expect_text" class="form-control form-control-sm" maxlength="191">
            </div>
            <div class="col-md-1">
                <label class="form-label fs-12 mb-1" for="journey-max">{{ translate('slow_above_ms') }}</label>
                <input id="journey-max" name="max_ms" type="number" min="1" max="600000" class="form-control form-control-sm">
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100">{{ translate('add') }}</button>
            </div>
        </form>

        @php($journeys = app(\App\Services\Monitoring\Operations\MonitoringConfiguration::class)->journeys())
        @if ($journeys !== [])
            <div class="k-table-wrap mt-3">
                <table class="k-table k-table--compact">
                    <thead><tr>
                        <th>{{ translate('name') }}</th><th>{{ translate('url') }}</th>
                        <th>{{ translate('expects') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($journeys as $journey)
                        <tr>
                            <td>{{ $journey['name'] ?? '—' }}</td>
                            <td><code>{{ $journey['url'] ?? '—' }}</code></td>
                            <td class="fs-12">
                                {{ $journey['expect_status'] ?? 200 }}
                                @if (!empty($journey['expect_text'])) · “{{ $journey['expect_text'] }}” @endif
                                @if (!empty($journey['max_ms'])) · &lt; {{ $journey['max_ms'] }} ms @endif
                            </td>
                            <td class="text-end">
                                <form action="{{ route('admin.monitoring.actions.synthetics.remove') }}" method="post"
                                      onsubmit="return confirm('{{ translate('stop_probing_this_journey') }}?')">
                                    @csrf
                                    <input type="hidden" name="name" value="{{ $journey['name'] ?? '' }}">
                                    <button class="btn btn-outline-danger btn-sm">{{ translate('remove') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-k.card>
@endif
