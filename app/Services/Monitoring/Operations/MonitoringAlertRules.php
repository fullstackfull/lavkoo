<?php

namespace App\Services\Monitoring\Operations;

use App\Services\AuditLogger;
use App\Services\Monitoring\Alerting\AlertEvaluator;
use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Editing the rules that decide when somebody is told.
 *
 * The whole alerting chain was built and unreachable — evaluator, incident correlator, cooldown
 * machine, metric resolver and email notifier all present — and three failures compounded so that
 * nothing ever paged anyone. The scheduled evaluator ran without `--seed` and no seeder existed, so
 * a fresh install evaluated zero rules forever. No route could write a rule, so changing a threshold
 * meant a hand-written INSERT. And every shipped rule was created with `notify_email = false` with
 * no screen to turn it on, so an alert that did fire went only to laravel.log.
 *
 * This class closes the second and third; the first is closed by the evaluator seeding itself on its
 * first run, which is idempotent and marked so it happens once rather than nightly.
 */
class MonitoringAlertRules
{
    public const OPERATORS = ['>', '>=', '<', '<=', '=='];

    public function __construct(
        private readonly AlertEvaluator $evaluator,
        private readonly EventLog $events,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<int, object> every rule, enabled or not — the page edits both. */
    public function all(): array
    {
        try {
            return $this->connection()->table('monitoring_alert_rules')->orderBy('key')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function seed(): int
    {
        $created = $this->evaluator->seedDefaults(force: true);

        if ($created > 0) {
            $this->audit->record(action: 'monitoring.alert_rules_seeded', after: ['created' => $created]);
            $this->events->record(
                type: EventLog::CONFIG,
                severity: EventLog::INFO,
                title: 'Alert rules installed',
                context: ['created' => $created],
            );
        }

        return $created;
    }

    /**
     * Create or update one rule.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error: ?string}
     */
    public function save(string $key, array $input): array
    {
        $key = trim($key);

        if ($key === '' || !preg_match('/^[a-z0-9_.\-]{1,96}$/i', $key)) {
            return ['ok' => false, 'error' => 'a_rule_key_is_letters_numbers_dots_and_dashes'];
        }

        $operator = in_array($input['operator'] ?? null, self::OPERATORS, true) ? $input['operator'] : '>';
        $warning = $this->threshold($input['warning_threshold'] ?? null);
        $critical = $this->threshold($input['critical_threshold'] ?? null);

        if ($warning === null && $critical === null) {
            // A rule with neither threshold can never fire, and would sit on the page looking like
            // cover it is not providing.
            return ['ok' => false, 'error' => 'a_rule_needs_a_warning_or_a_critical_threshold'];
        }

        $existing = $this->find($key);

        $row = [
            'name' => mb_substr(trim((string) ($input['name'] ?? $key)), 0, 191),
            'metric' => mb_substr(trim((string) ($input['metric'] ?? '')), 0, 96),
            'label' => mb_substr(trim((string) ($input['label'] ?? '')), 0, 96),
            'operator' => $operator,
            'warning_threshold' => $warning,
            'critical_threshold' => $critical,
            'recovery_threshold' => $this->threshold($input['recovery_threshold'] ?? null),
            'for_seconds' => max(0, min(86400, (int) ($input['for_seconds'] ?? 120))),
            'cooldown_seconds' => max(0, min(86400, (int) ($input['cooldown_seconds'] ?? 900))),
            'enabled' => (bool) ($input['enabled'] ?? false),
            'notify_email' => (bool) ($input['notify_email'] ?? false),
            'notify_channels' => !empty($input['notify_channels']) ? mb_substr((string) $input['notify_channels'], 0, 191) : null,
            'description' => !empty($input['description']) ? mb_substr((string) $input['description'], 0, 191) : null,
            'updated_at' => Clock::stamp(),
        ];

        if ($row['metric'] === '') {
            return ['ok' => false, 'error' => 'a_rule_needs_a_metric_to_watch'];
        }

        try {
            if ($existing === null) {
                $this->connection()->table('monitoring_alert_rules')->insert($row + ['key' => $key, 'created_at' => Clock::stamp()]);
            } else {
                $this->connection()->table('monitoring_alert_rules')->where('key', $key)->update($row);
            }
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        $this->audit->record(
            action: $existing === null ? 'monitoring.alert_rule_created' : 'monitoring.alert_rule_updated',
            subject: ['type' => 'monitoring_alert_rule', 'id' => $key],
            before: $existing === null ? null : (array) $existing,
            after: $row,
        );

        $this->events->record(
            type: EventLog::CONFIG,
            severity: EventLog::INFO,
            title: 'Alert rule ' . ($existing === null ? 'created' : 'changed') . ': ' . $key,
            key: $key,
            context: ['enabled' => $row['enabled'], 'notify_email' => $row['notify_email']],
        );

        return ['ok' => true, 'error' => null];
    }

    /** Turn a rule off without losing it, which is what silencing during an incident needs. */
    public function setEnabled(string $key, bool $enabled): bool
    {
        if ($this->find($key) === null) {
            return false;
        }

        $this->connection()->table('monitoring_alert_rules')->where('key', $key)
            ->update(['enabled' => $enabled, 'updated_at' => Clock::stamp()]);

        $this->audit->record(
            action: $enabled ? 'monitoring.alert_rule_enabled' : 'monitoring.alert_rule_silenced',
            subject: ['type' => 'monitoring_alert_rule', 'id' => $key],
        );

        $this->events->record(
            type: EventLog::CONFIG,
            severity: $enabled ? EventLog::INFO : EventLog::WARNING,
            title: 'Alert rule ' . ($enabled ? 'enabled' : 'silenced') . ': ' . $key,
            key: $key,
        );

        return true;
    }

    public function delete(string $key): bool
    {
        $existing = $this->find($key);

        if ($existing === null) {
            return false;
        }

        $this->connection()->table('monitoring_alert_rules')->where('key', $key)->delete();
        // The live state goes with it: a state row for a rule that no longer exists would keep an
        // incident open that nothing can ever recover.
        $this->connection()->table('monitoring_alert_states')->where('rule_key', $key)->delete();

        $this->audit->record(
            action: 'monitoring.alert_rule_deleted',
            subject: ['type' => 'monitoring_alert_rule', 'id' => $key],
            before: (array) $existing,
        );

        $this->events->record(
            type: EventLog::CONFIG,
            severity: EventLog::WARNING,
            title: 'Alert rule deleted: ' . $key,
            key: $key,
        );

        return true;
    }

    private function threshold(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    private function find(string $key): ?object
    {
        try {
            return $this->connection()->table('monitoring_alert_rules')->where('key', $key)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection'));
    }
}
