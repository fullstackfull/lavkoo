<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The error centre: exceptions grouped into the bug behind them.
 *
 * "Fourteen errors in the last hour" is a number nobody can act on. One group with fourteen
 * occurrences, a first seen, a last seen and a stack trace is a bug report — so everything here is
 * built around the group, and individual occurrences exist only to give a group the concrete
 * example that makes it debuggable.
 *
 * The distinction the page leads with is new against recurring. An exception that has been failing
 * quietly for a month and one that started eleven minutes after a deploy demand completely
 * different reactions, and the only thing that tells them apart is first_seen_at against the
 * window.
 *
 * Two counts are deliberately kept apart rather than added together. A group's `occurrences` is a
 * lifetime counter that outlives the occurrence rows retention prunes; the in-window count is read
 * from monitoring_errors. Showing the lifetime counter under a heading that says "this window"
 * would be the easiest lie on this page, so both are carried and both are labelled.
 *
 * Every part reports its own state. A panel that catches its own failure can say which half could
 * not be read instead of blanking a section that was mostly fine.
 */
class ErrorsPanel implements Panel
{
    /** Groups per page: enough to scan in one screen, few enough to keep the offset cheap. */
    private const PER_PAGE = 25;

    /**
     * The furthest page anybody may ask for.
     *
     * `page` reaches an OFFSET, and OFFSET is the one part of this query with no bound of its own:
     * page=100000 makes the server walk two and a half million rows in order to throw them away.
     * Paging that deep into errors is not a real workflow — narrowing the window or the filters is
     * — so the offset is capped rather than trusted.
     */
    private const MAX_PAGE = 200;

    /** Recent occurrences shown for the selected group. */
    private const OCCURRENCE_LIMIT = 10;

    /** Longest stack trace rendered. Runaway recursion produces frames by the megabyte. */
    private const STACK_TRACE_CHARS = 20000;

    private const STATUSES = ['open', 'resolved', 'ignored'];

    /**
     * Ceiling on the distinct filter combinations read out of the window.
     *
     * Status x severity x channel x release is bounded in practice but not by the schema, and a
     * filter row is not worth an unbounded group-by. When the ceiling is hit the payload says so
     * rather than quietly offering a partial list of releases as if it were all of them.
     */
    private const OPTION_ROWS = 500;

    private ?Redactor $redactor = null;

    public function __construct(private readonly SeriesReader $reader)
    {
    }

