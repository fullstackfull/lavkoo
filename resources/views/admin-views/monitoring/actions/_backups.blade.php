{{--
    Recording a backup and a restore test.

    BackupCheck grades a shop degraded until a backup is recorded, and the only writer was a command
    an operator had to bolt into their own backup script — so every install deploying through cPanel
    or the built-in updater was permanently degraded with no way in the product to say otherwise.

    Recording is a statement of fact, not a backup: this does not take one. The wording says so.
--}}
@if ($permissions->canEditSettings())
    <x-k.card :title="translate('record_a_backup_that_has_already_run')">
        <p class="mon-note" style="margin-block-start:0">
            {{ translate('this_records_that_a_backup_happened_it_does_not_take_one') }}.
        </p>
        <form action="{{ route('admin.monitoring.actions.backups.recorded') }}" method="post" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="backup-kind">{{ translate('kind') }}</label>
                <select id="backup-kind" name="kind" class="form-control form-control-sm">
                    <option value="database">{{ translate('database') }}</option>
                    <option value="files">{{ translate('files') }}</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="backup-status">{{ translate('outcome') }}</label>
                <select id="backup-status" name="status" class="form-control form-control-sm">
                    <option value="success">{{ translate('success') }}</option>
                    <option value="failed">{{ translate('failed') }}</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1" for="backup-destination">{{ translate('where_it_was_written') }}</label>
                <input id="backup-destination" name="destination" class="form-control form-control-sm" maxlength="191">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="backup-size">{{ translate('size_in_bytes') }}</label>
                <input id="backup-size" name="size_bytes" type="number" min="0" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="backup-started">{{ translate('started_at') }}</label>
                <input id="backup-started" name="started_at" type="datetime-local" class="form-control form-control-sm">
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100">{{ translate('record') }}</button>
            </div>
        </form>

        <hr class="my-3">

        <form action="{{ route('admin.monitoring.actions.backups.restore-tested') }}" method="post" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-2">
                <label class="form-label fs-12 mb-1" for="restore-backup">{{ translate('backup_id_optional') }}</label>
                <input id="restore-backup" name="backup" type="number" min="1" class="form-control form-control-sm"
                       placeholder="{{ translate('newest_successful') }}">
            </div>
            <div class="col-md-6">
                <label class="form-label fs-12 mb-1" for="restore-result">{{ translate('what_the_test_found') }}</label>
                <input id="restore-result" name="result" class="form-control form-control-sm" maxlength="191"
                       placeholder="{{ translate('restored_to_staging_in_four_minutes') }}">
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="restore-failed" name="failed" value="1">
                    <label class="form-check-label fs-12" for="restore-failed">{{ translate('the_restore_did_not_work') }}</label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary btn-sm w-100">{{ translate('record_restore_test') }}</button>
            </div>
        </form>
    </x-k.card>
@endif
