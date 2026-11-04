<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Security: who was refused, who was let in and given power, and what this deployment cannot see.
 *
 * The whole section turns on one distinction that a security page gets wrong more often than it
 * gets right. What is recorded here is HTTP RESPONSES. `telemetry_requests` holds one row per
 * request with an exact status, so a 401, a 403 and a 419 can be counted precisely — but a 401 is
 * the server saying "not with those credentials, or none at all", which happens to an expired
 * session, a missing bearer token, a signed-out tab retrying an XHR and a guard that simply does
 * not admit this user type. It is NOT a rejected password. Nothing in this application listens for
 * `Illuminate\Auth\Events\Failed`, so a wrong password is not written anywhere at all, and a page
 * that headed a 401 count "failed logins" would be reporting a number it never measured. So the
 * refusal counts are labelled by what produced them, and the rejected-credential card states its
 * own absence rather than borrowing the refusal count to fill itself.
 *
 * The second rule here is privacy, and it is absolute rather than balanced against usefulness.
 * Nothing on this page is an address. Sources are the `visit_sessions.ip_hash` the telemetry
 * recorder already stored: a /24 (or /64) network, hashed with a salt that rotates every day, so
 * it cannot be reversed to an address and cannot be recognised as the same source tomorrow. That
 * makes it a legitimate grouping key and a useless tracking key, which is exactly the trade this
 * page wants — and it means the page must say so, because a table of "sources" that silently
 * forgets everyone at midnight would be read as a table of repeat offenders. `audit_logs` does
 * hold a real, unmasked address and a user agent; this panel does not read those columns, and
 * names that it does not rather than leaving it to look like an oversight.
 *
 * Zero is never printed for a window nothing was recorded in. Every count on this page is gated on
 * the per-request recorder having actually written a row inside the window: a quiet shop and a
 * stopped recorder both produce an empty table, they lead in opposite directions, and only one of
 * them means "no one is attacking you".
 */
class SecurityPanel implements Panel
{
    /**
     * The refusal statuses, and what each one actually means when this application returns it.
     *
     * A fixed vocabulary rather than a class of codes: an operator reading "4xx" learns nothing,
     * and the three collapse into one number only if their causes are the same, which they are
     * not. The values are translation keys — panel-authored, never composed from a column.
     */
    private const REFUSAL_MEANINGS = [
        401 => 'the_request_carried_no_usable_credentials_a_missing_expired_or_rejected_session_or_token',
        403 => 'the_caller_was_identified_and_still_not_allowed_a_guard_a_policy_or_a_missing_permission',
        419 => 'the_csrf_token_was_missing_or_the_session_had_expired_before_the_form_was_submitted',
    ];

    /** Requests the throttle refused. Recorded volume abuse, as opposed to inferred. */
    private const THROTTLE_STATUS = 429;

    /**
     * Audit actions that would be a genuine rejected credential, if anything wrote them.
     *
     * Read as an allow-list rather than a prefix so that `auth.login` — a SUCCESSFUL sign-in — can
     * never be counted as a failure by a wildcard that grew.
     */
    private const CREDENTIAL_ACTIONS = ['auth.login_failed', 'auth.locked_out'];

    /**
     * The action families a security reviewer opens this page expecting to find.
     *
     * Presence is measured from the audit vocabulary this deployment has actually written, not
     * asserted here: the point of the list is to name what is missing, and a hard-coded claim
     * would go stale the day somebody wires one of them up.
     */
    private const SECURITY_ACTION_FAMILIES = [
        // Named by the module prefix this platform actually writes, not by the word a reviewer
        // would use. The list used to read ['auth', 'role', 'permission', 'setting'], and three of
        // those four never match an action name — roles and employees are written as `access.*`
        // and settings as `settings.*` — so the page reported permanent blind spots over coverage
        // that was there all along, which is a worse failure than the one it was looking for.
        'authentication' => ['auth'],
        'access_control' => ['access'],
        'configuration' => ['settings', 'platform'],
        'monitoring' => ['monitoring'],
    ];

    /**
     * Timeline types worth reading on a security page.
     *
     * monitoring_events carries eight types and none of them is "security" — see EventLog::TYPES.
     * An alert firing and a change to monitoring's own configuration are the two that bear on this
     * section, so those are read and the absence of a security type is stated rather than hidden
     * behind an empty table.
     */
    private const SIGNAL_EVENT_TYPES = [EventLog::ALERT, EventLog::CONFIG];

    /** Statuses on monitoring_errors that would be an authorisation failure that threw. */
    private const ERROR_STATUSES = [401, 403];

    /** Readings drawn as single values above the tables. Each is honestly one number, or an absence. */
    private const HEADLINE = [
        'authentication_refusals', 'unauthorized_401', 'forbidden_403',
        'rejected_credentials', 'rate_limited_requests', 'distinct_refused_sources',
        'privileged_actions_recorded',
    ];

    private const RECORDER = 'telemetry_requests';

    private const SESSIONS = 'visit_sessions';

    private const AUDIT = 'audit_logs';

    private const ERRORS = 'monitoring_errors';

    private const ERROR_GROUPS = 'monitoring_error_groups';

    private const EVENTS = 'monitoring_events';

    /** Rows in the refused-path table. Enough to see a sweep, short enough to read. */
    private const MAX_PATH_ROWS = 40;

    /** Distinct sources listed. A page listing more than this is a page nobody finishes. */
    private const MAX_SOURCE_ROWS = 25;

    private const MAX_THROTTLE_ROWS = 15;

    private const MAX_AUDIT_ROWS = 50;

    private const MAX_ACTION_GROUPS = 60;

    /** Distinct action names read back to describe what this deployment audits at all. */
    private const MAX_VOCABULARY = 200;

    private const MAX_ERROR_ROWS = 25;

    private const MAX_EVENT_ROWS = 25;

    private const MAX_USER_TYPES = 8;

    /** Changed field NAMES kept per audit row. The values are never read; see fieldsChanged(). */
    private const MAX_CHANGED_FIELDS = 8;

    /** How much of the one-way source digest is shown. Grouping is always on the whole of it. */
    private const DIGEST_CHARS = 12;

    /** Free text from a column is bounded before it enters a payload that has to survive JSON. */
    private const TEXT_CHARS = 191;

