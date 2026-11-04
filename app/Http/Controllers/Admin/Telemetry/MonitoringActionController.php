<?php

namespace App\Http\Controllers\Admin\Telemetry;

use App\Http\Controllers\BaseController;
use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\MonitoringPermissionService;
use App\Services\Monitoring\Operations\MonitoringAlertRules;
use App\Services\Monitoring\Operations\MonitoringConfiguration;
use App\Services\Monitoring\Operations\MonitoringIncidents;
use App\Services\Monitoring\Operations\MonitoringJournal;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Everything the monitoring console can now do rather than only report.
 *
 * The console shipped GET-only, and the cost was not evenly spread. Some sections merely could not
 * be tuned. Others could not do their job at all: no alert rule could be written, so nothing ever
 * paged anyone; no backup could be recorded, so BackupCheck graded every cPanel install permanently
 * degraded; six incident columns had no writer, so there was no record of who took an incident or
 * what caused it.
 *
 * All of it lands here rather than in MonitoringController, which stays a reader. Every action
 * checks the same settings capability, records to the audit trail and to the monitoring timeline,
 * and returns to the section it came from — an operator acting on what they are looking at should
 * end up looking at it again.
 */
class MonitoringActionController extends BaseController
{
    public function __construct(
        private readonly MonitoringPermissionService $permissions,
        private readonly MonitoringConfiguration $configuration,
        private readonly MonitoringJournal $journal,
        private readonly MonitoringIncidents $incidents,
        private readonly MonitoringAlertRules $rules,
    ) {
    }

    /**
     * BaseController declares this and PHP checks compatibility at class load, so it has to exist
     * even though this controller has no page of its own. It sends the operator to the console.
     */
    public function index(?Request $request = null, ?string $type = null): RedirectResponse
    {
        return redirect()->route('admin.monitoring.index');
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        if ($denied = $this->deny('settings')) {
            return $denied;
        }

        $result = $this->configuration->save((array) $request->input('settings', []));

        if ($result['refused'] !== []) {
            // Named, not swallowed. A refused threshold that reported success would leave an
            // operator believing they had changed something they had not.
            ToastMagic::error(translate('some_settings_were_refused') . ': ' . implode(', ', array_keys($result['refused'])));
        }

        if ($result['saved'] !== []) {
            ToastMagic::success(translate('the_monitoring_settings_were_saved'));
        } elseif ($result['refused'] === []) {
            ToastMagic::success(translate('nothing_changed'));
        }

        return $this->back('settings');
    }

    public function addJourney(Request $request): RedirectResponse
    {
        if ($denied = $this->deny('synthetics')) {
            return $denied;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:96',
            'url' => 'required|string|max:2048',
            'expect_status' => 'nullable|integer|min:100|max:599',
            'expect_text' => 'nullable|string|max:191',
            'max_ms' => 'nullable|integer|min:1|max:600000',
            'timeout' => 'nullable|integer|min:1|max:120',
        ]);

        $result = $this->configuration->addJourney(
            name: $validated['name'],
            url: $validated['url'],
            expectStatus: (int) ($validated['expect_status'] ?? 200),
            expectText: $validated['expect_text'] ?? null,
            maxMs: isset($validated['max_ms']) ? (int) $validated['max_ms'] : null,
            timeout: (int) ($validated['timeout'] ?? 15),
        );

        $result['ok']
            ? ToastMagic::success(translate('the_probe_was_added'))
            : ToastMagic::error(translate($result['error']));