    public function data(string $range, Request $request): array
    {
        $since = $this->reader->since($range);
        $filters = $this->filters($request);
        $capture = $this->capture();
        $options = $this->options($since);
        $groups = $this->groups($since, $filters);

        if ($groups['state'] === 'empty') {
            // Why the table is empty decides whether the page reads as good news or as a warning,
            // and only the panel holds the three facts needed to tell them apart.
            $groups['reason'] = $this->emptyReason($filters, $options, $capture);
        }

        return [
            'window' => [
                'range' => $range,
                'minutes' => $this->reader->window($range)['minutes'],
                'since' => Clock::display($since)->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'filters' => $filters,
            'options' => $options,
            'summary' => $this->summary($since, $filters),
            'groups' => $groups,
            'selected' => $filters['group'] === null ? null : $this->selectedGroup($filters['group'], $since),
            'capture' => $capture,
            'source' => 'monitoring_error_groups + monitoring_errors',
        ];
    }

    /**
     * The filter row, normalised.
     *
     * Every one of these values reaches a query, so each is clamped here rather than trusted:
     * status against the schema's own vocabulary, the free-text fields to their column widths, and
     * the page number to a real offset.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        // `?status[]=x` hands the request an ARRAY, and casting one to string is a PHP warning the
        // error handler turns into a throw — which takes the whole section down with an "Array to
        // string conversion" card. A filter that cannot be spelled is simply not applied.
        $status = $request->query('status', 'open');
        $status = is_string($status) ? $status : 'open';

        if (!in_array($status, array_merge(self::STATUSES, ['all']), true)) {
            $status = 'open';
        }

        $group = (int) $this->scalar($request, 'group', 0);

        return [
            'q' => $this->trimmed($request, 'q', 120),
            'status' => $status,
            // Truncated to the column widths in the migration: a longer value can never match a
            // row, so binding it would only cost a scan.
            'severity' => $this->trimmed($request, 'severity', 12),
            'channel' => $this->trimmed($request, 'channel', 12),
            'release' => $this->trimmed($request, 'release', 40),
            // The route an endpoint's page links in with, so "the errors on this endpoint" is a
            // link rather than a search. 191 is the column width in the migration.
            'route' => $this->trimmed($request, 'route', 191),
            'group' => $group > 0 ? $group : null,
            'page' => max(1, min(self::MAX_PAGE, (int) $this->scalar($request, 'page', 1))),
        ];
    }

    /** A query value that can be cast to a number, or the fallback when it is an array. */
    private function scalar(Request $request, string $key, int|string $fallback): int|string
    {
        $value = $request->query($key, $fallback);

        return is_scalar($value) ? $value : $fallback;
    }

    private function trimmed(Request $request, string $key, int $maxLength): ?string
    {
        $value = $request->query($key, '');

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * What the window actually contains, for the filter row and the empty state.
     *
     * One grouped read rather than four distinct-value queries: the same scan answers "which
     * severities exist here", "which channels", "which releases" and "how many groups sit in each
     * status", and four scans of the same rows would be three too many.
     *
     * @return array<string, mixed>
     */
    private function options(Carbon $since): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_error_groups')
                ->where('last_seen_at', '>=', $since->toDateTimeString())
                ->groupBy('status', 'severity', 'channel', 'release')
                ->orderBy('status')
                ->limit(self::OPTION_ROWS)
                ->select(['status', 'severity', 'channel', 'release'])
                ->selectRaw('COUNT(*) AS combination_total')
                ->get();

            $statuses = [];
            $severities = [];
            $channels = [];
            $releases = [];
            $total = 0;

            foreach ($rows as $row) {
                $count = (int) $row->combination_total;
                $total += $count;
                $this->tally($statuses, $row->status, $count);
                $this->tally($severities, $row->severity, $count);
                $this->tally($channels, $row->channel, $count);
                $this->tally($releases, $row->release, $count);
            }

            return [
                'state' => 'ok',
                'statuses' => $statuses,
                'severities' => array_keys($severities),
                'channels' => array_keys($channels),
                'releases' => array_keys($releases),
                'groups_in_window' => $total,
                'truncated' => $rows->count() >= self::OPTION_ROWS,
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'unavailable',
                'statuses' => [],
                'severities' => [],
                'channels' => [],
                'releases' => [],
                'groups_in_window' => null,
                'truncated' => false,
                'message' => Metric::describeFailure($exception),
            ];
        }
    }

    /**
     * @param  array<string, int>  $bag
     */
    private function tally(array &$bag, mixed $value, int $count): void
    {
        $key = is_string($value) ? trim($value) : '';
        if ($key === '') {
            return;
        }

        $bag[$key] = ($bag[$key] ?? 0) + $count;
        arsort($bag);
    }

