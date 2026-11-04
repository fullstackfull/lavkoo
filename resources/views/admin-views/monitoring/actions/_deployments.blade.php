{{--
    Recording a release, so behaviour can be tied to one.

    The only writer was a command in a deploy script, so on most installs the timeline was
    permanently empty — and an empty deploy timeline is what stops "p95 doubled at 14:20 and the
    deploy was at 14:19" from ever being written.
--}}
@if ($permissions->canEditSettings())
    <x-k.card :title="translate('record_a_release')">
        <form action="{{ route('admin.monitoring.actions.deployments.recorded') }}" method="post" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="deploy-release">{{ translate('release') }}</label>
                <input id="deploy-release" name="release" class="form-control form-control-sm" maxlength="40"
                       placeholder="{{ translate('read_from_the_build_if_left_empty') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="deploy-branch">{{ translate('branch') }}</label>
                <input id="deploy-branch" name="branch" class="form-control form-control-sm" maxlength="96">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="deploy-by">{{ translate('deployed_by') }}</label>
                <input id="deploy-by" name="by" class="form-control form-control-sm" maxlength="96">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="deploy-status">{{ translate('outcome') }}</label>
                <select id="deploy-status" name="status" class="form-control form-control-sm">
                    <option value="success">{{ translate('success') }}</option>
                    <option value="failed">{{ translate('failed') }}</option>
                    <option value="unknown">{{ translate('unknown') }}</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1" for="deploy-notes">{{ translate('notes') }}</label>
                <input id="deploy-notes" name="notes" class="form-control form-control-sm" maxlength="2000">
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100">{{ translate('record') }}</button>
            </div>
        </form>
    </x-k.card>
@endif
