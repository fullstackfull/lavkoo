<?php

namespace App\Services\Monitoring\Panels;

use App\Console\Commands\MonitoringDeployRecorded;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * Which build started running, when, and what happened to the shop afterwards.
 *
 * Half of this question is already answered everywhere else in the system: every error, every error
 * group and every trace is stamped with app_release_version(), so "which build produced this" is
 * readable today. What only this section can say is WHEN a build started running — and without that
 * timestamp nothing can produce the sentence an operator actually needs, which is "p95 doubled at
 * 14:20 and the deploy was at 14:19".
 *
 * Two things make a deployments page dishonest if they are not handled deliberately, and both are
 * handled here rather than in the view.
 *
 * The first is the empty table. monitoring_deployments is written by exactly one thing —
 * `php artisan monitoring:deploy-recorded`, called by the deploy script — so an empty table is
 * evidence about the deploy script and no evidence at all about whether anything shipped. It is
 * never drawn as "no deploys". The running release is named instead, from the same source the
 * errors are tagged with, so the page still tells the operator which build they are looking at.
 *
 * The second is migrations_run. The command records the total size of the migrations table, which
 * on its own says nothing: 440 is not a fact about a release. The figure that matters is the
 * DIFFERENCE against the previous recorded release — that is the number of migrations that release
 * ran, and a release that ran fourteen of them is a release to watch when something starts failing
 * an hour later. A difference needs two rows, so the row immediately before the window is read as a
 * baseline, and where there is no earlier row the difference is absent with its reason rather than
 * shown as zero: "this is the first release on record" and "this release ran no migrations" are
 * opposite claims and they must never share a cell.
 */
class DeploymentsPanel implements Panel
{
    /**
     * How many recorded releases the history table carries.
     *
     * A deploy is a rare event, so this is generous enough that the table is normally the whole
     * window; when it is not, the page says so rather than letting the oldest releases vanish.
     */
    private const MAX_RELEASES = 25;

    /** The statuses the recorder writes. Anything else in the column is somebody else's value. */
    private const STATUSES = ['success', 'failed', 'unknown'];

    /**
     * Why a migration difference is missing, as fixed keys the view can translate.
     *
     * A missing difference has three causes that lead to different conclusions, and a blank cell
     * for all three would hide the only one that is a problem (a release that recorded no count).
     */
    private const NO_BASELINE = 'no_earlier_release_is_recorded';
    private const NOT_RECORDED_HERE = 'this_release_recorded_no_migration_count';
    private const NOT_RECORDED_BEFORE = 'the_previous_release_recorded_no_migration_count';

    /** Where a deployment row can only come from, named in full because the page is about it. */
    private const RECORDER_COMMAND = 'php artisan monitoring:deploy-recorded';

    private const RECORDER_CLASS = MonitoringDeployRecorded::class;

    private const SOURCE = 'monitoring_deployments';

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $running = $this->running();
        $latest = $this->latest($range, $running['release']);
        $releases = $this->releases($range, $running['release']);
        $errors = $this->errorsByRelease($range, $releases);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'running' => $running,
            'recorder' => $this->recorder(),
            'latest' => $latest,
            'headline' => $this->headline($releases),
            'releases' => $releases,
            'errors_by_release' => $errors,
            'comparisons' => $this->comparisons($releases),
            'unrendered' => [],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The build that is running now

    /**
     * The release this request is being served by.
     *
     * Read from the application rather than the table, so it is the one fact on this page that is
     * true even when nothing has ever been recorded. It is also the exact string stamped onto
     * monitoring_errors.release and monitoring_traces.release, which is what makes the error counts
     * further down comparable to it at all.
     *
     * @return array<string, mixed>
     */
    private function running(): array
    {
        $release = Metric::probe('version.json + .git/HEAD', static fn () => Metric::of(
            value: app_release_version(),
            source: 'version.json + .git/HEAD',
            note: 'The same string stamped onto every error and trace as it is recorded, which is what lets a spike be tied to a build.',
        ));

        $commit = Metric::probe('.git/HEAD', static function () {
            $sha = app_commit_sha();

            if ($sha === null) {
                return Metric::notConfigured(
                    source: '.git/HEAD',
                    remedy: 'Deploy with the .git directory present, or write the deployed sha into version.json as part of the build.',
                    note: 'This deployment has no readable .git, so the release above is the version.json number with no commit behind it.',
                );
            }

            // Twelve characters is git's own abbreviation. The full forty have no break
            // opportunity in them and push straight through the next card.
            return Metric::of(
                value: substr($sha, 0, 12),
                source: '.git/HEAD',
                note: 'The first 12 characters, as git abbreviates a commit.',
            );
        });

        return [
            'state' => $release->state,
            'release' => $release,
            'commit' => $commit,
            'environment' => Metric::probe('APP_ENV', static fn () => Metric::of(
                value: (string) app()->environment(),
                source: 'APP_ENV',
            )),
            'source' => 'version.json + .git/HEAD',
        ];
    }

