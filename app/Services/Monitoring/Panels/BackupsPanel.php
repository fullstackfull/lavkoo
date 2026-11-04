<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * When a backup last succeeded, how big it was, and whether anybody has ever restored one.
 *
 * This application does not take backups and is not able to. Every row it reads was written by an
 * operator's own backup script calling `php artisan monitoring:backup-recorded`, so an empty table
 * here is a statement about that script and no statement at all about whether the shop is being
 * backed up. It is never drawn as "no backups": the page names the command that writes a row and
 * where it goes in the script, because the fix for an empty backups page is a line of shell, not a
 * setting in this admin.
 *
 * Three distinctions are made in the panel rather than left to the view, because a page that gets
 * any of them wrong reads as reassurance at the moment it should read as an alarm.
 *
 * Age is measured against the newest SUCCESSFUL backup, while the freshness verdict also looks at
 * the newest row of any status. A failed backup last night with a good one from the night before
 * is not a twenty-six-hour-old backup problem, it is a broken backup job — and reporting only the
 * age would hide it behind a green number.
 *
 * Size is published as a trend and not only as a number. A backup that suddenly halves is the
 * signal this section exists for: the job still exits zero, the row still says success, the age is
 * still fresh, and half the database is missing from the artefact. Every drop past a stated
 * percentage is named with both sizes beside it. A backup whose size was never recorded is counted
 * separately and never plotted as zero bytes, which would draw exactly the cliff this looks for.
 *
 * Restore testing has two negative answers that must never render the same. "Never restore-tested"
 * is an unopened box; "the last restore test failed" is a box that was opened and was empty. The
 * first is a task, the second is an emergency, and they are carried as different verdicts with
 * different states so no view can collapse them.
 */
class BackupsPanel implements Panel
{
    /** Where every row on this page comes from. */
    private const SOURCE = 'monitoring_backups';

    /** The backup check's own verdict, written by `php artisan monitoring:check`. */
    private const CHECK_SOURCE = "monitoring_check_results (check_key='backup')";

    /** How often the scheduler runs the check that grades the age, per bootstrap/app.php. */
    private const CHECK_CADENCE_MINUTES = 5;

    /** How many recorded backups the history table carries. */
    private const MAX_HISTORY = 50;

    /** Upper bound on the grouped read of check outcomes: there are six possible statuses. */
    private const MAX_CHECK_STATUSES = 10;

    /**
     * The shrink that gets named.
     *
     * A halving is a fifty per cent drop; the line is drawn below that so a backup that nearly
     * halves is flagged too, and the measured percentage is published beside every flag so the
     * threshold never has to be guessed at from the fact that a row is listed.
     */
    private const SIZE_DROP_PERCENT = 40.0;

    /** The two commands that can write to this table. Nothing else in this build can. */
    private const RECORD_COMMAND = 'php artisan monitoring:backup-recorded';

    private const RESTORE_COMMAND = 'php artisan monitoring:restore-tested';

    private const RECORD_CLASS = \App\Console\Commands\MonitoringBackupRecorded::class;

    private const RESTORE_CLASS = \App\Console\Commands\MonitoringRestoreTested::class;

    /** Values the recorder writes. Anything else in the column was written by somebody else. */
    private const KINDS = ['database', 'files'];

    private const STATUSES = ['success', 'failed'];

    /** The statuses CheckResult can carry, so a stored value is only translated when it is ours. */
    private const CHECK_STATUSES = ['ok', 'degraded', 'failing', 'unknown', 'not_configured', 'not_supported'];

    /**
     * The freshness verdicts, as fixed keys the view translates.
     *
     * Five answers rather than a boolean: "no backup has ever been recorded", "the last one failed"
     * and "the last one is three days old" send an operator to three different places.
     */
    private const FRESH = 'fresh';
    private const STALE = 'stale';
    private const OVERDUE = 'overdue';
    private const LAST_FAILED = 'last_backup_failed';
    private const NEVER_RECORDED = 'never_recorded';
    private const AGE_UNREADABLE = 'age_unreadable';

    /** The restore-test verdicts, as fixed keys. Passed and never-tested are opposite facts. */
    private const RESTORE_PASSED = 'passed';
    private const RESTORE_FAILED = 'failed';
    private const RESTORE_UNCLEAR = 'recorded_without_a_result';
    private const RESTORE_NEVER = 'never_restore_tested';
    private const RESTORE_NO_BACKUP = 'no_backup_to_test';
    private const RESTORE_UNKNOWN = 'unknown';