    /**
     * The four numbers above the table, all of them answering for the same filter row.
     *
     * @return array<string, mixed>
     */
    private function summary(Carbon $since, array $filters): array
    {
        $summary = [
            'state' => 'ok',
            'groups' => null,
            'new_groups' => null,
            'recurring_groups' => null,
            'occurrences_all_time' => null,
            'source' => 'monitoring_error_groups',
        ];

        try {
            $row = $this->groupQuery($since, $filters)
                ->selectRaw(
                    'COUNT(*) AS matched_groups,'
                    . ' COALESCE(SUM(monitoring_error_groups.occurrences), 0) AS lifetime_occurrences,'
                    . ' COALESCE(SUM(CASE WHEN monitoring_error_groups.first_seen_at >= ? THEN 1 ELSE 0 END), 0) AS new_groups',
                    [$since->toDateTimeString()],
                )
                ->first();

            $groups = (int) ($row->matched_groups ?? 0);
            $new = (int) ($row->new_groups ?? 0);

            $summary['groups'] = $groups;
            $summary['new_groups'] = $new;
            $summary['recurring_groups'] = max(0, $groups - $new);
            $summary['occurrences_all_time'] = (int) ($row->lifetime_occurrences ?? 0);
        } catch (\Throwable $exception) {
            $summary['state'] = 'unavailable';
            $summary['message'] = Metric::describeFailure($exception);
        }

        $totals = $this->occurrenceTotals($since, $filters);
        $summary['occurrences'] = $totals['occurrences'];
        $summary['affected_users'] = $totals['affected_users'];

        return $summary;
    }

    /**
     * Occurrences and distinct users inside the window, for the groups the filter row selects.
     *
     * This joins monitoring_errors to monitoring_error_groups, which is allowed precisely because
     * both live on the monitoring connection — the rule that forbids joining is about monitoring
     * against shop tables, which may be a different database entirely.
     *
     * The join is what keeps the tiles honest: counting occurrence rows without it would report
     * every error in the window while the table below showed only the open ones.
     *
     * @return array<string, array<string, mixed>>
     */
    private function occurrenceTotals(Carbon $since, array $filters): array
    {
        $storesUserId = (bool) config('monitoring.privacy.store_user_id', true);

        try {
            $row = $this->applyFilters(
                $this->reader->connection()->table('monitoring_errors')
                    ->join('monitoring_error_groups', 'monitoring_error_groups.id', '=', 'monitoring_errors.group_id')
                    // created_at is indexed and carries the window; the join then resolves each row
                    // by primary key.
                    ->where('monitoring_errors.created_at', '>=', $since->toDateTimeString()),
                $since,
                $filters,
            )
                // The identity is the guard plus the id, not the id alone. admin #5, vendor #5 and
                // customer #5 are three different people whose ids come from three different
                // tables, so counting user_id on its own quietly merges them into one. CONCAT is
                // null when user_id is null, which is what keeps guests out of the count.
                ->selectRaw(
                    'COUNT(*) AS occurrence_rows,'
                    . " COUNT(DISTINCT CONCAT(COALESCE(monitoring_errors.user_type, ''), ':', monitoring_errors.user_id)) AS distinct_users",
                )
                ->first();

            $occurrences = [
                'state' => 'ok',
                'value' => (int) ($row->occurrence_rows ?? 0),
                'source' => 'monitoring_errors',
            ];

            $users = $storesUserId
                ? [
                    'state' => 'ok',
                    'value' => (int) ($row->distinct_users ?? 0),
                    'source' => 'monitoring_errors',
                ]
                : [
                    // Zero here would read as "nobody was affected" when the truth is that nobody
                    // is being counted.
                    'state' => 'not_configured',
                    'value' => null,
                    'source' => 'monitoring_errors',
                    'remedy' => 'Occurrences are stored without a user id because monitoring.privacy.store_user_id is off. Set MONITORING_STORE_USER_ID=true to count how many signed-in accounts each error reached.',
                ];

            return ['occurrences' => $occurrences, 'affected_users' => $users];
        } catch (\Throwable $exception) {
            $failure = [
                'state' => 'unavailable',
                'value' => null,
                'source' => 'monitoring_errors',
                'message' => Metric::describeFailure($exception),
            ];

            return ['occurrences' => $failure, 'affected_users' => $failure];
        }
    }

