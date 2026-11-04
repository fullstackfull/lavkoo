{{--
    A note on the axis, written by the person reading the chart.

    It existed only as a shell command, which meant the operator who could see the anomaly was never
    the operator who could label it — and an unlabelled spike is re-investigated every time somebody
    new opens the page.
--}}
@if ($permissions->canEditSettings())
    <x-k.card :title="translate('add_a_note_to_the_timeline')">
        <form action="{{ route('admin.monitoring.actions.annotate') }}" method="post" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label fs-12 mb-1" for="annotate-title">{{ translate('what_happened') }}</label>
                <input id="annotate-title" name="title" class="form-control form-control-sm" maxlength="191" required
                       placeholder="{{ translate('supplier_import_started') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1" for="annotate-description">{{ translate('detail_optional') }}</label>
                <input id="annotate-description" name="description" class="form-control form-control-sm" maxlength="2000">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="annotate-severity">{{ translate('severity') }}</label>
                <select id="annotate-severity" name="severity" class="form-control form-control-sm">
                    @foreach (\App\Services\Monitoring\EventLog::SEVERITIES as $severity)
                        <option value="{{ $severity }}">{{ translate($severity) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="annotate-at">{{ translate('when_if_not_now') }}</label>
                <input id="annotate-at" name="at" type="datetime-local" class="form-control form-control-sm">
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100">{{ translate('add') }}</button>
            </div>
        </form>
    </x-k.card>
@endif