    /** The prefix `monitoring:restore-tested --failed` writes into the result column. */
    private const FAILED_PREFIX = 'FAILED:';

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly MonitoringSettings $settings,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $thresholds = $this->thresholds();
        $freshness = $this->freshness($thresholds);
        $history = $this->history($range);
        $restore = $this->restore($history);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'recorder' => $this->recorder(),
            'freshness' => $freshness,
            'headline' => $this->headline($freshness, $history, $restore),
            'check' => $this->check($range),
            'history' => $history,
            'size_trend' => $this->sizeTrend($history),
            'restore' => $restore,
            'thresholds' => $thresholds,
            // This panel reads no collector: every figure on it comes from tables. The key is kept
            // so the section carries the same shape as the rest, and the view's footer stays silent.
            'unrendered' => [],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Nothing here takes a backup

    /**
     * What can write to this table, and the exact line an operator adds to their backup script.
     *
     * The most important sentence on the page and the one a dashboard normally leaves out. Whether
     * the backup script calls the recorder cannot be read from here — a row in the table is the
     * only evidence of that — so the command is stated whether or not any row exists.
     *
     * @return array<string, mixed>
     */
    private function recorder(): array
    {
        $recordInstalled = class_exists(self::RECORD_CLASS);
        $restoreInstalled = class_exists(self::RESTORE_CLASS);

        return [
            'state' => $recordInstalled && $restoreInstalled ? 'ok' : 'not_configured',
            // Stated as a fact rather than inferred from an empty table: no code path in this
            // application runs mysqldump, tars a directory or writes an artefact anywhere.
            'takes_backups' => false,
            'record_installed' => $recordInstalled,
            'restore_installed' => $restoreInstalled,
            'record_command' => self::RECORD_COMMAND,
            'restore_command' => self::RESTORE_COMMAND,
            'note' => $recordInstalled && $restoreInstalled
                ? 'This application never takes a backup. It records that one happened, when the backup script tells it — so an empty table here is evidence about that script and none at all about whether the data is safe.'
                : 'This application never takes a backup, and the command that records one is not installed in this build, so nothing can write a row to this table at all.',
            'remedy' => $recordInstalled
                ? 'Add ' . self::RECORD_COMMAND . ' as the last line of whatever script takes the backup, on both the success and the failure path.'
                : 'Restore app/Console/Commands/MonitoringBackupRecorded.php, then call ' . self::RECORD_COMMAND . ' from the backup script.',
            // Written as separate lines so the shell continuations survive JSON and render as a
            // block an operator can copy verbatim into their own script.
            'example' => [
                'mysqldump --single-transaction --quick "$DB_NAME" > /backups/db-$(date +%F).sql \\',
                '  && php artisan monitoring:backup-recorded --kind=database --destination=/backups/db-$(date +%F).sql --size-bytes=$(stat -c%s /backups/db-$(date +%F).sql) --duration=$SECONDS \\',
                '  || php artisan monitoring:backup-recorded --kind=database --status=failed --error="mysqldump exited $?"',
            ],
            'restore_example' => [
                '# after restoring that dump somewhere and checking it came back:',
                'php artisan monitoring:restore-tested --result="restored to staging, 131 tables"',
                'php artisan monitoring:restore-tested --failed --result="import stopped at orders"',
            ],
            'source' => 'app/Console/Commands/MonitoringBackupRecorded.php, app/Console/Commands/MonitoringRestoreTested.php',
        ];
    }

    // -------------------------------------------------------------------------------------------
    // How old the newest good backup is

