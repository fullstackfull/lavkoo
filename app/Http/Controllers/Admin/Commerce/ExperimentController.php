<?php

namespace App\Http\Controllers\Admin\Commerce;

use App\Http\Controllers\BaseController;
use App\Models\ExperienceExperiment;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\AuditLogger;
use App\Services\Commerce\ExperimentRules;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemePermissionService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Experiments (Phase 3.5): one section, N variants, stable buckets, control by default.
 */
class ExperimentController extends BaseController
{
    public function __construct(
        private readonly ExperimentRules $rules,
        private readonly SectionRegistry $registry,
        private readonly ThemePermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(?Request $request, ?string $type = null): View
    {
        $ready = $this->ready();

        return view('admin-views.commerce.experiments', [
            'ready'       => $ready,
            'enabled'     => (bool) config('commerce.enabled', true),
            // Who saw which variant: the number that makes an experiment an experiment rather
            // than a coin flip nobody watched.
            'reach'       => app(\App\Services\Commerce\ExperimentReach::class),
            'experiments' => $ready ? ExperienceExperiment::query()->orderByDesc('id')->get() : collect(),
            // The sections an experiment can target: what is LIVE, because control means "as
            // published" and a draft section has no shoppers to measure.
            'sections'    => $this->publishedSections(),
            'editable'    => $this->permissions->canEdit(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'section_uuid' => ['required', 'string', 'max:60'],
        ]);

        $section = $this->rules->publishedSection(
            (string) $request->input('section_uuid'),
            $this->publishedVersionId(),
        );

        if ($section === null) {
            ToastMagic::error(translate('that_section_is_not_on_the_published_page') . '!');
            return back();
        }

        $checked = $this->rules->validateVariants(
            json_decode((string) $request->input('variants', '[]'), true),
            $section->type,
        );

        if ($checked['errors'] !== [] || $checked['variants'] === []) {
            ToastMagic::error(translate('these_variants_cannot_be_saved')
                . ($checked['errors'] !== [] ? ': ' . implode(', ', $checked['errors']) : ''));
            return back();
        }

        $key = ExperienceExperiment::keyFor($request->input('name'));

        if ($key === '' || ExperienceExperiment::query()->where('key', $key)->exists()) {
            ToastMagic::error(translate('an_experiment_with_this_name_already_exists') . '!');
            return back();
        }

        $experiment = ExperienceExperiment::create([
            'name'         => trim($request->input('name')),
            'key'          => $key,
            'status'       => ExperienceExperiment::STATUS_DRAFT,
            'page'         => (string) ($section->page ?: 'home'),
            'section_uuid' => $section->uuid,
            'variants'     => $checked['variants'],
        ]);

        $this->audit->record(
            action: 'commerce.experiment_created',
            subject: $experiment,
            after: ['name' => $experiment->name, 'section_uuid' => $experiment->section_uuid,
                    'variants' => array_map(fn (array $variant) => $variant['key'] . ':' . $variant['weight'],
                        $checked['variants'])],
        );

        ToastMagic::success(translate('experiment_created_as_a_draft'));

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $experiment = ExperienceExperiment::find($request['id']);

        if ($experiment === null) {
            ToastMagic::error(translate('experiment_not_found') . '!');
            return back();
        }

        $before = ['status' => $experiment->status];

        if ($request->has('status')) {
            $status = (string) $request->input('status');

            if (!in_array($status, ExperienceExperiment::STATUSES, true)) {
                ToastMagic::error(translate('that_is_not_an_experiment_status') . '!');
                return back();
            }

            // A stopped experiment stays stopped: restarting one would re-bucket a population
            // that already saw its variants and poison whatever was measured.
            if ($experiment->status === ExperienceExperiment::STATUS_STOPPED
                && $status === ExperienceExperiment::STATUS_RUNNING) {
                ToastMagic::error(translate('a_stopped_experiment_cannot_restart_make_a_new_one') . '!');
                return back();
            }

            // Starting an experiment changes what shoppers are served, which is publishing —
            // the capability the module grant deliberately does NOT include (ThemePermissionService).
            if ($status === ExperienceExperiment::STATUS_RUNNING && !$this->permissions->canPublish()) {
                ToastMagic::error(translate('starting_an_experiment_changes_the_live_storefront_and_needs_the_publish_permission') . '!');
                return back();
            }

            $experiment->status = $status;
        }

        $experiment->save();
        $this->flushServing($experiment->page);

        $this->audit->record(
            action: 'commerce.experiment_updated',
            subject: $experiment,
            before: $before,
            after: ['status' => $experiment->status],
        );

        ToastMagic::success(translate('experiment_updated_successfully'));

        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $experiment = ExperienceExperiment::find($request['id']);

        if ($experiment === null) {
            ToastMagic::error(translate('experiment_not_found') . '!');
            return back();
        }

        $experiment->delete();
        $this->flushServing($experiment->page);

        $this->audit->record(
            action: 'commerce.experiment_deleted',
            subject: $experiment,
            before: ['name' => $experiment->name, 'status' => $experiment->status],
        );

        ToastMagic::success(translate('experiment_deleted_everyone_sees_control'));

        return back();
    }

    // ---------------------------------------------------------------------------------------

    /** @return array<int, array{uuid: string, label: string, type: string}> */
    private function publishedSections(): array
    {
        $versionId = $this->publishedVersionId();

        if ($versionId === null) {
            return [];
        }

        try {
            return ThemeSection::query()
                ->where('theme_version_id', $versionId)
                ->whereNotNull('uuid')
                ->orderBy('page')
                ->orderBy('sort_order')
                ->get(['uuid', 'type', 'page', 'sort_order'])
                ->map(fn (ThemeSection $section) => [
                    'uuid'  => $section->uuid,
                    'type'  => $section->type,
                    'label' => $section->page . ' · #' . $section->sort_order . ' · '
                        . str_replace('_', ' ', $section->type),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function publishedVersionId(): ?int
    {
        try {
            $theme = Theme::query()->where('is_active', true)->first();

            return $theme === null ? null : ThemeVersion::query()
                ->where('theme_id', $theme->id)
                ->where('status', ThemeVersion::STATUS_PUBLISHED)
                ->value('id');
        } catch (\Throwable) {
            return null;
        }
    }

    /** A lifecycle change must reach shoppers now, not when the 60s list cache expires. */
    private function flushServing(string $page): void
    {
        \Illuminate\Support\Facades\Cache::forget('commerce_experiments_' . $page);
        app(\App\Services\Theme\ThemeDelivery::class)->flush();
    }

    private function ready(): bool
    {
        try {
            return Schema::hasTable('experience_experiments');
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