    /**
     * One page of groups, newest activity first.
     *
     * @return array<string, mixed>
     */
    private function groups(Carbon $since, array $filters): array
    {
        try {
            $total = $this->groupQuery($since, $filters)->count();
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = max(1, min($filters['page'], $pages, self::MAX_PAGE));

            $rows = $this->groupQuery($since, $filters)
                ->orderByDesc('last_seen_at')
                ->offset(($page - 1) * self::PER_PAGE)
                ->limit(self::PER_PAGE)
                ->get([
                    'id', 'fingerprint', 'exception_class', 'message', 'file', 'line', 'route',
                    'channel', 'severity', 'status', 'release', 'last_release', 'occurrences',
                    'affected_users', 'first_seen_at', 'last_seen_at', 'resolved_at',
                ]);

            // One grouped read for the whole page rather than a count per row: twenty-five extra
            // queries is how a monitoring page becomes the incident it was built to report.
            $inWindow = $this->occurrencesPerGroup($rows->pluck('id')->all(), $since);

            $presented = [];
            foreach ($rows as $row) {
                $presented[] = $this->presentGroup($row, $since, $inWindow, $filters['group']);
            }

            return [
                'state' => $presented === [] ? 'empty' : 'ok',
                'rows' => $presented,
                'pagination' => [
                    'page' => $page,
                    'per_page' => self::PER_PAGE,
                    'total' => $total,
                    'pages' => $pages,
                    'from' => $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1,
                    'to' => min($total, $page * self::PER_PAGE),
                    'has_previous' => $page > 1,
                    'has_next' => $page < min($pages, self::MAX_PAGE),
                    'capped' => $pages > self::MAX_PAGE,
                    'max_page' => self::MAX_PAGE,
                ],
                'source' => 'monitoring_error_groups',
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'unavailable',
                'rows' => [],
                'pagination' => null,
                'message' => Metric::describeFailure($exception),
                'remedy' => 'monitoring_error_groups could not be read on the `' . (string) config('monitoring.connection', 'monitoring') . '` connection. Run `php artisan migrate` to create the monitoring tables, and check that connection\'s credentials.',
                'source' => 'monitoring_error_groups',
            ];
        }
    }