    /**
     * The age of the newest successful backup, against the age the check grades on.
     *
     * Deliberately not bounded by the selected range. "How old is the backup" is a question about
     * the whole table, and answering it only for the last hour would report every shop as having
     * no backup whenever somebody picked a short window.
     *
     * @param  array<string, mixed>  $thresholds
     * @return array<string, mixed>
     */
    private function freshness(array $thresholds): array
    {
        try {
            $connection = $this->reader->connection();

            $newest = $connection->table(self::SOURCE)
                ->orderByDesc('started_at')
                ->limit(1)
                ->first();

            $newestSuccessful = $newest !== null && (string) $newest->status === 'success'
                ? $newest
                : $connection->table(self::SOURCE)
                    ->where('status', 'success')
                    ->orderByDesc('started_at')
                    ->limit(1)
                    ->first();
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing this read costs the page its
            // age card, while letting it escape would blank the recorder instructions too — the
            // one thing this section can say when there is nothing in the table at all.
            return array_merge($this->emptyFreshness('failed', $thresholds), [
                'note' => Metric::describeFailure($exception),
            ]);
        }

        if ($newest === null) {
            return array_merge($this->emptyFreshness('no_data', $thresholds), [
                'verdict' => self::NEVER_RECORDED,
                'note' => 'No backup has ever been recorded. This says nothing about whether backups are being taken: nothing in this application takes one or is told about one until the backup script says so.',
                'remedy' => 'Call ' . self::RECORD_COMMAND . ' at the end of the backup script; once one row exists, its age is graded every ' . self::CHECK_CADENCE_MINUTES . ' minutes by the backup check.',
            ]);
        }

        $ageMinutes = $newestSuccessful === null ? null : $this->minutesSince($newestSuccessful->started_at);
        $newestIsSuccessful = (string) $newest->status === 'success';

        return [
            'state' => 'ok',
            'note' => $newestSuccessful === null
                ? 'Every backup on record failed, so there is no successful backup to measure the age of.'
                : null,
            'remedy' => null,
            'source' => self::SOURCE,
            'verdict' => $this->freshnessVerdict($newestIsSuccessful, $ageMinutes, $thresholds),
            'age_minutes' => $ageMinutes,
            // Hours to one decimal: the threshold is stated in hours, and a backup taken forty
            // minutes ago is not zero hours old.
            'age_hours' => $ageMinutes === null ? null : round($ageMinutes / 60, 1),
            'newest' => $this->summarise($newest),
            'newest_is_successful' => $newestIsSuccessful,
            'newest_successful' => $newestSuccessful === null ? null : $this->summarise($newestSuccessful),
            'warning_hours' => $thresholds['backup_age_warning_hours'],
            'critical_hours' => $thresholds['backup_age_critical_hours'],
        ];
    }

    /**
     * @param  array<string, mixed>  $thresholds
     * @return array<string, mixed>
     */
    private function emptyFreshness(string $state, array $thresholds): array
    {
        return [
            'state' => $state,
            'note' => null,
            'remedy' => null,
            'source' => self::SOURCE,
            'verdict' => $state === 'failed' ? self::AGE_UNREADABLE : self::NEVER_RECORDED,
            'age_minutes' => null,
            'age_hours' => null,
            'newest' => null,
            'newest_is_successful' => null,
            'newest_successful' => null,
            'warning_hours' => $thresholds['backup_age_warning_hours'],
            'critical_hours' => $thresholds['backup_age_critical_hours'],
        ];
    }

    /**
     * The same grading the backup check applies, so the page and the check cannot disagree.
     *
     * A failed newest backup outranks the age: a successful backup from the night before keeps the
     * age green while the job that produced it is broken, and the green number is what somebody
     * would go to sleep on.
     *
     * @param  array<string, mixed>  $thresholds
     */
    private function freshnessVerdict(bool $newestIsSuccessful, ?int $ageMinutes, array $thresholds): string
    {
        if (!$newestIsSuccessful) {
            return self::LAST_FAILED;
        }

        if ($ageMinutes === null) {
            return self::AGE_UNREADABLE;
        }

        $hours = $ageMinutes / 60;

        return match (true) {
            $hours > $thresholds['backup_age_critical_hours'] => self::OVERDUE,
            $hours > $thresholds['backup_age_warning_hours'] => self::STALE,
            default => self::FRESH,
        };
    }

    // -------------------------------------------------------------------------------------------
    // The cards above the table

