<?php

namespace App\Http\Controllers\Admin\Telemetry;

use App\Http\Controllers\BaseController;
use App\Jobs\RebuildProductSearchIndex;
use App\Services\AuditLogger;
use App\Services\Monitoring\FailedJobs;
use App\Services\Monitoring\MonitoringNavigation;
use App\Services\Monitoring\MonitoringPermissionService;
use App\Services\Monitoring\Panels\PanelRegistry;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The operations centre.
 *
 * One controller for every section, because they all do the same three things: check the section
 * exists, check this admin may open it, then hand its panel's data to a view. The differences
 * between sections live in their panels, not here — a new section is a panel class and an entry in
 * MonitoringNavigation, with nothing to add in the router or the controller.
 *
 * Each section also answers as JSON on the same URL, which is what the page's own refresh uses.
 * That keeps one code path producing the numbers rather than a view version and an API version
 * that drift apart.
 */
class MonitoringController extends BaseController
{
    public function __construct(
        private readonly MonitoringPermissionService $permissions,
        private readonly PanelRegistry $panels,
    ) {
    }

    /**
     * Every section renders through here.
     *
     * The signature is the project's ControllerInterface one — `?Request`, `?string $type` — rather
     * than the more natural `string $section`, because BaseController declares it and PHP checks
     * compatibility at class load: a nicer parameter name here is a fatal error on every admin page.
     */
    public function index(?Request $request = null, ?string $type = null): View|JsonResponse|RedirectResponse
    {
        $request ??= request();
        $section = $type ?: 'overview';

        if (!MonitoringNavigation::exists($section)) {
            return redirect()->route('admin.monitoring.index');
        }

        $capability = $this->permissions->capabilityForTab($section);
        if (!$this->permissions->can($capability)) {
            ToastMagic::error(translate('access_Denied') . '!');

            return redirect()->route('admin.monitoring.section', ['section' => 'overview']);
        }

        $range = $this->range($request);
        $panel = $this->panels->data($section, $range, $request);

        if ($request->wantsJson() || $request->boolean('json')) {
            return response()->json([
                'section' => $section,
                'range' => $range,
                'data' => $panel,
            ]);
        }

        return view('admin-views.monitoring.index', [
            'section' => $section,
            'meta' => MonitoringNavigation::sections()[$section],
            'groups' => MonitoringNavigation::visibleGroups($this->permissions, $section),
            'groupLabels' => MonitoringNavigation::groups(),
            'range' => $range,
            'ranges' => $this->availableRanges(),
            'panel' => $panel,
            'permissions' => $this->permissions,
            // Rendered server-side as well as polled: the header must state the truth on first
            // paint rather than showing placeholder chips until the first poll lands.
            'self' => $this->panels->selfHealth(),
            // The status header belongs to the SHELL, not to the overview panel. Reading it out of
            // $panel meant every other section rendered "0/0 measured signals" and a blank score —
            // a fabricated zero on thirty-two pages, which is the one thing this system must not do.
            'pulse' => $this->panels->pulse(),
        ]);
    }

    /**
     * Put a failed job back on its queue, or drop it.
     *
     * One of the two writes in an area that is otherwise read-only, and the reason for the
     * exception is that the alternative is worse: a failed order confirmation is visible on this
     * page and repairable only from a shell, so the person who can see the problem is never the
     * person who can fix it.
     *
     * Reading the queue is infrastructure; changing what it will do is the write capability, which
     * this area already calls settings. Both are checked, and both are audited.
     */
    public function failedJob(Request $request, string $action, string $uuid): RedirectResponse
    {
        if (!$this->permissions->canEditSettings()) {
            ToastMagic::error(translate('access_Denied') . '!');

            return redirect()->route('admin.monitoring.section', ['section' => 'queues']);
        }

        $result = $action === 'retry'
            ? app(FailedJobs::class)->retry($uuid)
            : app(FailedJobs::class)->forget($uuid);

        $result['ok']
            ? ToastMagic::success(translate($action === 'retry' ? 'the_job_was_queued_again' : 'the_failed_job_was_discarded'))
            : ToastMagic::error(translate($result['reason'] ?? 'that_job_is_no_longer_in_the_failed_list'));

        return redirect()->route('admin.monitoring.section', ['section' => 'queues']);
    }

