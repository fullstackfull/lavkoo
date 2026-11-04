<?php

namespace App\Http\Controllers\Admin\Commerce;

use App\Http\Controllers\BaseController;
use App\Models\CustomerSegment;
use App\Services\AuditLogger;
use App\Services\Commerce\SegmentRules;
use App\Services\Theme\ThemePermissionService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Customer segments (Phase 3.4): rule-based, deterministic, computed — never a stored list.
 */
class SegmentController extends BaseController
{
    public function __construct(
        private readonly SegmentRules $rules,
        private readonly ThemePermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(?Request $request, ?string $type = null): View
    {
        $ready = $this->ready();

        return view('admin-views.commerce.segments', [
            'ready'     => $ready,
            'enabled'   => (bool) config('commerce.enabled', true),
            'segments'  => $ready ? CustomerSegment::query()->orderBy('name')->get() : collect(),
            'fields'    => SegmentRules::FIELDS,
            'operators' => SegmentRules::OPERATORS,
            'editable'  => $this->permissions->canEdit(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $request->validate(['name' => ['required', 'string', 'max:120']]);

        $checked = $this->rules->validate(json_decode((string) $request->input('rules', '[]'), true));

        if ($checked['errors'] !== []) {
            ToastMagic::error(translate('these_rules_cannot_be_saved') . ': ' . implode(', ', $checked['errors']));
            return back();
        }

        if ($checked['rules'] === []) {
            // A segment with no rules matches nobody; saving one would put a dead token in the
            // builder's audience picker. Refuse out loud.
            ToastMagic::error(translate('a_segment_needs_at_least_one_rule') . '!');
            return back();
        }

        $key = CustomerSegment::keyFor($request->input('name'));

        if ($key === '' || CustomerSegment::query()->where('key', $key)->exists()) {
            ToastMagic::error(translate('a_segment_with_this_name_already_exists') . '!');
            return back();
        }

        // guest/customer are the built-in audience tokens; a segment wearing one of their names
        // would hijack every section already targeted at them.
        if (in_array($key, \App\Services\Theme\ViewerContext::AUDIENCES, true)) {
            ToastMagic::error(translate('this_name_belongs_to_a_built_in_audience') . '!');
            return back();
        }

        $segment = CustomerSegment::create([
            'name'   => trim($request->input('name')),
            'key'    => $key,
            'status' => true,
            'rules'  => $checked['rules'],
        ]);

        $this->audit->record(
            action: 'commerce.segment_created',
            subject: $segment,
            after: ['name' => $segment->name, 'key' => $segment->key, 'rules' => $segment->rules],
        );

        \App\Services\Commerce\SegmentResolver::forgetLists();

        ToastMagic::success(translate('segment_created_successfully'));

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $segment = CustomerSegment::find($request['id']);

        if ($segment === null) {
            ToastMagic::error(translate('segment_not_found') . '!');
            return back();
        }

        $before = ['name' => $segment->name, 'status' => $segment->status, 'rules' => $segment->rules];

        if ($request->has('name')) {
            $request->validate(['name' => ['required', 'string', 'max:120']]);
            // The key never changes with the name: sections target the key, and renaming a
            // segment must not silently untarget every section using it.
            $segment->name = trim($request->input('name'));
        }
        if ($request->has('status')) {
            $segment->status = $request->boolean('status');
        }
        if ($request->has('rules')) {
            $checked = $this->rules->validate(json_decode((string) $request->input('rules', '[]'), true));

            if ($checked['errors'] !== [] || $checked['rules'] === []) {
                ToastMagic::error(translate('these_rules_cannot_be_saved')
                    . ($checked['errors'] !== [] ? ': ' . implode(', ', $checked['errors']) : ''));
                return back();
            }

            $segment->rules = $checked['rules'];
        }

        $segment->save();

        $this->audit->record(
            action: 'commerce.segment_updated',
            subject: $segment,
            before: $before,
            after: ['name' => $segment->name, 'status' => $segment->status, 'rules' => $segment->rules],
        );

        \App\Services\Commerce\SegmentResolver::forgetLists();

        ToastMagic::success(translate('segment_updated_successfully'));

        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $segment = CustomerSegment::find($request['id']);

        if ($segment === null) {
            ToastMagic::error(translate('segment_not_found') . '!');
            return back();
        }

        $segment->delete();

        $this->audit->record(
            action: 'commerce.segment_deleted',
            subject: $segment,
            before: ['name' => $segment->name, 'key' => $segment->key],
        );

        \App\Services\Commerce\SegmentResolver::forgetLists();

        ToastMagic::success(translate('segment_deleted_sections_targeting_it_now_show_to_nobody_extra'));

        return back();
    }

    private function ready(): bool
    {
        try {
            return Schema::hasTable('customer_segments');
        } catch (\Throwable) {
            return false;
        }
    }

    private function guard(): bool
    {
        if (config('app.mode') === 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return false;
        }

        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return false;
        }

        return true;
    }
}