    /**
     * Readings drawn as single values, each built only from a read that actually succeeded.
     *
     * Nothing is published from a block that failed or holds nothing: a card reading "0 backups"
     * above a table that could not be queried is the exact confusion this system exists to
     * prevent, and the block's own state says it better in a sentence.
     *
     * @param  array<string, mixed>  $freshness
     * @param  array<string, mixed>  $history
     * @param  array<string, mixed>  $restore
     * @return array<string, Metric>
     */
    private function headline(array $freshness, array $history, array $restore): array
    {
        $headline = [];

        if ($freshness['state'] === 'ok') {
            $headline['hours_since_the_last_successful_backup'] = $freshness['age_hours'] === null
                ? Metric::noData(
                    source: self::SOURCE,
                    note: $freshness['note'] ?? 'No successful backup has a readable start time, so its age cannot be measured.',
                )
                : Metric::of(
                    value: $freshness['age_hours'],
                    source: self::SOURCE,
                    unit: 'h',
                    note: 'Measured from the newest backup whose status is success.',
                );

            $size = $freshness['newest_successful']['size_bytes'] ?? null;
            $headline['size_of_the_last_successful_backup'] = $size === null
                ? Metric::noData(
                    source: self::SOURCE,
                    note: 'The backup script recorded no size for it. Pass --size-bytes so a shrinking artefact can be seen.',
                )
                : Metric::of(
                    value: round($size / 1048576, 1),
                    source: self::SOURCE,
                    unit: 'MB',
                );
        }

        if ($history['state'] === 'ok' || $history['rows'] !== []) {
            $headline['backups_recorded_in_this_window'] = Metric::of(
                value: count($history['rows']),
                source: self::SOURCE,
                note: $history['truncated']
                    ? 'The window holds more backups than this page lists, so this counts the listed ones.'
                    : 'Rows recorded in this window, which is not the same as backups taken: one that never calls the recorder leaves nothing behind.',
            );

            $failed = 0;
            foreach ($history['rows'] as $row) {
                if ($row['status'] === 'failed') {
                    $failed++;
                }
            }

            $headline['failed_backups_in_this_window'] = Metric::of(
                value: $failed,
                source: self::SOURCE,
                note: 'Counted from the status the backup script reported; a backup that died before calling the recorder reports nothing at all.',
            );
        }

        $headline['hours_since_the_last_restore_test'] = $this->restoreAgeMetric($restore);

        return $headline;
    }

    /**
     * @param  array<string, mixed>  $restore
     */
    private function restoreAgeMetric(array $restore): Metric
    {
        return match ($restore['verdict']) {
            self::RESTORE_PASSED, self::RESTORE_UNCLEAR => $restore['age_hours'] === null
                ? Metric::noData(source: self::SOURCE, note: 'The restore test has no readable timestamp.')
                : Metric::of(value: $restore['age_hours'], source: self::SOURCE, unit: 'h'),
            // A failed test is not an age worth printing beside the good ones — the failure is the
            // reading, and it is drawn in full in its own block.
            self::RESTORE_FAILED => Metric::collectorOffline(
                source: self::SOURCE,
                note: 'The last recorded restore test failed, so nothing here has been shown to restore.',
                remedy: 'Restore the newest backup somewhere and record the outcome: ' . self::RESTORE_COMMAND . '.',
            ),
            self::RESTORE_NEVER => Metric::notConfigured(
                source: self::SOURCE,
                remedy: self::RESTORE_COMMAND . ' --result="restored to staging"',
                note: 'No backup has ever been restore-tested. An untested backup is a hope rather than a backup.',
            ),
            self::RESTORE_NO_BACKUP => Metric::noData(
                source: self::SOURCE,
                note: 'There is no backup on record to restore-test.',
            ),
            default => Metric::noData(
                source: self::SOURCE,
                note: $restore['note'] ?? 'The restore-test record could not be read.',
            ),
        };
    }

    // -------------------------------------------------------------------------------------------
    // What the backup check made of it