        return $this->back('synthetics');
    }

    public function removeJourney(Request $request): RedirectResponse
    {
        if ($denied = $this->deny('synthetics')) {
            return $denied;
        }

        $this->configuration->removeJourney((string) $request->input('name', ''))
            ? ToastMagic::success(translate('the_probe_was_removed'))
            : ToastMagic::error(translate('that_probe_is_no_longer_configured'));

        return $this->back('synthetics');
    }

    public function annotate(Request $request): RedirectResponse
    {
        if ($denied = $this->deny('timeline')) {
            return $denied;
        }

        $validated = $request->validate([
            'title' => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'severity' => 'nullable|in:' . implode(',', EventLog::SEVERITIES),
            'key' => 'nullable|string|max:96',
            'at' => 'nullable|date',
        ]);

        $this->journal->annotate(
            title: $validated['title'],
            description: $validated['description'] ?? null,
            severity: $validated['severity'] ?? EventLog::INFO,
            key: $validated['key'] ?? null,
            at: $validated['at'] ?? null,
        )
            ? ToastMagic::success(translate('noted_on_the_timeline'))
            : ToastMagic::error(translate('a_note_needs_something_to_say'));

        return $this->back('timeline');
    }

    public function recordBackup(Request $request): RedirectResponse
    {
        if ($denied = $this->deny('backups')) {
            return $denied;
        }

        $validated = $request->validate([
            'kind' => 'required|in:database,files',
            'status' => 'required|in:success,failed',
            'destination' => 'nullable|string|max:191',
            'size_bytes' => 'nullable|integer|min:0',
            'duration' => 'nullable|integer|min:0|max:86400',
            'started_at' => 'nullable|date',
            'error' => 'nullable|string|max:500',
        ]);

        $result = $this->journal->recordBackup($validated);

        $result['ok']
            ? ToastMagic::success(translate('the_backup_was_recorded'))
            : ToastMagic::error(translate('the_backup_could_not_be_recorded') . ': ' . $result['error']);

        return $this->back('backups');
    }

    public function recordRestoreTest(Request $request): RedirectResponse
    {
        if ($denied = $this->deny('backups')) {
            return $denied;
        }

        $validated = $request->validate([
            'backup' => 'nullable|integer|min:1',
            'result' => 'nullable|string|max:191',
            'failed' => 'nullable|boolean',
        ]);

        $result = $this->journal->recordRestoreTest(
            backupId: isset($validated['backup']) ? (int) $validated['backup'] : null,
            failed: (bool) ($validated['failed'] ?? false),
            result: $validated['result'] ?? null,
        );

        $result['ok']
            ? ToastMagic::success(translate('the_restore_test_was_recorded'))
            : ToastMagic::error(translate($result['error']));

        return $this->back('backups');
    }

    public function recordDeployment(Request $request): RedirectResponse
    {
        if ($denied = $this->deny('deployments')) {
            return $denied;
        }

        $validated = $request->validate([
            'release' => 'nullable|string|max:40',
            'sha' => 'nullable|string|max:40',
            'branch' => 'nullable|string|max:96',
            'by' => 'nullable|string|max:96',
            'duration' => 'nullable|integer|min:0|max:86400',
            'migrations' => 'nullable|integer|min:0',
            'status' => 'required|in:success,failed,unknown',
            'notes' => 'nullable|string|max:2000',
        ]);

        $result = $this->journal->recordDeployment($validated);

        $result['ok']
            ? ToastMagic::success(translate('the_deployment_was_recorded'))
            : ToastMagic::error(translate('the_deployment_could_not_be_recorded') . ': ' . $result['error']);

        return $this->back('deployments');
    }

    public function incident(Request $request, string $action, int $id): RedirectResponse
    {
        if ($denied = $this->deny('incidents')) {
            return $denied;
        }

        $actor = auth('admin')->user();
        $name = $actor?->name ?: ($actor?->email ?: 'admin');

        $done = match ($action) {
            'acknowledge' => $this->incidents->acknowledge($id, $name),
            'note' => $this->incidents->note($id, (string) $request->input('note', ''), $name),
            'attribute' => $this->incidents->attribute(
                incidentId: $id,
                cause: (string) $request->input('probable_cause', ''),
                evidence: $request->input('cause_evidence'),
                deploymentId: $request->filled('deployment_id') ? (int) $request->input('deployment_id') : null,
                actor: $name,
            ),
            'resolve' => $this->incidents->resolve($id, $name, $actor?->id),
            default => false,
        };

        $done
            ? ToastMagic::success(translate('the_incident_was_updated'))
            : ToastMagic::error(translate('nothing_to_do_there_the_incident_may_already_be_in_that_state'));

        return $this->back('incidents');
    }

    public function alertRule(Request $request, string $action): RedirectResponse
    {
        if ($denied = $this->deny('alerts')) {
            return $denied;
        }

        $key = (string) $request->input('key', '');

        $outcome = match ($action) {
            'save' => $this->rules->save($key, $request->all()),
            'enable' => ['ok' => $this->rules->setEnabled($key, true), 'error' => 'that_rule_is_no_longer_configured'],
            'silence' => ['ok' => $this->rules->setEnabled($key, false), 'error' => 'that_rule_is_no_longer_configured'],
            'delete' => ['ok' => $this->rules->delete($key), 'error' => 'that_rule_is_no_longer_configured'],
            'seed' => ['ok' => true, 'error' => null, 'created' => $this->rules->seed()],
            default => ['ok' => false, 'error' => 'unknown_action'],
        };

        $outcome['ok']
            ? ToastMagic::success(translate($action === 'seed' ? 'the_shipped_alert_rules_were_installed' : 'the_alert_rule_was_saved'))
            : ToastMagic::error(translate($outcome['error'] ?? 'unknown_action'));

        return $this->back('alerts');
    }

    /**
     * The one gate every action shares.
     *
     * Reading the console is one capability and changing what it does is another, and this is the
     * second — the same one that already guards retrying a failed job.
     */
    private function deny(string $section): ?RedirectResponse
    {
        if ($this->permissions->canEditSettings()) {
            return null;
        }

        ToastMagic::error(translate('access_Denied') . '!');

        return $this->back($section);
    }

    private function back(string $section): RedirectResponse
    {
        return redirect()->route('admin.monitoring.section', ['section' => $section]);
    }
}
