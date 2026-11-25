<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\AuditLog;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The audit center (Phase 3 — spec item 84).
 *
 * Read-only by construction: this controller only queries. The log is append-only, and there is no
 * edit or delete path from the UI, because an audit trail that can be altered from the same screen
 * that reads it is not an audit trail.
 *
 * Server-side paginated and filtered — the spec is explicit that large tables must never be loaded
 * whole into the browser (items 120, 119).
 */
class AuditLogController extends BaseController
{
    public function index(Request|null $request, ?string $type = null): View
    {
        $entries = collect();
        $modules = [];

        if (Schema::hasTable('audit_logs')) {
            $entries = $this->ofTheEmployeesCountries(AuditLog::query())
                ->when($request?->get('module'), fn ($q, $module) => $q->inModule($module))
                ->when($request?->get('actor_type'), fn ($q, $actor) => $q->where('actor_type', $actor))
                ->when($request?->get('action'), fn ($q, $action) => $q->where('action', $action))
                ->when($request?->get('search'), function ($q, $search) {
                    $q->where(fn ($inner) => $inner
                        ->where('actor_name', 'like', "%{$search}%")
                        ->orWhere('subject_id', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%"));
                })
                ->latest('id')
                ->paginate(30)
                ->appends($request?->all() ?? []);

            // The module list is derived from the actions actually recorded, so it never lists an
            // area that has produced no events. Split in PHP rather than with the database's string
            // functions: SUBSTRING_INDEX is MariaDB-only and would break the SQLite test suite — the
            // same portability trap CONCAT set in the search work.
            $modules = $this->ofTheEmployeesCountries(AuditLog::query())
                ->select('action')->distinct()->pluck('action')
                ->map(fn ($action) => str_contains((string) $action, '.') ? explode('.', $action)[0] : $action)
                ->unique()->filter()->values()->all();
        }

        return view('admin-views.marketplace.audit-log', [
            'entries' => $entries,
            'modules' => $modules,
            'filters' => [
                'module' => $request?->get('module'),
                'actor_type' => $request?->get('actor_type'),
                'search' => $request?->get('search'),
            ],
            'restrictedToCountries' => app(StaffCountryScope::class)->boundCountries(),
        ]);
    }

    /**
     * The lines a restricted employee may read: the ones that NAME one of their countries (G1-08).
     *
     * A line that names no market is not treated as the default storefront's here, which is where this parts company with every other
     * market-owning table of this platform -- and deliberately. `AuditLogger` files a panel or console action with NO market, because an
     * administrator sees every country and attributing their action to Saudi Arabia would put a fact nobody recorded into an audit trail.
     * A reading that then handed those lines to whoever holds the default market would be inventing that same fact at the other end, and
     * about actions that may well have concerned another country.
     *
     * The consequence is worth saying out loud: every line written before `2026_12_29_100000` names no market, so a restricted employee
     * sees none of them. That is the honest answer -- the trail does not say they are theirs -- and the safe one.
     *
     * An employee who is not restricted, and the master admin, read everything, as they always have.
     *
     * @param Builder<AuditLog> $lines
     * @return Builder<AuditLog>
     */
    private function ofTheEmployeesCountries(Builder $lines): Builder
    {
        $countries = app(StaffCountryScope::class)->boundCountries();

        if ($countries === null) {
            return $lines;
        }

        return $countries === []
            ? $lines->whereRaw('1 = 0')
            : $lines->whereIn('storefront_country', $countries);
    }
}