    /**
     * The throwable behind each block that failed, kept out of the payload.
     *
     * Metric::failed() is the only way to reach the FAILED state and it takes a throwable, while
     * everything that reaches a screen has to be redacted first. So each caught failure is
     * recorded here as a wrapper carrying the already-redacted sentence, and the headline card
     * built from it says "could not be read" rather than "no data" — which is what the block card
     * under it says, and two halves of one page disagreeing about whether a table was readable is
     * the confusion this section exists to remove.
     *
     * @var array<string, \Throwable>
     */
    private array $faults = [];

    /**
     * The sentence the collection banner already states at the top of the section.
     *
     * When the request log cannot answer, every telemetry block inherits that one reason. Printing
     * it again on each derived card turns a single fault into six, so a card whose reason is
     * word-for-word the banner's drops it and lets the banner carry it — except the card that is
     * the block's own headline number, which keeps it so the count is never bare.
     */
    private ?string $hoistedNote = null;

    /** The remedy shown beside the hoisted note, suppressed on derived cards for the same reason. */
    private ?string $hoistedRemedy = null;

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $minutes = $window['minutes'];

        $collection = $this->collection($minutes);

        // Only a collection that failed is hoisted. When it read fine there is no banner, and
        // suppressing a card's note against a sentence nobody is shown would delete it outright.
        if ($collection['state'] !== 'ok') {
            $this->hoistedNote = is_string($collection['note']) ? $collection['note'] : null;
            $this->hoistedRemedy = is_string($collection['remedy']) ? $collection['remedy'] : null;
        }