    /**
     * Ask for the search index to be rebuilt.
     *
     * The other write this page allows, and the reason it exists: a bulk import writes product rows
     * without going through the model save path the index observer listens on, so a large import
     * leaves search answering from a stale index and, until now, only shell access could fix it.
     *
     * Queued rather than run here — a rebuild walks the whole catalogue in chunks and outlives a
     * request — and gated on the settings capability rather than on merely being able to read the
     * page, because it is work the operator is asking the server to do.
     */
    public function rebuildSearchIndex(Request $request, AuditLogger $audit): RedirectResponse
    {
        if (!$this->permissions->canEditSettings()) {
            ToastMagic::error(translate('access_Denied') . '!');

            return redirect()->route('admin.monitoring.section', ['section' => 'search']);
        }

        $actor = auth('admin')->user();
        RebuildProductSearchIndex::dispatch($actor?->name ?? $actor?->email);

        $audit->record(
            action: 'platform.search_index_rebuild_requested',
            context: ['queue' => config('queue.default')],
        );

        // Said plainly because it is true: the request is queued, and a shop with no queue worker
        // running will see nothing happen. The page names the command for that case.
        ToastMagic::success(translate('the_rebuild_has_been_queued'));

        return redirect()->route('admin.monitoring.section', ['section' => 'search']);
    }

    /**
     * The lightweight feed the page polls.
     *
     * Deliberately separate from the full section payload: the header strip refreshes every few
     * seconds and must stay cheap, while a section's tables are only rebuilt when the operator
     * asks for them.
     */
    public function pulse(): JsonResponse
    {
        if (!$this->permissions->canView()) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return response()->json($this->panels->pulse());
    }

    /**
     * The window every panel reads.
     *
     * Validated against the allowed set rather than trusted: this value reaches a query, and an
     * arbitrary one would let anyone who can open the page ask for an unbounded scan.
     */
    private function range(Request $request): string
    {
        // `?range[]=x` hands the request an ARRAY, and casting one to string is a PHP warning the
        // error handler turns into a throw — array_key_exists() would fault on it too. This read
        // happens before any panel's catch, so an unusable window has to fall back to the default
        // here or it takes the whole console down instead of degrading one card.
        $value = $request->query('range', '1h');
        $requested = is_string($value) ? trim($value) : '1h';

        return array_key_exists($requested, $this->availableRanges()) ? $requested : '1h';
    }

    /**
     * @return array<string, array{label: string, minutes: int, resolution: string}>
     */
    private function availableRanges(): array
    {
        // Resolution is chosen to keep every chart to a few hundred points: a 90-day chart drawn
        // from minute buckets would be 130,000 rows for a line nobody can read anyway.
        return [
            'live' => ['label' => 'live', 'minutes' => 5, 'resolution' => 'minute'],
            '15m' => ['label' => '15_minutes', 'minutes' => 15, 'resolution' => 'minute'],
            '1h' => ['label' => '1_hour', 'minutes' => 60, 'resolution' => 'minute'],
            '6h' => ['label' => '6_hours', 'minutes' => 360, 'resolution' => 'minute'],
            '24h' => ['label' => '24_hours', 'minutes' => 1440, 'resolution' => 'hour'],
            '7d' => ['label' => '7_days', 'minutes' => 10080, 'resolution' => 'hour'],
            '30d' => ['label' => '30_days', 'minutes' => 43200, 'resolution' => 'day'],
            '90d' => ['label' => '90_days', 'minutes' => 129600, 'resolution' => 'day'],
        ];
    }
}
