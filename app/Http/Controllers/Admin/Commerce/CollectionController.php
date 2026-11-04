<?php

namespace App\Http\Controllers\Admin\Commerce;

use App\Http\Controllers\BaseController;
use App\Models\ProductCollection;
use App\Models\ProductMetric;
use App\Services\AuditLogger;
use App\Services\Commerce\CollectionResolver;
use App\Services\Commerce\CollectionRuleRegistry;
use App\Services\Commerce\MerchandisingRules;
use App\Services\Theme\ThemePermissionService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dynamic Collections (Phase 3.1): Commerce Experience's first screen.
 *
 * A collection is configuration, not content — it changes what sections MAY show, and only the
 * sections a merchant explicitly sources from it (§7: nothing existing opts in by itself). So
 * the writes here are guarded like theme edits, audited like theme edits, and refuse invalid
 * rules out loud rather than saving a cleaned-up approximation of them.
 */
class CollectionController extends BaseController
{
    public function __construct(
        private readonly CollectionRuleRegistry $rules,
        private readonly MerchandisingRules $merchandising,
        private readonly CollectionResolver $resolver,
        private readonly ThemePermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(?Request $request, ?string $type = null): View
    {
        $ready = $this->resolver->ready();

        return view('admin-views.commerce.collections', [
            'ready'       => $ready,
            'enabled'     => (bool) config('commerce.enabled', true),
            'collections' => $ready
                ? ProductCollection::query()->orderBy('name')->get()
                : collect(),
            'fields'      => CollectionRuleRegistry::FIELDS,
            'sorts'       => CollectionRuleRegistry::SORTS,
            'boostKinds'  => MerchandisingRules::BOOST_KINDS,
            'fallbackSources' => MerchandisingRules::FALLBACK_SOURCES,
            'editable'    => $this->permissions->canEdit(),
            'metricsAge'  => $ready ? ProductMetric::query()->max('computed_at') : null,
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

        $slug = ProductCollection::slugFor($request->input('name'));

        if ($slug === '' || ProductCollection::query()->where('slug', $slug)->exists()) {
            ToastMagic::error(translate('a_collection_with_this_name_already_exists') . '!');
            return back();
        }

        $merch = $this->merchandising->validate(
            json_decode((string) $request->input('merchandising', 'null'), true),
        );

        if ($merch['errors'] !== []) {
            ToastMagic::error(translate('this_merchandising_cannot_be_saved') . ': ' . implode(', ', $merch['errors']));
            return back();
        }

        $collection = ProductCollection::create([
            'name'          => trim($request->input('name')),
            'slug'          => $slug,
            'status'        => true,
            'rules'         => $checked['rules'],
            'sort_by'       => $this->rules->isSort($request->input('sort_by')) ? $request->input('sort_by') : 'sales_30d',
            'merchandising' => $merch['config'],
        ]);

        $this->audit->record(
            action: 'commerce.collection_created',
            subject: $collection,
            after: ['name' => $collection->name, 'rules' => $collection->rules, 'sort_by' => $collection->sort_by],
        );

        ToastMagic::success(translate('collection_created_successfully'));

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $collection = ProductCollection::find($request['id']);

        if ($collection === null) {
            ToastMagic::error(translate('collection_not_found') . '!');
            return back();
        }

        $before = ['name' => $collection->name, 'status' => $collection->status,
                   'rules' => $collection->rules, 'sort_by' => $collection->sort_by];

        if ($request->has('name')) {
            $request->validate(['name' => ['required', 'string', 'max:120']]);
            $collection->name = trim($request->input('name'));
        }
        if ($request->has('status')) {
            $collection->status = $request->boolean('status');
        }
        if ($request->has('sort_by') && $this->rules->isSort($request->input('sort_by'))) {
            $collection->sort_by = $request->input('sort_by');
        }
        if ($request->has('rules')) {
            $checked = $this->rules->validate(json_decode((string) $request->input('rules', '[]'), true));

            if ($checked['errors'] !== []) {
                ToastMagic::error(translate('these_rules_cannot_be_saved') . ': ' . implode(', ', $checked['errors']));
                return back();
            }

            $collection->rules = $checked['rules'];
        }

        if ($request->has('merchandising')) {
            $merch = $this->merchandising->validate(
                json_decode((string) $request->input('merchandising', 'null'), true),
                $collection->id,
            );

            if ($merch['errors'] !== []) {
                ToastMagic::error(translate('this_merchandising_cannot_be_saved') . ': ' . implode(', ', $merch['errors']));
                return back();
            }

            $collection->merchandising = $merch['config'];
        }

        $collection->save();

        $this->audit->record(
            action: 'commerce.collection_updated',
            subject: $collection,
            before: $before,
            after: ['name' => $collection->name, 'status' => $collection->status,
                    'rules' => $collection->rules, 'sort_by' => $collection->sort_by,
                    'merchandising' => $collection->merchandising],
        );

        ToastMagic::success(translate('collection_updated_successfully'));

        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $collection = ProductCollection::find($request['id']);

        if ($collection === null) {
            ToastMagic::error(translate('collection_not_found') . '!');
            return back();
        }

        // Sections referencing it keep their settings and simply render nothing until re-pointed;
        // the publish gate names the broken reference. Deleting configuration never edits pages.
        $collection->delete();

        $this->audit->record(
            action: 'commerce.collection_deleted',
            subject: $collection,
            before: ['name' => $collection->name, 'rules' => $collection->rules],
        );

        ToastMagic::success(translate('collection_deleted_successfully'));

        return back();
    }

    /**
     * What this collection resolves to right now — the merchant's "why is this showing" for
     * collections (§59), answered with the exact resolver the storefront uses.
     */
    public function preview(Request $request): JsonResponse
    {
        $products = $this->resolver->resolve((int) $request['id'], 12);

        return response()->json([
            'status'   => 'success',
            'products' => $products->map(fn ($product) => [
                'id'    => $product->id,
                'name'  => $product->name,
                'price' => (float) $product->unit_price,
            ])->all(),
        ]);
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
