<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
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
            $entries = AuditLog::query()
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
            $modules = AuditLog::query()
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
        ]);
    }
}