    /**
     * In-window occurrence counts for the groups on this page.
     *
     * Null rather than an empty map when the read fails, so a row can say "not read" instead of
     * printing a zero it has not earned.
     *
     * @param  array<int, int|string>  $groupIds
     * @return array<int, int>|null
     */
    private function occurrencesPerGroup(array $groupIds, Carbon $since): ?array
    {
        if ($groupIds === []) {
            return [];
        }

        try {
            $counts = $this->reader->connection()->table('monitoring_errors')
                ->whereIn('group_id', $groupIds)
                ->where('created_at', '>=', $since->toDateTimeString())
                ->groupBy('group_id')
                ->limit(count($groupIds))
                ->select('group_id')
                ->selectRaw('COUNT(*) AS occurrence_rows')
                ->pluck('occurrence_rows', 'group_id')
                ->all();

            $normalised = [];
            foreach ($counts as $groupId => $count) {
                $normalised[(int) $groupId] = (int) $count;
            }

            return $normalised;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One group row, ready to render.
     *
     * @param  array<int, int>|null  $inWindow
     * @return array<string, mixed>
     */
    private function presentGroup(object $row, Carbon $since, ?array $inWindow, ?int $selectedId): array
    {
        $firstSeenAt = $this->parsed($row->first_seen_at ?? null);
        $id = (int) $row->id;

        return [
            'id' => $id,
            'fingerprint' => (string) ($row->fingerprint ?? ''),
            'exception_class' => (string) ($row->exception_class ?? ''),
            // The tail of the class is what identifies a bug at a glance; the namespace is kept
            // for the tooltip because two packages can both ship a ValidationException.
            'exception_short' => $this->shortClass((string) ($row->exception_class ?? '')),
            // Written redacted, redacted again on the way out: this panel is the last gate before
            // an exception message reaches a screen, and messages quote whatever the request
            // carried.
            'message' => $this->redactor()->text((string) ($row->message ?? '')),
            'file' => $row->file === null ? null : (string) $row->file,
            'line' => $row->line === null ? null : (int) $row->line,
            'route' => $row->route === null ? null : (string) $row->route,
            'channel' => $row->channel === null ? null : (string) $row->channel,
            'severity' => (string) ($row->severity ?? 'error'),
            'status' => (string) ($row->status ?? 'open'),
            'release' => $row->release === null ? null : (string) $row->release,
            'last_release' => $row->last_release === null ? null : (string) $row->last_release,
            'occurrences_all_time' => (int) ($row->occurrences ?? 0),
            'occurrences_in_window' => $inWindow === null ? null : ($inWindow[$id] ?? 0),
            'affected_users_all_time' => (int) ($row->affected_users ?? 0),
            'first_seen' => $this->moment($firstSeenAt),
            'last_seen' => $this->moment($row->last_seen_at ?? null),
            'resolved_at' => $this->moment($row->resolved_at ?? null),
            // New means first seen inside this window. After a deploy that is the difference
            // between "the release broke something" and "this was already broken".
            'is_new' => $firstSeenAt !== null && $firstSeenAt->greaterThanOrEqualTo($since),
            'is_selected' => $selectedId !== null && $selectedId === $id,
        ];
    }

    /**
     * The selected group, with the examples that make it debuggable.
     *
     * @return array<string, mixed>
     */
    private function selectedGroup(int $groupId, Carbon $since): array
    {
        try {
            $row = $this->reader->connection()->table('monitoring_error_groups')
                ->where('id', $groupId)
                ->limit(1)
                ->first();

            if ($row === null) {
                return [
                    'state' => 'no_data',
                    'id' => $groupId,
                    // "No longer stored" would be a guess: a mistyped or stale link asks for an id
                    // this store never issued, and that is not the same fact as a pruned group.
                    'remedy' => 'No group with id ' . $groupId . ' is in monitoring_error_groups. Either it was pruned — groups go after monitoring.retention.error_days, currently ' . app(MonitoringSettings::class)->retentionDays('error_days', 60) . ' days — or the link carries an id this store never issued.',
                ];
            }

            return [
                'state' => 'ok',
                'id' => $groupId,
                'group' => $this->presentGroup($row, $since, $this->occurrencesPerGroup([$groupId], $since), $groupId),
                'occurrences' => $this->occurrences($groupId, $since),
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'unavailable',
                'id' => $groupId,
                'message' => Metric::describeFailure($exception),
            ];
        }
    }

    /**
     * The most recent occurrences of one group, inside the window.
     *
     * Only the newest stack trace is returned. Every occurrence in a group shares a fingerprint,
     * which is built from the trace itself, so ten copies of the same frames would be ten times
     * the payload for no extra evidence.
     *
     * @return array<string, mixed>
     */
    private function occurrences(int $groupId, Carbon $since): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_errors')
                ->where('group_id', $groupId)
                ->where('created_at', '>=', $since->toDateTimeString())
                ->orderByDesc('created_at')
                ->limit(self::OCCURRENCE_LIMIT)
                ->get([
                    'id', 'trace_id', 'request_id', 'route', 'method', 'status', 'channel',
                    'platform', 'app_version', 'release', 'created_at', 'stack_trace',
                ]);

            if ($rows->isEmpty()) {
                return [
                    'state' => 'no_data',
                    'rows' => [],
                    'stack_trace' => null,
                    'limited' => false,
                    // A group reached by a shared link is looked up by id, not through the window,
                    // so it can perfectly well have last been seen before this window opened. The
                    // remedy must not assert that it was seen inside it.
                    'remedy' => 'No occurrence row for this group falls inside the selected window. The group may last have fired before the window opened — widen it — or its rows may have been pruned, which happens after monitoring.retention.error_days (currently ' . app(MonitoringSettings::class)->retentionDays('error_days', 60) . ' days).',
                ];
            }

            $newest = $rows->first();

            return [
                'state' => 'ok',
                'rows' => $rows->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'request_id' => $row->request_id === null ? null : (string) $row->request_id,
                    'trace_id' => $row->trace_id === null ? null : (string) $row->trace_id,
                    'route' => $row->route === null ? null : (string) $row->route,
                    'method' => $row->method === null ? null : (string) $row->method,
                    'status' => $row->status === null ? null : (int) $row->status,
                    'channel' => $row->channel === null ? null : (string) $row->channel,
                    'platform' => $row->platform === null ? null : (string) $row->platform,
                    'app_version' => $row->app_version === null ? null : (string) $row->app_version,
                    'release' => $row->release === null ? null : (string) $row->release,
                    'at' => $this->moment($row->created_at),
                ])->all(),
                // A full page of rows is the newest slice, not the whole story. Without this the
                // ten rows below a tile reading "40 in this window" look like a contradiction.
                'limited' => $rows->count() >= self::OCCURRENCE_LIMIT,
                'limit' => self::OCCURRENCE_LIMIT,
                'stack_trace' => $this->stackTrace($newest->stack_trace ?? null),
                'stack_trace_from' => [
                    'request_id' => $newest->request_id === null ? null : (string) $newest->request_id,
                    'at' => $this->moment($newest->created_at),
                ],
                'source' => 'monitoring_errors',
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'unavailable',
                'rows' => [],
                'stack_trace' => null,
                'message' => Metric::describeFailure($exception),
            ];
        }
    }

    /**
     * A stack trace safe to put on a screen.
     *
     * @return array<string, mixed>|null
     */
    private function stackTrace(?string $trace): ?array
    {
        if ($trace === null || trim($trace) === '') {
            return null;
        }

        $length = mb_strlen($trace);

        return [
            'text' => $this->redactor()->text(mb_substr($trace, 0, self::STACK_TRACE_CHARS)),
            'truncated' => $length > self::STACK_TRACE_CHARS,
            'frames_shown_chars' => min($length, self::STACK_TRACE_CHARS),
        ];
    }

    /**
     * Whether anything has ever reached this store, and how long it is kept.
     *
     * An empty window on a shop that records errors is good news. An empty window on a shop that
     * has never recorded one, or that has collection switched off entirely, is a different fact
     * wearing the same clothes — and the reassuring reading of it is the wrong one.
     *
     * @return array<string, mixed>
     */
    private function capture(): array
    {
        $retentionDays = app(MonitoringSettings::class)->retentionDays('error_days', 60);
        $collecting = (bool) config('monitoring.enabled', true);

        try {
            return [
                'state' => 'ok',
                // An existence probe, not a count: the question is only whether the store has ever
                // held a row, and COUNT(*) over a table with no useful predicate is exactly the
                // scan this page must never do.
                'ever_recorded' => $this->reader->connection()->table('monitoring_error_groups')->limit(1)->exists(),
                'collection_enabled' => $collecting,
                'retention_days' => $retentionDays,
                'remedy' => 'Exceptions appear here only once the application reports them into monitoring_error_groups. If this store has been serving traffic and nothing has ever been recorded, check that the monitoring exception reporter is registered in bootstrap/app.php withExceptions(), and that MONITORING_ENABLED is true.',
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'unavailable',
                'ever_recorded' => null,
                'collection_enabled' => $collecting,
                'retention_days' => $retentionDays,
                'message' => Metric::describeFailure($exception),
                'remedy' => 'The monitoring tables could not be read on the `' . (string) config('monitoring.connection', 'monitoring') . '` connection. Run `php artisan migrate` and check MONITORING_DB_* in .env.',
            ];
        }
    }

    /**
     * Why the table came back empty, as a key the view can phrase.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $capture
     */
    private function emptyReason(array $filters, array $options, array $capture): string
    {
        if ($capture['state'] !== 'ok' || $options['state'] !== 'ok') {
            return 'unreadable';
        }

        if ($capture['collection_enabled'] === false) {
            return 'collection_off';
        }

        if ($capture['ever_recorded'] === false) {
            return 'never_recorded';
        }

        $narrowed = $filters['q'] !== null
            || $filters['severity'] !== null
            || $filters['channel'] !== null
            || $filters['release'] !== null
            || $filters['route'] !== null
            || $filters['status'] !== 'all';

        if ((int) $options['groups_in_window'] > 0 && $narrowed) {
            return 'filtered_out';
        }

        return 'quiet_window';
    }

    private function groupQuery(Carbon $since, array $filters): Builder
    {
        return $this->applyFilters(
            $this->reader->connection()->table('monitoring_error_groups'),
            $since,
            $filters,
        );
    }

    /**
     * The filter row translated to SQL.
     *
     * Columns are written qualified so this same method can bound the occurrence join without a
     * second copy of the filter logic that would eventually disagree with this one.
     */
    private function applyFilters(Builder $query, Carbon $since, array $filters): Builder
    {
        $table = 'monitoring_error_groups';

        // last_seen_at carries the window and is indexed both on its own and as the second half of
        // the (status, last_seen_at) triage index, so adding a status narrows the scan rather than
        // widening it.
        $query->where($table . '.last_seen_at', '>=', $since->toDateTimeString());

        if ($filters['status'] !== 'all') {
            $query->where($table . '.status', $filters['status']);
        }

        foreach (['severity', 'channel', 'release', 'route'] as $column) {
            if ($filters[$column] !== null) {
                $query->where($table . '.' . $column, $filters[$column]);
            }
        }

        if ($filters['q'] !== null) {
            // A leading wildcard cannot use an index. It is affordable only because the window
            // above has already cut the candidates down to groups seen recently — this clause must
            // never run without it.
            $term = '%' . $this->escapeLike($filters['q']) . '%';
            $query->where(function (Builder $search) use ($table, $term) {
                $search->where($table . '.message', 'like', $term)
                    ->orWhere($table . '.exception_class', 'like', $term)
                    ->orWhere($table . '.route', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * Wildcards typed into the search box are literal text, not operators: somebody hunting for
     * "100%" is not asking to match everything.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * A stored UTC timestamp, ready to render and ready to age.
     *
     * @return array{at: string, age_seconds: int}|null
     */
    private function moment(mixed $stamp): ?array
    {
        $moment = $this->parsed($stamp);

        if ($moment === null) {
            return null;
        }

        return [
            'at' => Clock::display($moment)->toDateTimeString(),
            'age_seconds' => max(0, (int) $moment->diffInSeconds(Clock::now())),
        ];
    }

    /**
     * A stored timestamp as a UTC instant, or null when it is not one.
     *
     * Kept apart from moment() so a caller that also has to COMPARE the instant — is this group new
     * in the window? — reuses this parse instead of running its own against Clock::parse(), whose
     * signature rejects the plain DateTime a driver may hand back and would throw on it.
     */
    private function parsed(mixed $stamp): ?Carbon
    {
        if ($stamp instanceof \DateTimeInterface && !$stamp instanceof Carbon) {
            $stamp = Carbon::instance($stamp);
        }

        if (!$stamp instanceof Carbon && !is_string($stamp)) {
            return null;
        }

        // MySQL hands back zero dates as strings on a non-strict connection, and Carbon throws on
        // them.
        if (is_string($stamp) && ($stamp === '' || str_starts_with($stamp, '0000-'))) {
            return null;
        }

        try {
            return Clock::parse($stamp);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shortClass(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    private function redactor(): Redactor
    {
        return $this->redactor ??= Redactor::make();
    }
}
