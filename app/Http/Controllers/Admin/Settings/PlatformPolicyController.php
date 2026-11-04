<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The screen behind PolicyRegistry.
 *
 * One page for every rule the platform applies to itself, because the alternative the audit found —
 * a number per class, changeable only by deploying — is what let three definitions of "low stock"
 * and two minimum password lengths coexist without anyone noticing.
 *
 * The form, its validation and its help text are all generated from the declarations, so a rule
 * added to the registry appears here correctly bounded without a line being written in this class.
 */
class PlatformPolicyController extends BaseController
{
    public function __construct(private readonly Policy $policy)
    {
    }

    public function index(?Request $request = null, ?string $type = null): View
    {
        $group = $type && isset(PolicyRegistry::GROUPS[$type]) ? $type : array_key_first(PolicyRegistry::GROUPS);

        return view('admin-views.settings.policies', [
            'groups' => PolicyRegistry::GROUPS,
            'group' => $group,
            'meta' => PolicyRegistry::GROUPS[$group],
            'values' => $this->policy->all($group),
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        if (!isset(PolicyRegistry::GROUPS[$group])) {
            return redirect()->route('admin.settings.policies.index');
        }

        $validated = $request->validate($this->policy->rules($group));
        $changes = $this->policy->save($validated);

        ToastMagic::success(translate($changes === [] ? 'nothing_changed' : 'the_policy_was_updated'));

        return back();
    }
}