    /**
     * Whether anything in this build can write a deployment row.
     *
     * The command being installed is readable; whether the deploy script calls it is not, and this
     * block says so. The only evidence that it is wired up is a row in the table, which is why an
     * empty history is reported as a gap in the deploy script rather than as a quiet quarter.
     *
     * @return array<string, mixed>
     */
    private function recorder(): array
    {
        $installed = class_exists(self::RECORDER_CLASS);

        return [
            'state' => $installed ? 'ok' : 'not_configured',
            'installed' => $installed,
            'command' => self::RECORDER_COMMAND,
            'note' => $installed
                ? 'The recorder command is installed in this build. Whether the deploy script actually calls it cannot be read from here — a row in the table is the only evidence of that.'
                : 'No recorder command is installed, so nothing in this build can write a deployment row at all.',
            'remedy' => $installed
                ? 'Add `' . self::RECORDER_COMMAND . ' --by="$(whoami)" --duration=$SECONDS` as the last step of the deploy script (docs/audit/deployment-runbook.md).'
                : 'Restore app/Console/Commands/MonitoringDeployRecorded.php, then call `' . self::RECORDER_COMMAND . '` from the deploy script.',
            'source' => 'app/Console/Commands/MonitoringDeployRecorded.php',
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The newest recorded release, against the one that is running

    /**
     * The newest row in the table, whatever window is selected.
     *
     * Deliberately not bounded by the range: "does the record match what is running" is a question
     * about the whole table, and answering it only for the last hour would report a mismatch every
     * time somebody picked a short window.
     *
     * @return array<string, mixed>
     */
    private function latest(string $range, Metric $runningRelease): array
    {
        try {
            $row = $this->reader->connection()->table(self::SOURCE)
                ->orderByDesc('deployed_at')
                ->limit(1)
                ->first(['release', 'commit_sha', 'branch', 'environment', 'deployed_by', 'status', 'deployed_at']);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: this read failing costs the page one
            // card, while letting it escape would blank the running release too — the one thing
            // this section can always say.
            return array_merge($this->emptyLatest('failed'), [
                'note' => Metric::describeFailure($exception),
            ]);
        }

        $running = $runningRelease->isOk() ? (string) $runningRelease->value : null;

        if ($row === null) {
            return array_merge($this->emptyLatest('no_data'), [
                'note' => 'No release has ever been recorded here. That is a statement about the deploy script, not about the shop: nothing writes a deployment row unless the deploy calls the recorder, so this table stays empty however many times the code ships.',
                'remedy' => 'Add `' . self::RECORDER_COMMAND . '` as the last step of the deploy script; every section that draws a release marker reads this same table.',
                'running_release' => $running,
            ]);
        }

        $deployedAt = $this->displayStamp($row->deployed_at);
        $release = (string) $row->release;

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => self::SOURCE,
            'release' => $release,
            'commit_sha' => $this->shortSha($row->commit_sha),
            'branch' => $this->plain($row->branch),
            'environment' => $this->plain($row->environment),
            'deployed_by' => $this->plain($row->deployed_by),
            'status' => (string) $row->status,
            'status_known' => in_array((string) $row->status, self::STATUSES, true),
            'deployed_at' => $deployedAt,
            'age_minutes' => $this->minutesSince($row->deployed_at),
            // Null rather than false when the running release could not be read: "the record does
            // not match what is running" and "we cannot tell what is running" are different
            // statements, and only one of them is a reason to go looking.
            'matches_running_release' => $running === null ? null : $running === $release,
            'running_release' => $running,
            // Whether the newest row is inside the selected range, so an empty table below can be
            // explained by the window rather than read as an empty table.
            'in_window' => $this->isInWindow($row->deployed_at, $range),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyLatest(string $state): array
    {
        return [
            'state' => $state,
            'note' => null,
            'remedy' => null,
            'source' => self::SOURCE,
            'release' => null,
            'commit_sha' => null,
            'branch' => null,
            'environment' => null,
            'deployed_by' => null,
            'status' => null,
            'status_known' => false,
            'deployed_at' => null,
            'age_minutes' => null,
            'matches_running_release' => null,
            'running_release' => null,
            'in_window' => false,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Every recorded release in the window

    /**
     * The releases recorded inside the window, newest first, each with the migrations it ran.
     *
     * The read takes one row more than it draws, and that extra row is not discarded: it is the
     * predecessor of the oldest listed release, which is exactly what the oldest migration
     * difference needs. When the window itself holds no earlier row, one row before the window is
     * read as the baseline instead — a release does not stop having a predecessor because somebody
     * chose a one-hour range.
     *
     * @return array<string, mixed>
     */
    private function releases(string $range, Metric $runningRelease): array
    {
        try {
            $connection = $this->reader->connection();
            $fetched = $connection->table(self::SOURCE)
                ->where('deployed_at', '>=', $this->reader->since($range))
                ->orderByDesc('deployed_at')
                ->limit(self::MAX_RELEASES + 1)
                ->get([
                    'release', 'commit_sha', 'branch', 'environment', 'deployed_by', 'status',
                    'duration_seconds', 'migrations_run', 'notes', 'deployed_at',
                    'before_metrics', 'after_metrics',
                ]);
        } catch (\Throwable $exception) {
            return array_merge($this->emptyReleases('failed'), [
                'note' => Metric::describeFailure($exception),
            ]);
        }

        $truncated = $fetched->count() > self::MAX_RELEASES;
        $listed = $fetched->take(self::MAX_RELEASES)->values();

        if ($listed->isEmpty()) {
            return $this->emptyReleases('no_data');
        }

        // The predecessor of the oldest listed release: the row the limit cut off when the window
        // held more than the table draws, otherwise the newest row before the window starts.
        $predecessor = $truncated ? $fetched->get(self::MAX_RELEASES) : $this->baselineBefore($range);
        $running = $runningRelease->isOk() ? (string) $runningRelease->value : null;

        $rows = [];
        foreach ($listed as $index => $row) {
            $previous = $listed->get($index + 1) ?? $predecessor;
            $rows[] = $this->row($row, $previous, $running);
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => self::SOURCE,
            'rows' => $rows,
            'truncated' => $truncated,
            'limit' => self::MAX_RELEASES,
            'baseline_release' => $predecessor === null ? null : (string) $predecessor->release,
            'baseline_deployed_at' => $predecessor === null ? null : $this->displayStamp($predecessor->deployed_at),
        ];
    }

    /**
     * The newest release recorded before the window opened.
     *
     * One row, on the indexed column, so the oldest release on the page can still say how many
     * migrations it ran. A failure here loses a single difference rather than the table, so it is
     * swallowed into a null baseline and reported as "no earlier release is recorded".
     */
    private function baselineBefore(string $range): ?object
    {
        try {
            return $this->reader->connection()->table(self::SOURCE)
                ->where('deployed_at', '<', $this->reader->since($range))
                ->orderByDesc('deployed_at')
                ->limit(1)
                ->first(['release', 'migrations_run', 'deployed_at']);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $row, ?object $previous, ?string $running): array
    {
        $release = (string) $row->release;
        $migrations = $this->integerOrNull($row->migrations_run);
        $difference = $this->migrationDifference($migrations, $previous);
        $status = (string) $row->status;

        return [
            'release' => $release,
            'commit_sha' => $this->shortSha($row->commit_sha),
            'commit_sha_full' => $this->plain($row->commit_sha),
            'branch' => $this->plain($row->branch),
            'environment' => $this->plain($row->environment),
            'deployed_by' => $this->plain($row->deployed_by),
            'status' => $status,
            'status_known' => in_array($status, self::STATUSES, true),
            'duration_seconds' => $this->integerOrNull($row->duration_seconds),
            'deployed_at' => $this->displayStamp($row->deployed_at),
            'age_minutes' => $this->minutesSince($row->deployed_at),
            // The recorded total, kept beside the difference. On its own it is a fact about the
            // migrations table rather than about this release, which is why it is the smaller of
            // the two figures in the cell.
            'migrations_run' => $migrations,
            'migrations_delta' => $difference['delta'],
            'migrations_delta_reason' => $difference['reason'],
            'migrations_compared_with' => $difference['compared_with'],
            // Notes are typed at a deploy shell and land in a database column unfiltered, so they
            // are redacted on the way out like any other free text this system did not author.
            'notes' => $this->text($row->notes),
            'is_running' => $running === null ? null : $running === $release,
            'has_before_metrics' => $this->decoded($row->before_metrics) !== null,
            'has_after_metrics' => $this->decoded($row->after_metrics) !== null,
        ];
    }

    /**
     * How many migrations this release ran, or why that cannot be said.
     *
     * Never zero by default. A release with no predecessor on record and a release that genuinely
     * ran nothing produce the same blank cell in every deployment dashboard that gets this wrong,
     * and they are opposite facts: one is missing history, the other is a clean deploy.
     *
     * @return array{delta: int|null, reason: string|null, compared_with: string|null}
     */
    private function migrationDifference(?int $migrations, ?object $previous): array
    {
        if ($previous === null) {
            return ['delta' => null, 'reason' => self::NO_BASELINE, 'compared_with' => null];
        }

        $before = $this->integerOrNull($previous->migrations_run ?? null);
        $comparedWith = isset($previous->release) ? (string) $previous->release : null;

        if ($migrations === null) {
            return ['delta' => null, 'reason' => self::NOT_RECORDED_HERE, 'compared_with' => $comparedWith];
        }

        if ($before === null) {
            return ['delta' => null, 'reason' => self::NOT_RECORDED_BEFORE, 'compared_with' => $comparedWith];
        }

        return ['delta' => $migrations - $before, 'reason' => null, 'compared_with' => $comparedWith];
    }

    /** @return array<string, mixed> */
    private function emptyReleases(string $state): array
    {
        return [
            'state' => $state,
            'note' => $state === 'no_data'
                ? 'No release was recorded inside this window. An empty table here is evidence about the deploy script and none at all about whether the code shipped — nothing writes a row unless the deploy calls the recorder.'
                : null,
            'remedy' => $state === 'no_data'
                ? 'Widen the range, or add `' . self::RECORDER_COMMAND . '` as the last step of the deploy script.'
                : null,
            'source' => self::SOURCE,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_RELEASES,
            'baseline_release' => null,
            'baseline_deployed_at' => null,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What each release is carrying

    /**
     * Readings drawn as cards above the table, built only from a read that succeeded.
     *
     * Nothing is published here when the history could not be read or the window holds no release:
     * a card reading "0 releases" beside a table that could not be queried is the exact confusion
     * this system exists to prevent, and the block's own state says it better in a sentence.
     *
     * @param  array<string, mixed>  $releases
     * @return array<string, Metric>
     */
    private function headline(array $releases): array
    {
        if ($releases['state'] !== 'ok' || $releases['rows'] === []) {
            return [];
        }

        $rows = $releases['rows'];
        $newest = $rows[0];
        $failed = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'failed') {
                $failed++;
            }
        }

        $headline = [
            'releases_recorded_in_this_window' => Metric::of(
                value: count($rows),
                source: self::SOURCE,
                note: $releases['truncated']
                    ? 'The window holds more releases than this page lists, so this counts the listed ones.'
                    : 'Rows in the table, which is not the same as deploys: a deploy that does not call the recorder leaves nothing behind.',
            ),
            'failed_deploys_recorded' => Metric::of(
                value: $failed,
                source: self::SOURCE,
                note: 'Counted from the status the deploy script reported; a deploy that died before calling the recorder reports nothing at all.',
            ),
        ];

        $headline['hours_since_the_newest_release'] = $newest['age_minutes'] === null
            ? Metric::noData(
                source: self::SOURCE,
                note: 'The newest release has no readable deployed_at, so its age cannot be measured.',
            )
            : Metric::of(
                value: round($newest['age_minutes'] / 60, 1),
                source: self::SOURCE,
                unit: 'h',
            );

        $headline['migrations_in_the_newest_release'] = $newest['migrations_delta'] === null
            ? Metric::noData(
                source: self::SOURCE,
                note: 'There is no difference to take for the newest release, so the number of migrations it ran is unknown rather than zero.',
            )
            : Metric::of(
                value: $newest['migrations_delta'],
                source: self::SOURCE,
                note: 'The difference against the release recorded before it, which is what says how many migrations this release ran.',
            );

        return $headline;
    }

    /**
     * Errors already tagged with each listed release.
     *
     * The cross-reference costs nothing to make honest and everything to get wrong. Two different
     * counts are published rather than one, because they answer different questions: the errors
     * count is occurrences RECORDED IN THIS WINDOW carrying that release, and the group counts are
     * distinct bugs whose first or latest sighting was on it — lifetime figures that ignore the
     * window entirely. Averaging them, or labelling either as "errors caused by this release",
     * would put a number on the page that nothing measured.
     *
     * @param  array<string, mixed>  $history  the release block these counts hang off
     * @return array<string, mixed>
     */
    private function errorsByRelease(string $range, array $history): array
    {
        $source = 'monitoring_errors (release), monitoring_error_groups (release, last_release)';
        $releases = array_values(array_unique(array_filter(
            array_column($history['rows'], 'release'),
            static fn (string $release) => $release !== '',
        )));

        if ($releases === []) {
            return [
                // A history that could not be read leaves nothing to count errors against, and
                // saying "no data" for it would report an empty result out of a query that was
                // never asked.
                'state' => $history['state'] === 'ok' ? 'no_data' : $history['state'],
                'note' => $history['state'] === 'ok'
                    ? 'No release is listed above, so there is nothing to look up errors for.'
                    : 'The release history could not be read, so there is no release to count errors against.',
                'remedy' => null,
                'source' => $source,
                'by_release' => [],
                'window_since' => Clock::display($this->reader->since($range))->toDateTimeString(),
            ];
        }

        try {
            $connection = $this->reader->connection();

            // Bounded by the indexed created_at first, then narrowed to the releases on the page,
            // so the read stays inside the same index every other error query uses.
            $errors = $connection->table('monitoring_errors')
                ->where('created_at', '>=', $this->reader->since($range))
                ->whereIn('release', $releases)
                ->groupBy('release')
                ->limit(count($releases) + 1)
                ->get(['release', $connection->raw('COUNT(*) AS occurrences')]);

            // One row per distinct bug rather than per occurrence, and the result can hold no more
            // rows than there are releases on the page.
            $firstSeen = $connection->table('monitoring_error_groups')
                ->whereIn('release', $releases)
                ->groupBy('release')
                ->limit(count($releases) + 1)
                ->get([
                    'release',
                    $connection->raw('COUNT(*) AS groups'),
                    $connection->raw("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_groups"),
                ]);

            $lastSeen = $connection->table('monitoring_error_groups')
                ->whereIn('last_release', $releases)
                ->groupBy('last_release')
                ->limit(count($releases) + 1)
                ->get(['last_release', $connection->raw('COUNT(*) AS groups')]);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
                'source' => $source,
                'by_release' => [],
                'window_since' => Clock::display($this->reader->since($range))->toDateTimeString(),
            ];
        }

        $byRelease = [];
        foreach ($releases as $release) {
            $byRelease[$release] = [
                'errors_in_window' => 0,
                'groups_first_seen' => 0,
                'open_groups_first_seen' => 0,
                'groups_last_seen' => 0,
            ];
        }

        foreach ($errors as $row) {
            $byRelease[(string) $row->release]['errors_in_window'] = (int) $row->occurrences;
        }
        foreach ($firstSeen as $row) {
            $byRelease[(string) $row->release]['groups_first_seen'] = (int) $row->groups;
            $byRelease[(string) $row->release]['open_groups_first_seen'] = (int) $row->open_groups;
        }
        foreach ($lastSeen as $row) {
            $byRelease[(string) $row->last_release]['groups_last_seen'] = (int) $row->groups;
        }

        return [
            'state' => 'ok',
            // Every count here is a measured zero: the queries ran and found nothing tagged with
            // these releases. That is a different fact from an unread column, and the block state
            // is what tells the two apart.
            'note' => null,
            'remedy' => null,
            'source' => $source,
            'by_release' => $byRelease,
            'window_since' => Clock::display($this->reader->since($range))->toDateTimeString(),
        ];
    }

    /**
     * The before/after readings each release was supposed to carry.
     *
     * The columns exist and the recorder does not write them: it records that a release shipped,
     * and nothing in this build compares the shop's behaviour either side of that moment. Drawn as
     * a configured-away feature naming what would have to write it, because two blank JSON columns
     * rendered as empty cells read as "this release changed nothing".
     *
     * @param  array<string, mixed>  $releases
     * @return array<string, mixed>
     */
    private function comparisons(array $releases): array
    {
        $source = self::SOURCE . '.before_metrics, ' . self::SOURCE . '.after_metrics';
        $remedy = 'Nothing writes these columns. A job would have to read monitoring_request_buckets (p95 via Support/Histogram, error rate from errors/hits) and monitoring_series (db.latency_ms) for the equal window each side of deployed_at, then update monitoring_deployments.before_metrics / after_metrics; no such job is registered in bootstrap/app.php.';

        if ($releases['state'] !== 'ok') {
            return [
                'state' => $releases['state'],
                'note' => $releases['note'] ?? 'The release history could not be read, so whether any release carries before/after readings is unknown.',
                'remedy' => null,
                'source' => $source,
                'rows' => [],
                'checked' => 0,
            ];
        }

        $carrying = [];
        foreach ($releases['rows'] as $row) {
            if ($row['has_before_metrics'] || $row['has_after_metrics']) {
                $carrying[] = [
                    'release' => $row['release'],
                    'deployed_at' => $row['deployed_at'],
                    'has_before_metrics' => $row['has_before_metrics'],
                    'has_after_metrics' => $row['has_after_metrics'],
                ];
            }
        }

        if ($carrying === []) {
            return [
                'state' => 'not_configured',
                'note' => 'No release listed here carries a before or after reading. The recorder writes the deploy itself and nothing computes the comparison, so these columns are empty by construction rather than because the releases changed nothing.',
                'remedy' => $remedy,
                'source' => $source,
                'rows' => [],
                'checked' => count($releases['rows']),
            ];
        }

        return [
            'state' => 'ok',
            'note' => 'Read from the rows themselves: something outside this build has written these columns.',
            'remedy' => null,
            'source' => $source,
            'rows' => $carrying,
            'checked' => count($releases['rows']),
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A count, or null when the column held none.
     *
     * Null is preserved rather than cast: (int) null is 0, and a zero in a migration column is the
     * single most misleading value this page could print.
     */
    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function plain(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    private function text(mixed $value): ?string
    {
        $value = $this->plain($value);

        return $value === null ? null : $this->redactor->text($value);
    }

    /** Git's own abbreviation; the stored value is never truncated, only what is drawn. */
    private function shortSha(mixed $value): ?string
    {
        $sha = $this->plain($value);

        return $sha === null ? null : mb_substr($sha, 0, 12);
    }

    /** @return array<mixed>|null the decoded column, or null when it holds nothing usable */
    private function decoded(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /**
     * A stored UTC stamp, in the timezone the dashboard renders in.
     *
     * Every timestamp on this page passes through here. Printing a stored value directly would put
     * a release an hour away from the error spike it caused on any deployment whose display
     * timezone is not UTC, which is the whole reason this section exists.
     */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the deploy really
            // happened, and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
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

    private function isInWindow(mixed $stored, string $range): bool
    {
        try {
            return Clock::parse($stored)->greaterThanOrEqualTo($this->reader->since($range));
        } catch (\Throwable) {
            return false;
        }
    }
}