        $refusals = $this->refusals($minutes, $collection);
        $sources = $this->sources($minutes, $collection, $refusals);
        $volume = $this->volume($minutes, $collection);
        $vocabulary = $this->auditVocabulary();
        $credentials = $this->credentials($minutes, $vocabulary);
        $activity = $this->adminActivity($minutes, $vocabulary);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $minutes,
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
                // The per-request log is pruned. A range wider than retention reads a window the
                // log does not cover, and its counts would be short without saying so.
                'telemetry_retention_days' => $collection['retention_days'],
                'covered_by_retention' => !$collection['range_exceeds_retention'],
            ],
            'collection' => $collection,
            'headline' => $this->headline($refusals, $credentials, $sources, $volume, $activity),
            'refusals' => $refusals,
            'sources' => $sources,
            'credentials' => $credentials,
            'volume' => $volume,
            'signals' => $this->signals($range),
            'admin_activity' => $activity,
            'audit_coverage' => $vocabulary,
            'error_occurrences' => $this->errorOccurrences($range),
            'privacy' => $this->privacy(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Is anything being recorded at all — the one fault, stated once

    /**
     * Whether the per-request log can answer this window, and how it fails if it cannot.
     *
     * Every count below depends on this, so it is read once and hoisted. Without it each table
     * would repeat the same sentence, and — much worse — an empty window would be drawn as a row
     * of zeros: "no 401s recorded" and "no requests recorded" are the same table and opposite news.
     *
     * @return array<string, mixed>
     */
    private function collection(int $minutes): array
    {
        $retention = max(1, (int) config('telemetry.retention_days', 30));
        $exceeds = $minutes > $retention * 1440;

        $shape = [
            'source' => self::RECORDER,
            'enabled' => (bool) config('telemetry.enabled', true),
            'retention_days' => $retention,
            'range_exceeds_retention' => $exceeds,
            'ever_recorded' => null,
            'newest_recorded_at' => null,
            'requests_in_window' => null,
            'measurable' => false,
        ];

        if (!$shape['enabled']) {
            return array_merge($shape, [
                'state' => 'not_configured',
                'note' => 'Per-request telemetry is switched off, so nothing has been written to ' . self::RECORDER . ' since it was disabled. No refusal on this page is a count of zero — it is the absence of a log.',
                'remedy' => 'Set TELEMETRY_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ]);
        }

        try {
            $since = $this->shopWindowStart($minutes);

            $inWindow = (int) DB::table(self::RECORDER)->where('created_at', '>=', $since)->count();
            $newest = DB::table(self::RECORDER)->orderByDesc('created_at')->limit(1)->value('created_at');
        } catch (\Throwable $exception) {
            return array_merge($shape, [
                'state' => 'failed',
                'note' => $this->fault('collection', $exception),
                'remedy' => null,
            ]);
        }

        $shape['ever_recorded'] = $newest !== null;
        $shape['newest_recorded_at'] = $this->shopStamp($newest);
        $shape['requests_in_window'] = $inWindow;

        if ($newest === null) {
            return array_merge($shape, [
                'state' => 'no_data',
                'note' => 'No request of any kind has ever been recorded on this deployment, so nothing on this page can be counted — including the refusals, which would otherwise read as zero.',
                'remedy' => 'Requests are written by the telemetry middleware after the response is sent. Confirm `telemetry.enabled` is true and that traffic is reaching the application at all.',
            ]);
        }

        if ($inWindow === 0) {
            return array_merge($shape, [
                'state' => 'no_data',
                'note' => 'The request log holds rows but none inside this window, so nothing in it was refused and nothing in it was allowed — neither is a measurement. The most recent recorded request is ' . ($shape['newest_recorded_at'] ?? 'unreadable') . ' (' . Clock::displayTimezone() . ').',
                'remedy' => 'Widen the range. If the newest recorded request is old while the shop is serving traffic, the telemetry middleware has stopped writing and the counts on this page are blind rather than clean.',
            ]);
        }

        return array_merge($shape, [
            'state' => 'ok',
            'note' => $exceeds
                ? 'The selected range is longer than the ' . $retention . ' days this log is kept for, so everything below covers the retained part of it only.'
                : null,
            'remedy' => null,
            'measurable' => true,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Refusals: by status, and by the path that produced them

    /**
     * Every refused request in the window, grouped by what refused it and where.
     *
     * @param  array<string, mixed>  $collection
     * @return array<string, mixed>
     */
    private function refusals(int $minutes, array $collection): array
    {
        $empty = [
            'state' => $collection['state'],
            'note' => $collection['note'],
            'remedy' => $collection['remedy'],
            'source' => self::RECORDER,
            'statuses' => array_keys(self::REFUSAL_MEANINGS),
            'total' => null,
            'requests_in_window' => $collection['requests_in_window'],
            'rate_pct' => null,
            // Present in every shape, counts and all, because these three rows are also the page's
            // definition of what a refusal is. A window that could not be read must still be able
            // to say what it would have counted; hiding the meanings with the numbers would drop
            // the one paragraph that stops a 401 being read as a rejected password.
            'by_status' => $this->emptyStatusRows(),
            'by_user_type' => [],
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_PATH_ROWS,
        ];

        if (!$collection['measurable']) {
            return $empty;
        }

        $statuses = array_keys(self::REFUSAL_MEANINGS);

        try {
            $since = $this->shopWindowStart($minutes);

            $byStatus = DB::table(self::RECORDER)
                ->where('created_at', '>=', $since)
                ->whereIn('status', $statuses)
                ->groupBy('status')
                ->limit(count($statuses))
                ->get(['status', DB::raw('COUNT(*) AS hits')]);

            $byUserType = DB::table(self::RECORDER)
                ->where('created_at', '>=', $since)
                ->whereIn('status', $statuses)
                ->groupBy('user_type')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(self::MAX_USER_TYPES)
                ->get(['user_type', DB::raw('COUNT(*) AS hits')]);

            $paths = DB::table(self::RECORDER)
                ->where('created_at', '>=', $since)
                ->whereIn('status', $statuses)
                ->groupBy('status', 'path', 'channel')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(self::MAX_PATH_ROWS + 1)
                ->get([
                    'status', 'path', 'channel',
                    DB::raw('COUNT(*) AS hits'),
                    DB::raw('MIN(created_at) AS first_at'),
                    DB::raw('MAX(created_at) AS last_at'),
                ]);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing this read blanks the refusal
            // tables, while letting it escape would blank the audit trail that read perfectly well.
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->fault('refusals', $exception),
                'remedy' => null,
            ]);
        }

        $counts = [];
        foreach ($byStatus as $row) {
            $counts[(int) $row->status] = (int) $row->hits;
        }

        $total = array_sum($counts);
        $recorded = max(0, (int) $collection['requests_in_window']);

        $statusRows = [];
        foreach ($statuses as $status) {
            $hits = $counts[$status] ?? 0;
            $statusRows[] = [
                'status' => $status,
                'hits' => $hits,
                // Share of the refusals, not of all traffic: the bar is a breakdown of this table.
                'share_pct' => $total > 0 ? round(100 * $hits / $total, 1) : null,
                'meaning' => self::REFUSAL_MEANINGS[$status],
            ];
        }

        $userTypes = [];
        foreach ($byUserType as $row) {
            $userTypes[] = [
                // Null predates the column being filled and is not evidence of a guest, so it is
                // its own label rather than folded into one that means something specific.
                'user_type' => $row->user_type === null ? null : $this->safeText($row->user_type, 32),
                'hits' => (int) $row->hits,
                'share_pct' => $total > 0 ? round(100 * (int) $row->hits / $total, 1) : null,
            ];
        }

        $rows = [];
        foreach ($paths->take(self::MAX_PATH_ROWS) as $row) {
            $rows[] = [
                'status' => (int) $row->status,
                'path' => $this->safeText($row->path, self::TEXT_CHARS),
                'channel' => $this->safeText($row->channel, 16),
                'hits' => (int) $row->hits,
                'share_pct' => $total > 0 ? round(100 * (int) $row->hits / $total, 1) : null,
                'first_at' => $this->shopStamp($row->first_at),
                'last_at' => $this->shopStamp($row->last_at),
                'meaning' => self::REFUSAL_MEANINGS[(int) $row->status] ?? null,
            ];
        }

        return array_merge($empty, [
            // A measured zero. The window holds recorded requests and none of them was refused,
            // which is a reading and is drawn as one.
            'state' => 'ok',
            'note' => $total === 0
                ? 'Every request recorded in this window was answered without a refusal. This is a measured zero, not an empty log: ' . number_format($recorded) . ' requests were recorded and none returned 401, 403 or 419.'
                : $collection['note'],
            'remedy' => null,
            'total' => $total,
            'rate_pct' => $recorded > 0 ? round(100 * $total / $recorded, 3) : null,
            'by_status' => $statusRows,
            'by_user_type' => $userTypes,
            'rows' => $rows,
            'truncated' => $paths->count() > self::MAX_PATH_ROWS,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Where the refusals came from — as a pseudonym that expires, never an address

    /**
     * The status vocabulary with no counts against it — null hits, never zero ones.
     *
     * @return array<int, array<string, mixed>>
     */
    private function emptyStatusRows(): array
    {
        $rows = [];
        foreach (self::REFUSAL_MEANINGS as $status => $meaning) {
            $rows[] = ['status' => $status, 'hits' => null, 'share_pct' => null, 'meaning' => $meaning];
        }

        return $rows;
    }

    /**
     * Refusals per stored source digest.
     *
     * The digest is `visit_sessions.ip_hash`: the caller's address masked to its network and then
     * hashed with a salt containing today's date. Three consequences the payload carries out loud,
     * because each of them changes what the table means:
     *
     *  - it cannot be reversed to an address, so nothing here can be blocked from this page;
     *  - it changes at midnight, so a window that crosses one splits a single source in two;
     *  - only web sessions have one at all. API and app traffic carries no session row, so those
     *    refusals are counted and reported as unattributed rather than quietly dropped.
     *
     * @param  array<string, mixed>  $collection
     * @param  array<string, mixed>  $refusals
     * @return array<string, mixed>
     */
    private function sources(int $minutes, array $collection, array $refusals): array
    {
        $empty = [
            'state' => $collection['state'],
            'note' => $collection['note'],
            'remedy' => $collection['remedy'],
            'source' => self::RECORDER . ' joined to ' . self::SESSIONS . '.ip_hash',
            'rows' => [],
            'attributed' => null,
            'unattributed' => null,
            'distinct' => null,
            'truncated' => false,
            'limit' => self::MAX_SOURCE_ROWS,
            'digest_chars' => self::DIGEST_CHARS,
            'salt_rotates_daily' => true,
            'window_crosses_midnight' => $this->windowCrossesMidnight($minutes),
        ];

        if (!$collection['measurable'] || $refusals['state'] !== 'ok') {
            return $empty;
        }

        if ((int) $refusals['total'] === 0) {
            return array_merge($empty, [
                'state' => 'no_data',
                'note' => 'Nothing was refused in this window, so there is no source to attribute. This follows from the measured zero above rather than from an unread table.',
                'remedy' => null,
                'attributed' => 0,
                'unattributed' => 0,
                'distinct' => 0,
            ]);
        }

        try {
            $since = $this->shopWindowStart($minutes);
            $statuses = array_keys(self::REFUSAL_MEANINGS);

            $grouped = DB::table(self::RECORDER . ' AS r')
                ->join(self::SESSIONS . ' AS s', 's.id', '=', 'r.session_id')
                ->where('r.created_at', '>=', $since)
                ->whereIn('r.status', $statuses)
                ->whereNotNull('s.ip_hash')
                ->groupBy('s.ip_hash')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(self::MAX_SOURCE_ROWS + 1)
                ->get([
                    's.ip_hash',
                    DB::raw('COUNT(*) AS refusals'),
                    DB::raw('COUNT(DISTINCT r.path) AS paths'),
                    DB::raw('MAX(r.created_at) AS last_at'),
                ]);

            $attributed = (int) DB::table(self::RECORDER . ' AS r')
                ->join(self::SESSIONS . ' AS s', 's.id', '=', 'r.session_id')
                ->where('r.created_at', '>=', $since)
                ->whereIn('r.status', $statuses)
                ->whereNotNull('s.ip_hash')
                ->count();
        } catch (\Throwable $exception) {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->fault('sources', $exception),
                'remedy' => null,
            ]);
        }

        $rows = [];
        foreach ($grouped->take(self::MAX_SOURCE_ROWS) as $row) {
            $digest = $this->digest($row->ip_hash);
            if ($digest === null) {
                continue;
            }

            $rows[] = [
                'digest' => $digest,
                'short' => substr($digest, 0, self::DIGEST_CHARS),
                'refusals' => (int) $row->refusals,
                'paths' => (int) $row->paths,
                'share_pct' => round(100 * (int) $row->refusals / max(1, (int) $refusals['total']), 1),
                'last_at' => $this->shopStamp($row->last_at),
            ];
        }

        $unattributed = max(0, (int) $refusals['total'] - $attributed);

        // Two ways to end up with no rows, and they are different facts. No refusal had a session
        // behind it at all — normal for API and app traffic, which carries none. Or sessions were
        // joined and every digest in them was malformed, which is a corrupted column rather than a
        // property of the traffic, and pointing the operator at the API would send them nowhere.
        $unsessioned = $attributed === 0;

        return array_merge($empty, [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => match (true) {
                $rows !== [] => null,
                $unsessioned => 'Every refusal in this window came from a request with no visit session behind it — API and mobile-app traffic carries none — so not one of them can be attributed to a source digest.',
                default => 'Sessions were found for ' . $attributed . ' of these refusals, and not one of them held a readable source digest. That is an unwritten or corrupted ip_hash column rather than a property of the traffic.',
            },
            'remedy' => match (true) {
                $rows !== [] => null,
                $unsessioned => 'Attributing an API refusal to a source would mean storing an identifier against the API request itself. That is a privacy decision rather than a missing feature, and this page will not infer one.',
                default => 'Check that TelemetryRecorder is filling visit_sessions.ip_hash through App\Services\Telemetry\ClientIdentity::networkHash(); a session row without it cannot be grouped by source.',
            },
            'rows' => $rows,
            'attributed' => $attributed,
            'unattributed' => $unattributed,
            'distinct' => count($rows),
            'truncated' => $grouped->count() > self::MAX_SOURCE_ROWS,
        ]);
    }

    /**
     * A stored digest, or null when the column does not hold one.
     *
     * Checked against the shape the recorder writes rather than passed through the text redactor:
     * the redactor's card-number rule rewrites long digit runs, and a hexadecimal digest can
     * contain one — which would silently rename a source and split its row in two.
     */
    private function digest(mixed $stored): ?string
    {
        if (!is_string($stored) || preg_match('/^[0-9a-f]{8,64}$/i', $stored) !== 1) {
            return null;
        }

        return strtolower($stored);
    }

    /**
     * Whether the salt rotated inside this window.
     *
     * The date in the salt is taken in the process timezone, which is the one the recorder wrote
     * its rows in — so the boundary is asked about there and not in UTC or in the display zone.
     */
    private function windowCrossesMidnight(int $minutes): bool
    {
        try {
            $zone = date_default_timezone_get();

            return Clock::minutesAgo($minutes)->setTimezone($zone)->toDateString()
                !== Clock::now()->setTimezone($zone)->toDateString();
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------------------------
    // Recorded volume abuse

    /**
     * Requests the throttle actually refused.
     *
     * The one recorded signal of suspicious volume this deployment has. It is a measurement of a
     * limiter firing, not a judgement that an attack happened — and it is silent about volume that
     * stayed under whatever limit is configured, which the note says rather than implying coverage.
     *
     * @param  array<string, mixed>  $collection
     * @return array<string, mixed>
     */
    private function volume(int $minutes, array $collection): array
    {
        $empty = [
            'state' => $collection['state'],
            'note' => $collection['note'],
            'remedy' => $collection['remedy'],
            'source' => self::RECORDER,
            'status' => self::THROTTLE_STATUS,
            'throttled' => null,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_THROTTLE_ROWS,
        ];

        if (!$collection['measurable']) {
            return $empty;
        }

        try {
            $since = $this->shopWindowStart($minutes);

            $rows = DB::table(self::RECORDER)
                ->where('created_at', '>=', $since)
                ->where('status', self::THROTTLE_STATUS)
                ->groupBy('path', 'channel')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(self::MAX_THROTTLE_ROWS + 1)
                ->get([
                    'path', 'channel',
                    DB::raw('COUNT(*) AS hits'),
                    DB::raw('MAX(created_at) AS last_at'),
                ]);
        } catch (\Throwable $exception) {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->fault('volume', $exception),
                'remedy' => null,
            ]);
        }

        $throttled = 0;
        $listed = [];
        foreach ($rows->take(self::MAX_THROTTLE_ROWS) as $row) {
            $throttled += (int) $row->hits;
            $listed[] = [
                'path' => $this->safeText($row->path, self::TEXT_CHARS),
                'channel' => $this->safeText($row->channel, 16),
                'hits' => (int) $row->hits,
                'last_at' => $this->shopStamp($row->last_at),
            ];
        }

        return array_merge($empty, [
            'state' => 'ok',
            'note' => $listed === []
                ? 'No request in this window was refused by a rate limiter. That is a measurement of the limiter never firing, not a statement that traffic was normal — volume below the configured limit leaves no trace here.'
                : null,
            'remedy' => null,
            // The sum is over the listed rows only, so a truncated list says so rather than
            // publishing a total the table underneath it does not add up to.
            'throttled' => $rows->count() > self::MAX_THROTTLE_ROWS ? null : $throttled,
            'rows' => $listed,
            'truncated' => $rows->count() > self::MAX_THROTTLE_ROWS,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // The rejected credential — the number this application does not have

    /**
     * Sign-in failures, if anything recorded one.
     *
     * Detected rather than asserted. The vocabulary read from audit_logs says whether this
     * deployment has ever written an `auth.*` action; when it has not, the card refuses to exist
     * instead of borrowing the 401 count, which measures something else entirely.
     *
     * @param  array<string, mixed>  $vocabulary
     * @return array<string, mixed>
     */
    private function credentials(int $minutes, array $vocabulary): array
    {
        $source = self::AUDIT;
        $note = 'This page counts HTTP responses, not password checks. A 401 or 403 on a sign-in route means the request was refused — by a missing session, an expired token, a guard, a policy or a limiter — and nothing in this application records whether a submitted password was wrong. The two are different measurements and neither estimates the other.';
        $remedy = 'Register listeners for `Illuminate\Auth\Events\Failed` and `Illuminate\Auth\Events\Lockout` in app/Providers/EventServiceProvider.php that record through `App\Services\AuditLogger` as `auth.login_failed` and `auth.locked_out`, passing the attempted identifier in $context and never in actor_name. Until that listener exists a rejected password leaves no trace anywhere in this application.';

        $empty = [
            'state' => 'not_configured',
            'note' => $note,
            'remedy' => $remedy,
            'source' => $source,
            'actions' => self::CREDENTIAL_ACTIONS,
            'recorded' => false,
            'count' => null,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_AUDIT_ROWS,
        ];

        if ($vocabulary['state'] === 'failed') {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $vocabulary['note'] ?? $note,
                'remedy' => null,
            ]);
        }

        // An empty vocabulary intersects to nothing and falls through to the not_configured shape
        // above, which is the right answer either way: no sign-in failure has ever been recorded.
        $present = array_values(array_intersect(self::CREDENTIAL_ACTIONS, $vocabulary['actions']));
        if ($present === []) {
            return $empty;
        }

        try {
            $rows = DB::table(self::AUDIT)
                ->where('created_at', '>=', $this->shopWindowStart($minutes))
                ->whereIn('action', $present)
                ->orderByDesc('created_at')
                ->limit(self::MAX_AUDIT_ROWS + 1)
                ->get(['action', 'actor_type', 'actor_name', 'subject_type', 'subject_id', 'created_at']);
        } catch (\Throwable $exception) {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->fault('credentials', $exception),
                'remedy' => null,
            ]);
        }

        $listed = [];
        foreach ($rows->take(self::MAX_AUDIT_ROWS) as $row) {
            $listed[] = [
                'action' => $this->safeText($row->action, 100),
                'actor_type' => $this->safeText($row->actor_type, 40),
                'actor_name' => $this->safeText($row->actor_name, self::TEXT_CHARS),
                'subject_type' => $this->safeText($row->subject_type, 100),
                'subject_id' => $this->safeText($row->subject_id, 140),
                'occurred_at' => $this->shopStamp($row->created_at),
            ];
        }

        return array_merge($empty, [
            'state' => $listed === [] ? 'no_data' : 'ok',
            'recorded' => true,
            'note' => $listed === []
                ? 'This deployment does record sign-in failures, and none was recorded inside this window.'
                : 'These are recorded sign-in failures — a credential this application checked and rejected. They are counted separately from the HTTP refusals above, which include expired sessions and permission denials.',
            'remedy' => null,
            'count' => count($listed),
            'rows' => $listed,
            'truncated' => $rows->count() > self::MAX_AUDIT_ROWS,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // The audit trail

    /**
     * Every action name this deployment has ever written, and which security families are missing.
     *
     * One bounded read of the indexed action column. It exists so the scope sentence under the
     * audit table is a measurement rather than a claim: what the trail covers is whatever has been
     * wired to AuditLogger, and stating that from a constant would go stale the day it changes.
     *
     * @return array<string, mixed>
     */
    private function auditVocabulary(): array
    {
        $empty = [
            'state' => 'no_data',
            'note' => null,
            'remedy' => null,
            'source' => self::AUDIT,
            'actions' => [],
            'modules' => [],
            'families' => [],
            'truncated' => false,
            'limit' => self::MAX_VOCABULARY,
        ];

        try {
            $actions = DB::table(self::AUDIT)
                ->distinct()
                ->orderBy('action')
                ->limit(self::MAX_VOCABULARY + 1)
                ->pluck('action');
        } catch (\Throwable $exception) {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->fault('audit', $exception),
            ]);
        }

        $names = [];
        $modules = [];
        foreach ($actions->take(self::MAX_VOCABULARY) as $action) {
            $name = $this->safeText($action, 100);
            if ($name === null) {
                continue;
            }

            $names[] = $name;
            $prefix = strtok($name, '.');
            if (is_string($prefix) && $prefix !== '') {
                $modules[$prefix] = ($modules[$prefix] ?? 0) + 1;
            }
        }

        // Which of the families a security reviewer expects have ever been written here. Reported
        // per family so the page can name the specific blind spot instead of one vague disclaimer.
        $families = [];
        foreach (self::SECURITY_ACTION_FAMILIES as $family => $prefixes) {
            $families[$family] = array_intersect($prefixes, array_keys($modules)) !== [];
        }

        return array_merge($empty, [
            'state' => $names === [] ? 'no_data' : 'ok',
            'note' => $names === []
                ? 'No action has ever been recorded in the audit trail on this deployment. Every module that writes to it does so through App\Services\AuditLogger, and none has been exercised here.'
                : null,
            'remedy' => $names === []
                ? 'The trail fills itself as audited actions happen — approving a settlement, paying a payout, changing a shipping zone. Nothing needs enabling; if a consequential action has been taken and left no line, that action is simply not wired to `App\Services\AuditLogger`.'
                : null,
            'actions' => $names,
            'modules' => $modules,
            'families' => $families,
            'truncated' => $actions->count() > self::MAX_VOCABULARY,
        ]);
    }

    /**
     * Who did what, to which record, most recent first.
     *
     * Three columns of this table are deliberately not read. `ip_address` holds a real, unmasked
     * address; `user_agent` is a device fingerprint; `before`/`after` hold the changed VALUES, and
     * on a row like seller.bank_details_changed those values are exactly what must never be drawn
     * on a dashboard. The field NAMES are kept, because "who changed which fields on which record"
     * is the question this table is for and it can be answered without the contents.
     *
     * @param  array<string, mixed>  $vocabulary
     * @return array<string, mixed>
     */
    private function adminActivity(int $minutes, array $vocabulary): array
    {
        $empty = [
            'state' => 'no_data',
            'note' => null,
            'remedy' => null,
            'source' => self::AUDIT,
            'rows' => [],
            'actions' => [],
            'actors' => null,
            'total' => null,
            'truncated' => false,
            'actions_truncated' => false,
            'limit' => self::MAX_AUDIT_ROWS,
            // Named rather than silently absent: a column that is stored and not drawn looks the
            // same as one that was never stored, and here the difference is the whole point.
            'withheld' => ['ip_address', 'user_agent', 'before', 'after'],
        ];

        if ($vocabulary['state'] === 'failed') {
            return array_merge($empty, ['state' => 'failed', 'note' => $vocabulary['note']]);
        }

        if ($vocabulary['state'] !== 'ok') {
            // The vocabulary read proved the table is readable and holds no action at all, so the
            // count for this window is a measured zero rather than an unread one — with the reason
            // attached, because a bare 0 under "privileged actions" reads as "nothing happened"
            // when what it means is "nothing this application audits has happened".
            return array_merge($empty, [
                'state' => 'ok',
                'note' => $vocabulary['note'],
                'remedy' => $vocabulary['remedy'],
                'actors' => 0,
                'total' => 0,
            ]);
        }

        try {
            $since = $this->shopWindowStart($minutes);

            $rows = DB::table(self::AUDIT)
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(self::MAX_AUDIT_ROWS + 1)
                ->get(['actor_type', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'before', 'after', 'created_at']);

            $grouped = DB::table(self::AUDIT)
                ->where('created_at', '>=', $since)
                ->groupBy('action')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(self::MAX_ACTION_GROUPS + 1)
                ->get(['action', DB::raw('COUNT(*) AS hits')]);

            $actors = (int) DB::table(self::AUDIT)
                ->where('created_at', '>=', $since)
                ->distinct()
                ->count(DB::raw('CONCAT(COALESCE(actor_type, \'\'), \':\', COALESCE(actor_id, 0))'));

            $total = (int) DB::table(self::AUDIT)->where('created_at', '>=', $since)->count();
        } catch (\Throwable $exception) {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->fault('audit', $exception),
            ]);
        }

        $listed = [];
        foreach ($rows->take(self::MAX_AUDIT_ROWS) as $row) {
            $changed = $this->fieldsChanged($row->before, $row->after);

            $listed[] = [
                'action' => $this->safeText($row->action, 100),
                'module' => $this->moduleOf($row->action),
                'actor_type' => $this->safeText($row->actor_type, 40),
                'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                'actor_name' => $this->safeText($row->actor_name, self::TEXT_CHARS),
                'subject_type' => $this->safeText($row->subject_type, 100),
                'subject_id' => $this->safeText($row->subject_id, 140),
                'changed_fields' => $changed['fields'],
                'changed_field_count' => $changed['count'],
                'fields_truncated' => $changed['truncated'],
                'occurred_at' => $this->shopStamp($row->created_at),
            ];
        }

        $actions = [];
        foreach ($grouped->take(self::MAX_ACTION_GROUPS) as $row) {
            $name = $this->safeText($row->action, 100);
            if ($name === null) {
                continue;
            }

            $actions[] = ['action' => $name, 'hits' => (int) $row->hits];
        }

        return array_merge($empty, [
            'state' => $listed === [] ? 'no_data' : 'ok',
            'note' => $listed === []
                ? 'No audited action was taken inside this window. The trail itself is working — it holds ' . count($vocabulary['actions']) . ' distinct action names from earlier windows.'
                : null,
            'remedy' => $listed === [] ? 'Widen the range to reach the last recorded action.' : null,
            'rows' => $listed,
            'actions' => $actions,
            'actors' => $actors,
            'total' => $total,
            'truncated' => $rows->count() > self::MAX_AUDIT_ROWS,
            'actions_truncated' => $grouped->count() > self::MAX_ACTION_GROUPS,
        ]);
    }

    /**
     * The NAMES of the fields an audited action changed. Never their values.
     *
     * @return array{fields: array<int, string>, count: int|null, truncated: bool}
     */
    private function fieldsChanged(mixed $before, mixed $after): array
    {
        $keys = [];

        foreach ([$before, $after] as $document) {
            if (!is_string($document) || trim($document) === '') {
                continue;
            }

            $decoded = json_decode($document, true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach (array_keys($decoded) as $key) {
                if (is_string($key) || is_int($key)) {
                    $keys[(string) $key] = true;
                }
            }
        }

        if ($keys === []) {
            return ['fields' => [], 'count' => 0, 'truncated' => false];
        }

        $names = array_keys($keys);
        $shown = [];
        foreach (array_slice($names, 0, self::MAX_CHANGED_FIELDS) as $name) {
            $safe = $this->safeText($name, 64);
            if ($safe !== null) {
                $shown[] = $safe;
            }
        }

        return [
            'fields' => $shown,
            'count' => count($names),
            'truncated' => count($names) > self::MAX_CHANGED_FIELDS,
        ];
    }

    private function moduleOf(mixed $action): ?string
    {
        $name = $this->safeText($action, 100);
        if ($name === null) {
            return null;
        }

        $prefix = strtok($name, '.');

        return is_string($prefix) && $prefix !== '' && $prefix !== $name ? $prefix : null;
    }

    // -------------------------------------------------------------------------------------------
    // What the monitoring store itself holds about refusals

    /**
     * Authorisation failures that arrived as reported exceptions.
     *
     * A different population from the refusal counts above and worth keeping apart: this table
     * only ever holds a 401 or 403 that was THROWN and reported, which is a small subset of the
     * responses that carry those codes. When nothing has ever reached the store the block says the
     * reporter is missing, rather than drawing a zero that would read as "nothing was refused".
     *
     * @return array<string, mixed>
     */
    private function errorOccurrences(string $range): array
    {
        $empty = [
            'state' => 'no_data',
            'note' => null,
            'remedy' => null,
            'source' => self::ERRORS,
            'statuses' => self::ERROR_STATUSES,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_ERROR_ROWS,
        ];

        try {
            $connection = $this->reader->connection();

            // An existence probe, not a count: the only question is whether anything has ever
            // reached this store, and COUNT(*) with no predicate is the scan this page must avoid.
            $everRecorded = $connection->table(self::ERROR_GROUPS)->limit(1)->exists();

            $rows = $connection->table(self::ERRORS)
                ->where('created_at', '>=', $this->reader->since($range))
                ->whereIn('status', self::ERROR_STATUSES)
                ->orderByDesc('created_at')
                ->limit(self::MAX_ERROR_ROWS + 1)
                ->get(['route', 'method', 'status', 'channel', 'user_type', 'platform', 'created_at']);
        } catch (\Throwable $exception) {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->fault('error_occurrences', $exception),
            ]);
        }

        if (!$everRecorded) {
            return array_merge($empty, [
                'state' => 'not_configured',
                'note' => 'No exception of any kind has ever been reported into the monitoring error store on this deployment, so it holds no 401 or 403 either. An empty table here is a statement about the reporter, not about authorisation.',
                'remedy' => 'Exceptions appear here only once the application reports them into monitoring_error_groups. Check that the monitoring exception reporter is registered in bootstrap/app.php withExceptions(), and that MONITORING_ENABLED is true.',
            ]);
        }

        $listed = [];
        foreach ($rows->take(self::MAX_ERROR_ROWS) as $row) {
            $listed[] = [
                'route' => $this->safeText($row->route, self::TEXT_CHARS),
                'method' => $this->safeText($row->method, 8),
                'status' => $row->status === null ? null : (int) $row->status,
                'channel' => $this->safeText($row->channel, 16),
                'user_type' => $this->safeText($row->user_type, 16),
                'platform' => $this->safeText($row->platform, 16),
                'occurred_at' => $this->displayStamp($row->created_at),
            ];
        }

        return array_merge($empty, [
            'state' => $listed === [] ? 'no_data' : 'ok',
            'note' => $listed === []
                ? 'The error store is being written to, and no reported exception in this window carried a 401 or 403 status.'
                : null,
            'rows' => $listed,
            'truncated' => $rows->count() > self::MAX_ERROR_ROWS,
        ]);
    }

    /**
     * Recorded events that bear on security: an alert firing, and a change to monitoring's own
     * configuration.
     *
     * monitoring_events has eight defined types and not one of them is security or authentication
     * — see EventLog::TYPES — so this block is narrow by construction and says which two it reads
     * rather than presenting itself as a security event log.
     *
     * @return array<string, mixed>
     */
    private function signals(string $range): array
    {
        $empty = [
            'state' => 'no_data',
            'note' => null,
            'remedy' => null,
            'source' => self::EVENTS,
            'types' => self::SIGNAL_EVENT_TYPES,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_EVENT_ROWS,
        ];

        try {
            $rows = $this->reader->connection()->table(self::EVENTS)
                ->where('occurred_at', '>=', $this->reader->since($range))
                ->whereIn('type', self::SIGNAL_EVENT_TYPES)
                ->orderByDesc('occurred_at')
                ->limit(self::MAX_EVENT_ROWS + 1)
                ->get(['type', 'key', 'severity', 'title', 'description', 'occurred_at']);
        } catch (\Throwable $exception) {
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->fault('signals', $exception),
            ]);
        }

        $listed = [];
        foreach ($rows->take(self::MAX_EVENT_ROWS) as $row) {
            $severity = $this->safeText($row->severity, 12);

            $listed[] = [
                'type' => $this->safeText($row->type, 24),
                'key' => $this->safeText($row->key, 96),
                'severity' => $severity,
                // The severity column is free text at the database level. Translating whatever it
                // happens to hold would mint a language key per stored value, so the view is told
                // whether this one is a value monitoring itself writes.
                'severity_known' => in_array($severity, [EventLog::INFO, EventLog::SUCCESS, EventLog::WARNING, EventLog::CRITICAL], true),
                'title' => $this->safeText($row->title, self::TEXT_CHARS),
                'description' => $this->safeText($row->description, self::TEXT_CHARS),
                'occurred_at' => $this->displayStamp($row->occurred_at),
            ];
        }

        return array_merge($empty, [
            'state' => $listed === [] ? 'no_data' : 'ok',
            'note' => $listed === []
                ? 'No alert fired and no monitoring setting changed inside this window. This axis has no security or authentication event type at all, so an intrusion would not appear here even if one had been detected.'
                : null,
            'remedy' => $listed === []
                ? 'Recording security events on this axis needs a new type written through `App\Services\Monitoring\EventLog` from wherever the event is detected; today it carries deploys, scheduler runs, backups, incidents, alerts, config changes, checks and annotations.'
                : null,
            'rows' => $listed,
            'truncated' => $rows->count() > self::MAX_EVENT_ROWS,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Headline

    /**
     * The single values above the tables — each one a reading, or a stated absence.
     *
     * @param  array<string, mixed>  $refusals
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $sources
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $activity
     * @return array<string, Metric>
     */
    private function headline(array $refusals, array $credentials, array $sources, array $volume, array $activity): array
    {
        $readings = [
            'authentication_refusals' => $this->countMetric($refusals, $refusals['total'], self::RECORDER, carryNote: true, fault: 'refusals'),
            'unauthorized_401' => $this->countMetric($refusals, $this->statusCount($refusals, 401), self::RECORDER, fault: 'refusals'),
            'forbidden_403' => $this->countMetric($refusals, $this->statusCount($refusals, 403), self::RECORDER, fault: 'refusals'),
            'rejected_credentials' => $this->credentialMetric($credentials),
            'rate_limited_requests' => $this->countMetric(
                $volume,
                $volume['throttled'],
                self::RECORDER,
                absentNote: 'More paths were rate limited than this page lists, so the total behind them is withheld rather than under-counted.',
                fault: 'volume',
            ),
            'distinct_refused_sources' => $this->countMetric($sources, $sources['distinct'], (string) $sources['source'], fault: 'sources'),
            'privileged_actions_recorded' => $this->countMetric($activity, $activity['total'], self::AUDIT, carryNote: true, fault: 'audit'),
        ];

        $headline = [];
        foreach (self::HEADLINE as $name) {
            $metric = $readings[$name] ?? null;
            if (!$metric instanceof Metric) {
                continue;
            }
            // An unavailable reading goes in whole — its state and remedy are the content. Only a
            // successful reading that is not a single value has nowhere honest to be drawn.
            if ($metric->isOk() && !is_scalar($metric->value)) {
                continue;
            }

            $headline[$name] = $metric;
        }

        return $headline;
    }

    /**
     * A count carried up from a block, keeping the block's own reason when there is no count.
     *
     * @param  array<string, mixed>  $block
     * @param  bool  $carryNote  put the block's reason on the card — for the reading that IS the
     *                           block's headline number, never for a split derived from it, so one
     *                           explanation is not printed three times across one row of cards
     * @param  string|null  $absentNote  why a block that read successfully still has no number
     * @param  string|null  $fault  the key this block records a caught failure under
     */
    private function countMetric(
        array $block,
        mixed $value,
        string $source,
        bool $carryNote = false,
        ?string $absentNote = null,
        ?string $fault = null,
    ): Metric {
        $state = (string) ($block['state'] ?? 'no_data');
        $note = is_string($block['note'] ?? null) ? $block['note'] : null;
        $remedy = is_string($block['remedy'] ?? null) ? $block['remedy'] : null;

        // This card's reason is the banner's, word for word, and the banner is already on screen.
        if (!$carryNote && $note !== null && $note === $this->hoistedNote) {
            $note = null;
            $remedy = $remedy === $this->hoistedRemedy ? null : $remedy;
        }

        if ($state === 'ok') {
            // A read that worked and still has no number is its own case: withheld because the
            // list it would total was cut short, which is not the same as never measured.
            return is_numeric($value)
                ? Metric::of((int) $value, $source, note: $carryNote ? $note : null)
                : Metric::noData($source, $absentNote ?? $note);
        }

        return match ($state) {
            'not_configured' => Metric::notConfigured($source, $remedy ?? 'This reading has no source configured on this deployment.', $note),
            'failed' => $this->failedMetric($source, $fault, $note),
            default => Metric::noData($source, $note),
        };
    }

    /**
     * A FAILED reading, carrying the redacted sentence its block published.
     *
     * The collection read is the fallback because the three telemetry blocks inherit its state
     * wholesale: when the request log itself is unreadable they never run their own query, so the
     * only throwable that exists is the one the hoisted block caught. Falling through to NO_DATA
     * would then label a failed read as an empty one, which is the exact swap this panel refuses.
     */
    private function failedMetric(string $source, ?string $fault, ?string $note): Metric
    {
        foreach ([$fault, 'collection'] as $key) {
            $exception = $key === null ? null : ($this->faults[$key] ?? null);

            if ($exception instanceof \Throwable) {
                return Metric::failed($source, $exception);
            }
        }

        return Metric::noData($source, $note);
    }

    /** @param array<string, mixed> $refusals */
    private function statusCount(array $refusals, int $status): ?int
    {
        foreach ($refusals['by_status'] ?? [] as $row) {
            if ((int) ($row['status'] ?? 0) === $status) {
                return (int) $row['hits'];
            }
        }

        return null;
    }

    /**
     * The rejected-credential card.
     *
     * Its whole job is to be honestly empty on a deployment that records no sign-in failure. A
     * zero here would be read as "nobody has tried", when what it means is "nobody has looked".
     *
     * @param  array<string, mixed>  $credentials
     */
    private function credentialMetric(array $credentials): Metric
    {
        $source = (string) $credentials['source'];

        $note = is_string($credentials['note']) ? $credentials['note'] : null;

        return match ($credentials['state']) {
            'ok' => Metric::of((int) $credentials['count'], $source, note: 'Recorded sign-in failures, counted separately from HTTP refusals.'),
            'not_configured' => Metric::notConfigured($source, (string) $credentials['remedy'], (string) $credentials['note']),
            // Two reads can fail this card: its own, or the vocabulary read that decides whether
            // the card exists. Whichever actually threw is the one the operator needs named.
            'failed' => $this->failedMetric($source, isset($this->faults['credentials']) ? 'credentials' : 'audit', $note),
            default => Metric::noData($source, $note),
        };
    }

    // -------------------------------------------------------------------------------------------

    /**
     * What this page will not put on a screen, and why.
     *
     * Published rather than left implicit so the view can state it once. An operator who cannot see
     * a source address here should be told that the omission is deliberate; otherwise the obvious
     * next step is to go and find it somewhere the redaction rules do not reach.
     *
     * @return array<string, mixed>
     */
    private function privacy(): array
    {
        return [
            'mask_ip' => (bool) config('monitoring.privacy.mask_ip', true),
            'source_is_hash' => true,
            'salt_rotates_daily' => true,
            'network_masked' => true,
            'audit_ip_stored_not_shown' => true,
            'never_rendered' => ['ip_address', 'user_agent', 'password', 'token', 'session_id', 'authorization_header', 'audit_before_after_values'],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Shared reading helpers

    /**
     * The same instant as Clock, written the way the shop's own tables wrote theirs.
     *
     * telemetry_requests and audit_logs are stamped with Carbon::now(), which resolves in the PHP
     * process timezone — not UTC, and on this deployment not app.timezone either. Monitoring's own
     * tables are UTC. Comparing a UTC boundary against a column written in local wall time is the
     * exact failure Clock exists to prevent: here it is a several-hour error, which returns an
     * empty security page while the shop is being probed.
     */
    private function shopWindowStart(int $minutes): Carbon
    {
        return Clock::minutesAgo($minutes)->setTimezone(date_default_timezone_get());
    }

    /** A shop-written stamp, read in the zone it was written in and shown in the dashboard's. */
    private function shopStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Carbon::parse($stored, date_default_timezone_get())
                ->setTimezone(Clock::displayTimezone())
                ->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the request really
            // happened, and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    /** A monitoring-written (UTC) stamp, in the timezone the dashboard renders in. */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    /**
     * A column value safe to put in a payload that is json_encode()d.
     *
     * Redacted, because a path, a title or an actor name is one of the places a token turns up;
     * bounded, because the JSON endpoint has no partial-output tolerance; and encoding-checked,
     * because storage does not guarantee valid UTF-8 and one bad byte fails the whole response.
     */
    private function safeText(mixed $value, int $limit): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = $this->redactor->text($text);

        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return mb_substr($text, 0, $limit);
    }

    /**
     * Record a caught failure and return the sentence a block publishes for it.
     *
     * The message is redacted here and nowhere else: a driver error carries the statement that
     * failed, and a statement is one of the more reliable places in an application to find
     * something that must not be written to a screen.
     */
    private function fault(string $key, \Throwable $exception): string
    {
        $note = class_basename($exception) . ': ' . $this->redactor->text($exception->getMessage());

        // Wrapped rather than kept: the original's message is unredacted, and a Metric built from
        // it would put the raw text on a card. The wrapper carries the safe sentence, which names
        // the real exception class inside it.
        $this->faults[$key] = new \RuntimeException($note);

        return $note;
    }
}