    /**
     * The verdict written by the check that watches this table, and how recently it ran.
     *
     * Read rather than recomputed. The check runs every few minutes inside `monitoring:check` and
     * is what the alert engine acts on, so a page that graded the age itself and disagreed with it
     * would leave an operator with two answers and no way to tell which one alerted.
     *
     * @return array<string, mixed>
     */
    private function check(string $range): array
    {
        try {
            $connection = $this->reader->connection();

            $latest = $connection->table('monitoring_check_results')
                ->where('check_key', 'backup')
                ->orderByDesc('checked_at')
                ->limit(1)
                ->first(['status', 'detail', 'context', 'duration_ms', 'checked_at']);

            $counts = $connection->table('monitoring_check_results')
                ->where('check_key', 'backup')
                ->where('checked_at', '>=', $this->reader->since($range))
                ->groupBy('status')
                ->limit(self::MAX_CHECK_STATUSES)
                ->get(['status', $connection->raw('COUNT(*) AS runs')]);
        } catch (\Throwable $exception) {
            return array_merge($this->emptyCheck('failed'), [
                'note' => Metric::describeFailure($exception),
            ]);
        }

        if ($latest === null) {
            return array_merge($this->emptyCheck('no_data'), [
                'note' => 'The backup check has never recorded a result. It runs inside `php artisan monitoring:check`, which the scheduler calls every ' . self::CHECK_CADENCE_MINUTES . ' minutes — an empty history here is a statement about that command, not a clean bill of health.',
                'remedy' => 'Run `php artisan monitoring:check` once by hand, then confirm the scheduler is firing it: `php artisan schedule:list`.',
            ]);
        }

        $byStatus = [];
        $runs = 0;
        foreach ($counts as $row) {
            $byStatus[(string) $row->status] = (int) $row->runs;
            $runs += (int) $row->runs;
        }

        $status = (string) $latest->status;

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => self::CHECK_SOURCE,
            'status' => $status,
            'status_known' => in_array($status, self::CHECK_STATUSES, true),
            // Authored by BackupCheck in English and never translated: it is composed at runtime
            // out of the age it measured.
            'detail' => $this->text($latest->detail),
            'checked_at' => $this->displayStamp($latest->checked_at),
            'age_minutes' => $this->minutesSince($latest->checked_at),
            'context' => $this->scalars($latest->context),
            'runs_in_window' => $runs,
            'by_status' => $byStatus,
            'cadence_minutes' => self::CHECK_CADENCE_MINUTES,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyCheck(string $state): array
    {
        return [
            'state' => $state,
            'note' => null,
            'remedy' => null,
            'source' => self::CHECK_SOURCE,
            'status' => null,
            'status_known' => false,
            'detail' => null,
            'checked_at' => null,
            'age_minutes' => null,
            'context' => null,
            'runs_in_window' => null,
            'by_status' => [],
            'cadence_minutes' => self::CHECK_CADENCE_MINUTES,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Every backup recorded in the window

    /**
     * The backups recorded inside the selected window, newest first.
     *
     * Bounded three ways — the window, the indexed started_at it is bounded on, and a hard row
     * limit — even though this is the smallest table in the store: a shop that backs up hourly and
     * keeps a year of rows is one query away from being the reason its own dashboard is slow.
     *
     * @return array<string, mixed>
     */
    private function history(string $range): array
    {
        try {
            $rows = $this->reader->connection()->table(self::SOURCE)
                ->where('started_at', '>=', $this->reader->since($range))
                ->orderByDesc('started_at')
                ->limit(self::MAX_HISTORY + 1)
                ->get();
        } catch (\Throwable $exception) {
            return array_merge($this->emptyHistory('failed'), [
                'note' => Metric::describeFailure($exception),
            ]);
        }

        $truncated = $rows->count() > self::MAX_HISTORY;
        $listed = $rows->take(self::MAX_HISTORY);

        if ($listed->isEmpty()) {
            return array_merge($this->emptyHistory('no_data'), [
                'note' => 'No backup was recorded inside this window. Backups are rare events and most windows on this page are shorter than the interval between them, so an empty table here is usually the range rather than a missing backup — the age above is measured against the whole table and is the figure that answers that.',
                'remedy' => 'Widen the range, or add ' . self::RECORD_COMMAND . ' to the backup script if nothing is reporting at all.',
            ]);
        }

        $history = [];
        $previousSize = null;
        // Oldest first while the sizes are compared, so each row is measured against the backup
        // that came before it rather than the one that came after. Only successful backups take
        // part: a failed run's half-written artefact is not the size of a backup, and letting one
        // anchor the comparison would report the next good backup as having doubled.
        foreach ($listed->reverse() as $row) {
            $summary = $this->summarise($row);
            $comparable = $summary['status'] === 'success' && $summary['size_bytes'] !== null;

            $summary['size_change_percent'] = $comparable
                ? $this->sizeChangePercent($summary['size_bytes'], $previousSize)
                : null;
            $summary['compared_with_size_bytes'] = $summary['size_change_percent'] === null ? null : $previousSize;

            if ($comparable) {
                $previousSize = $summary['size_bytes'];
            }

            $history[] = $summary;
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => self::SOURCE,
            'rows' => array_reverse($history),
            'truncated' => $truncated,
            'limit' => self::MAX_HISTORY,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyHistory(string $state): array
    {
        return [
            'state' => $state,
            'note' => null,
            'remedy' => null,
            'source' => self::SOURCE,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_HISTORY,
        ];
    }

    /**
     * One recorded backup, with everything the view needs about it.
     *
     * @return array<string, mixed>
     */
    private function summarise(object $row): array
    {
        $kind = (string) ($row->kind ?? '');
        $status = (string) ($row->status ?? '');
        $result = $this->text($row->restore_test_result ?? null);
        $testedAt = $row->restore_tested_at ?? null;

        return [
            'kind' => $kind,
            'kind_known' => in_array($kind, self::KINDS, true),
            'status' => $status,
            'status_known' => in_array($status, self::STATUSES, true),
            // Typed at a backup shell and stored unfiltered, so it is redacted on the way out like
            // any other free text this system did not author.
            'destination' => $this->text($row->destination ?? null),
            'size_bytes' => $this->integerOrNull($row->size_bytes ?? null),
            'duration_seconds' => $this->integerOrNull($row->duration_seconds ?? null),
            'error' => $this->firstLine($row->error ?? null),
            'started_at' => $this->displayStamp($row->started_at ?? null),
            // Taken from the stored UTC value rather than from the display string above: a chart
            // point is read by the browser as an instant, and re-parsing an already-converted
            // stamp as UTC would move every point by the display timezone's offset.
            'started_at_iso' => $this->isoStamp($row->started_at ?? null),
            'finished_at' => $this->displayStamp($row->finished_at ?? null),
            'age_minutes' => $this->minutesSince($row->started_at ?? null),
            'restore_tested_at' => $this->displayStamp($testedAt),
            'restore_test_result' => $result,
            'restore_verdict' => $this->restoreVerdict($testedAt, $result),
            'size_change_percent' => null,
            'compared_with_size_bytes' => null,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Whether the artefact is still the right size

    /**
     * The size of each successful backup across the window, and every sudden shrink in it.
     *
     * The one reading on this page that catches a backup which is running, exiting zero and
     * recording success while producing half a database. Backups whose size was never recorded are
     * counted apart and never plotted: a missing size drawn as zero bytes would invent the exact
     * cliff this is looking for.
     *
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function sizeTrend(array $history): array
    {
        $source = self::SOURCE . '.size_bytes';

        if ($history['state'] !== 'ok') {
            return [
                'state' => $history['state'],
                'note' => $history['note'] ?? 'The backup history could not be read, so no size trend can be drawn from it.',
                'remedy' => $history['remedy'] ?? null,
                'source' => $source,
                'points' => [],
                'drops' => [],
                'drop_threshold_percent' => self::SIZE_DROP_PERCENT,
                'sized' => 0,
                'unsized' => 0,
            ];
        }

        $successful = array_values(array_filter(
            array_reverse($history['rows']),
            static fn (array $row) => $row['status'] === 'success',
        ));

        $points = [];
        $drops = [];
        $unsized = 0;
        $previous = null;

        foreach ($successful as $row) {
            if ($row['size_bytes'] === null) {
                $unsized++;

                continue;
            }

            $change = $this->sizeChangePercent($row['size_bytes'], $previous['size_bytes'] ?? null);

            $points[] = [
                't' => $row['started_at_iso'],
                'started_at' => $row['started_at'],
                'bytes' => $row['size_bytes'],
                'kind' => $row['kind'],
                'change_percent' => $change,
            ];

            if ($change !== null && $change <= -self::SIZE_DROP_PERCENT) {
                $drops[] = [
                    'started_at' => $row['started_at'],
                    'bytes' => $row['size_bytes'],
                    'previous_started_at' => $previous['started_at'] ?? null,
                    'previous_bytes' => $previous['size_bytes'] ?? null,
                    'change_percent' => $change,
                ];
            }

            $previous = $row;
        }

        if (count($points) < 2) {
            return [
                // One sample is a reading, not a trend, and a single point drawn as a flat line
                // would say the size is steady on the strength of one measurement.
                'state' => 'no_data',
                'note' => $points === []
                    ? ($unsized > 0
                        ? 'No successful backup in this window recorded its size, so there is nothing to trend. A size is only stored when the backup script passes --size-bytes.'
                        : 'No successful backup with a recorded size falls inside this window.')
                    : 'Only one successful backup with a recorded size falls inside this window, and a trend needs two.',
                'remedy' => 'Widen the range, or pass --size-bytes=$(stat -c%s <artefact>) to ' . self::RECORD_COMMAND . ' so every backup records how big it was.',
                'source' => $source,
                'points' => $points,
                'drops' => $drops,
                'drop_threshold_percent' => self::SIZE_DROP_PERCENT,
                'sized' => count($points),
                'unsized' => $unsized,
            ];
        }

        return [
            'state' => 'ok',
            'note' => $unsized > 0
                ? 'Successful backups whose size was not recorded are left out of this line rather than drawn as zero bytes.'
                : null,
            'remedy' => null,
            'source' => $source,
            'points' => $points,
            'drops' => $drops,
            'drop_threshold_percent' => self::SIZE_DROP_PERCENT,
            'sized' => count($points),
            'unsized' => $unsized,
        ];
    }

    /** The change against the previous size, or null when there is nothing honest to compare. */
    private function sizeChangePercent(?int $bytes, ?int $previous): ?float
    {
        if ($bytes === null || $previous === null || $previous <= 0) {
            return null;
        }

        return round(100 * ($bytes - $previous) / $previous, 1);
    }

    // -------------------------------------------------------------------------------------------
    // Has anyone ever restored one

    /**
     * The most recent restore test on record, and what it found.
     *
     * Not bounded by the window: a restore test performed last month is still the answer to "has
     * anyone restored one of these", and reporting it as never tested because somebody chose a
     * one-hour range would turn a completed task into an emergency.
     *
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function restore(array $history): array
    {
        try {
            $connection = $this->reader->connection();

            // One row, and the table holds one row per backup run — a few hundred at a busy shop
            // that has never pruned them — so the ordering this cannot take from an index costs
            // nothing measurable.
            $tested = $connection->table(self::SOURCE)
                ->whereNotNull('restore_tested_at')
                ->orderByDesc('restore_tested_at')
                ->limit(1)
                ->first();

            $anyBackup = $tested !== null || $connection->table(self::SOURCE)
                ->orderByDesc('started_at')
                ->limit(1)
                ->exists();
        } catch (\Throwable $exception) {
            return array_merge($this->emptyRestore('failed', self::RESTORE_UNKNOWN), [
                'note' => Metric::describeFailure($exception),
            ]);
        }

        [$tested_in_window, $untested] = $this->restoreCounts($history);

        if ($tested === null) {
            return array_merge(
                $this->emptyRestore(
                    $anyBackup ? 'not_configured' : 'no_data',
                    $anyBackup ? self::RESTORE_NEVER : self::RESTORE_NO_BACKUP,
                ),
                [
                    'note' => $anyBackup
                        ? 'No backup on record has ever been restore-tested. Nothing here has been shown to restore, which is a different and much weaker statement than the green age above.'
                        : 'There is no backup on record, so there is nothing to restore-test.',
                    'remedy' => $anyBackup
                        ? 'Restore the newest backup somewhere disposable, then record what happened: ' . self::RESTORE_COMMAND . ' --result="restored to staging".'
                        : 'Record a backup first with ' . self::RECORD_COMMAND . '.',
                    'tested_in_window' => $tested_in_window,
                    'untested_successful_in_window' => $untested,
                ],
            );
        }

        $result = $this->text($tested->restore_test_result);
        $verdict = $this->restoreVerdict($tested->restore_tested_at, $result);
        $ageMinutes = $this->minutesSince($tested->restore_tested_at);

        return [
            // The read succeeded, so the block is available whatever it found. A failed restore
            // test is a measurement, not an unread value, and marking the block 'failed' would
            // draw it as "this could not be read" — the one sentence it must never be mistaken for.
            'state' => 'ok',
            'note' => $verdict === self::RESTORE_FAILED
                ? 'The last recorded restore test failed. The backups may still be arriving on schedule; none of them has been shown to come back.'
                : null,
            'remedy' => $verdict === self::RESTORE_FAILED
                ? 'Fix the restore path, test it again and record the outcome: ' . self::RESTORE_COMMAND . '.'
                : null,
            'source' => self::SOURCE,
            'verdict' => $verdict,
            'tested_at' => $this->displayStamp($tested->restore_tested_at),
            'age_minutes' => $ageMinutes,
            'age_hours' => $ageMinutes === null ? null : round($ageMinutes / 60, 1),
            'result' => $result,
            'backup' => $this->summarise($tested),
            'tested_in_window' => $tested_in_window,
            'untested_successful_in_window' => $untested,
            'command' => self::RESTORE_COMMAND,
        ];
    }

    /**
     * @param  array<string, mixed>  $history
     * @return array{0: int|null, 1: int|null}
     */
    private function restoreCounts(array $history): array
    {
        if ($history['state'] === 'failed') {
            // Null rather than zero: the history could not be read, so the number of untested
            // backups in the window was not counted rather than counted as none. A window that
            // simply holds no backup is a measured zero and falls through to the loop below.
            return [null, null];
        }

        $tested = 0;
        $untested = 0;
        foreach ($history['rows'] as $row) {
            if ($row['restore_tested_at'] !== null) {
                $tested++;

                continue;
            }
            if ($row['status'] === 'success') {
                $untested++;
            }
        }

        return [$tested, $untested];
    }

    /** @return array<string, mixed> */
    private function emptyRestore(string $state, string $verdict): array
    {
        return [
            'state' => $state,
            'note' => null,
            'remedy' => null,
            'source' => self::SOURCE,
            'verdict' => $verdict,
            'tested_at' => null,
            'age_minutes' => null,
            'age_hours' => null,
            'result' => null,
            'backup' => null,
            'tested_in_window' => null,
            'untested_successful_in_window' => null,
            'command' => self::RESTORE_COMMAND,
        ];
    }

    /**
     * What a restore test found, read from the marker its recorder writes.
     *
     * `monitoring:restore-tested --failed` prefixes the result with FAILED:, which is the only
     * machine-readable part of a free-text column. A row written by hand without it is reported as
     * recorded-without-a-result rather than as a pass, because reading an unrecognised sentence as
     * "it restored fine" is the one direction this must never guess in.
     */
    private function restoreVerdict(mixed $testedAt, ?string $result): string
    {
        if ($testedAt === null || (is_string($testedAt) && trim($testedAt) === '')) {
            return self::RESTORE_NEVER;
        }

        if ($result === null) {
            return self::RESTORE_UNCLEAR;
        }

        if (str_starts_with(strtoupper($result), self::FAILED_PREFIX)) {
            return self::RESTORE_FAILED;
        }

        return str_starts_with(strtolower($result), 'fail') ? self::RESTORE_FAILED : self::RESTORE_PASSED;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The age the backup check grades on, and where that number came from.
     *
     * The check reads the merchant-editable setting with the shipped config value underneath it,
     * so this reads the same pair. Taking config alone would print one threshold on the page while
     * the alert fired on another.
     *
     * @return array<string, mixed>
     */
    private function thresholds(): array
    {
        $configured = (int) config('monitoring.thresholds.backup_age_warning_hours', 36);
        $effective = $this->settings->threshold('backup_age_warning_hours');
        $warning = $effective === null ? $configured : max(1, (int) $effective);

        return [
            'backup_age_warning_hours' => $warning,
            // The check calls a backup failing at twice the warning age rather than at a threshold
            // of its own, so the same doubling is published here instead of a second setting.
            'backup_age_critical_hours' => $warning * 2,
            'config_default_hours' => $configured,
            'overridden' => $warning !== $configured,
            'source' => 'monitoring_settings (thresholds.backup_age_warning_hours), config/monitoring.php',
        ];
    }

    /**
     * A size or a duration, or null when the column held none.
     *
     * Null is preserved rather than cast: (int) null is 0, and a zero-byte backup is the single
     * most alarming value this page can print — it must only ever mean a measured zero.
     */
    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $this->redactor->text(mb_substr($value, 0, 191));
    }

    private function firstLine(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : (trim(strtok($value, "\n") ?: '') ?: null);
    }

    /**
     * Only the scalar members of a stored JSON column.
     *
     * The check's context is written by BackupCheck and is flat today; anything nested arriving in
     * it later is dropped rather than rendered, so a column that grows a structure cannot put a
     * PHP notice where a value belongs. A null is kept rather than dropped — the check writes
     * restore_tested_at as null on purpose, and that null is the reading.
     *
     * @return array<string, scalar|null>|null
     */
    private function scalars(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }

        $scalars = [];
        foreach ($decoded as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $scalars[(string) $key] = $item;
            }
        }

        return $scalars === [] ? null : $scalars;
    }

    /**
     * A stored UTC stamp, in the timezone the dashboard renders in.
     *
     * Every timestamp on this page passes through here. Printing a stored value directly would put
     * a backup several hours away from the night it was taken on any deployment whose display
     * timezone is not UTC, and this page is entirely about how long ago something happened.
     */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the backup really
            // happened, and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    /** A chart point's timestamp, or null when the stamp cannot be read as one. */
    private function isoStamp(mixed $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        try {
            return Clock::parse($stored)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function minutesSince(mixed $stored): ?int
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return (int) Clock::parse($stored)->diffInMinutes(Clock::now());
        } catch (\Throwable) {
            return null;
        }
    }
}
