<?php

namespace App\Http\Controllers\Admin\Commerce;

use App\Http\Controllers\BaseController;
use App\Models\ExperienceCampaign;
use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Services\AuditLogger;
use App\Services\Commerce\CampaignRules;
use App\Services\Theme\Channel;
use App\Services\Theme\ExperiencePageService;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ThemePermissionService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns (Phase 3.3): scheduled overlays that dress a page and hand it back untouched.
 *
 * The lifecycle is the merchant's; the window is the shopper's. Activating runs the conflict
 * check (§38) — two campaigns contesting one slot at equal priority in overlapping windows is a
 * coin flip waiting to be served, and it is refused here with both names on it.
 */
class CampaignController extends BaseController
{
    public function __construct(
        private readonly CampaignRules $rules,
        private readonly ExperiencePageService $pages,
        private readonly ThemePermissionService $permissions,
        private readonly AuditLogger $audit,
        private readonly ThemeDelivery $delivery,
        private readonly StorefrontThemeRenderer $renderer,
    ) {
    }

    public function index(?Request $request, ?string $type = null): View
    {
        $ready = $this->ready();
        $theme = Theme::query()->where('is_active', true)->first();

        return view('admin-views.commerce.campaigns', [
            'ready'     => $ready,
            'enabled'   => (bool) config('commerce.enabled', true),
            // How many shoppers each campaign's overlay actually reached (30d): the number that
            // answers "did the campaign work" on the same screen that runs it.
            'reach'     => app(\App\Services\Theme\SectionReach::class),
            'campaigns' => $ready
                ? ExperienceCampaign::query()->orderByDesc('id')->get()
                : collect(),
            'pages'     => $theme
                ? array_column($this->pages->forChannel($theme->id, Channel::CUSTOMER_APP), 'slug')
                : ExperiencePage::SYSTEM_SLUGS,
            'slots'     => CampaignRules::SLOTS,
            'types'     => CampaignRules::OVERRIDE_TYPES,
            'editable'  => $this->permissions->canEdit(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'page'      => ['required', 'string', 'max:60'],
            'priority'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at'   => ['nullable', 'date', 'after:starts_at'],
        ]);

        $checked = $this->rules->validateOverrides(
            json_decode((string) $request->input('overrides', '[]'), true),
        );

        if ($checked['errors'] !== []) {
            ToastMagic::error(translate('this_campaign_cannot_be_saved') . ': ' . implode(', ', $checked['errors']));
            return back();
        }

        // A page the engine cannot serve is an override nobody will ever see; refuse the typo
        // here rather than storing it as a quiet no-op.
        $page = (string) $request->input('page', 'home');
        if (!in_array($page, $this->servablePageSlugs(), true)) {
            ToastMagic::error(translate('that_is_not_a_page_this_shop_serves') . '!');
            return back();
        }

        $campaign = ExperienceCampaign::create([
            'name'      => trim($request->input('name')),
            'status'    => ExperienceCampaign::STATUS_DRAFT,
            'page'      => $page,
            'priority'  => (int) ($request->input('priority') ?: 30),
            'starts_at' => $request->input('starts_at') ?: null,
            'ends_at'   => $request->input('ends_at') ?: null,
            'overrides' => $checked['overrides'],
        ]);

        $this->audit->record(
            action: 'commerce.campaign_created',
            subject: $campaign,
            after: ['name' => $campaign->name, 'page' => $campaign->page, 'priority' => $campaign->priority,
                    'window' => [$campaign->starts_at?->toIso8601String(), $campaign->ends_at?->toIso8601String()],
                    'overrides' => count($checked['overrides'])],
        );

        ToastMagic::success(translate('campaign_created_as_a_draft'));

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $campaign = ExperienceCampaign::find($request['id']);

        if ($campaign === null) {
            ToastMagic::error(translate('campaign_not_found') . '!');
            return back();
        }

        $before = ['status' => $campaign->status, 'priority' => $campaign->priority,
                   'window' => [$campaign->starts_at?->toIso8601String(), $campaign->ends_at?->toIso8601String()]];

        if ($request->has('name')) {
            $request->validate(['name' => ['required', 'string', 'max:120']]);
            $campaign->name = trim($request->input('name'));
        }
        if ($request->has('priority')) {
            $campaign->priority = max(1, min(100, (int) $request->input('priority')));
        }
        if ($request->has('starts_at') || $request->has('ends_at')) {
            $request->validate([
                'starts_at' => ['nullable', 'date'],
                'ends_at'   => ['nullable', 'date'],
            ]);
            $campaign->starts_at = $request->input('starts_at') ?: null;
            $campaign->ends_at = $request->input('ends_at') ?: null;

            if ($campaign->starts_at !== null && $campaign->ends_at !== null
                && $campaign->ends_at->lessThanOrEqualTo($campaign->starts_at)) {
                ToastMagic::error(translate('a_campaign_cannot_end_before_it_starts') . '!');
                return back();
            }
        }
        if ($request->has('overrides')) {
            $checked = $this->rules->validateOverrides(
                json_decode((string) $request->input('overrides', '[]'), true),
            );

            if ($checked['errors'] !== []) {
                ToastMagic::error(translate('this_campaign_cannot_be_saved') . ': ' . implode(', ', $checked['errors']));
                return back();
            }

            $campaign->overrides = $checked['overrides'];
        }

        if ($request->has('status')) {
            $status = (string) $request->input('status');

            if (!in_array($status, ExperienceCampaign::STATUSES, true)) {
                ToastMagic::error(translate('that_is_not_a_campaign_status') . '!');
                return back();
            }

            // Going live — now or on schedule — is where a conflict would start being served,
            // so it is where a conflict is refused (§38). It is also publishing: the capability
            // the plain module grant deliberately does NOT include.
            if (in_array($status, ExperienceCampaign::SERVABLE_STATUSES, true)) {
                if (!$this->permissions->canPublish()) {
                    ToastMagic::error(translate('putting_a_campaign_live_changes_the_storefront_and_needs_the_publish_permission') . '!');
                    return back();
                }

                $probe = clone $campaign;
                $probe->status = $status;
                $conflicts = $this->rules->conflictsFor($probe);

                if ($conflicts !== []) {
                    ToastMagic::error(
                        translate('this_would_contest_a_slot_at_equal_priority_with') . ': ' . implode(' · ', $conflicts),
                    );
                    return back();
                }

                if ($status === ExperienceCampaign::STATUS_SCHEDULED && $campaign->starts_at === null) {
                    ToastMagic::error(translate('a_scheduled_campaign_needs_a_start_time') . '!');
                    return back();
                }
            }

            $campaign->status = $status;
        }

        // Editing a campaign that is ALREADY live re-runs the §38 check: a priority or window
        // change can create the very tie the activation gate exists to refuse.
        if (in_array($campaign->status, ExperienceCampaign::SERVABLE_STATUSES, true)) {
            $conflicts = $this->rules->conflictsFor($campaign);

            if ($conflicts !== []) {
                ToastMagic::error(
                    translate('this_would_contest_a_slot_at_equal_priority_with') . ': ' . implode(' · ', $conflicts),
                );
                return back();
            }
        }

        $campaign->save();
        $this->flushServing();

        $this->audit->record(
            action: 'commerce.campaign_updated',
            subject: $campaign,
            before: $before,
            after: ['status' => $campaign->status, 'priority' => $campaign->priority,
                    'window' => [$campaign->starts_at?->toIso8601String(), $campaign->ends_at?->toIso8601String()]],
        );

        ToastMagic::success(translate('campaign_updated_successfully'));

        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $campaign = ExperienceCampaign::find($request['id']);

        if ($campaign === null) {
            ToastMagic::error(translate('campaign_not_found') . '!');
            return back();
        }

        $campaign->delete();
        $this->flushServing();

        $this->audit->record(
            action: 'commerce.campaign_deleted',
            subject: $campaign,
            before: ['name' => $campaign->name, 'status' => $campaign->status],
        );

        ToastMagic::success(translate('campaign_deleted_and_the_base_page_is_simply_what_it_always_was'));

        return back();
    }

    // ---------------------------------------------------------------------------------------

    /** A campaign transition must reach shoppers now, not a cache TTL from now. */
    private function flushServing(): void
    {
        \App\Services\Commerce\CampaignResolver::forget();
        $this->delivery->flush();
        $this->renderer->flush();
    }

    /** @return array<int, string> every slug either channel can be served */
    private function servablePageSlugs(): array
    {
        $theme = Theme::query()->where('is_active', true)->first();

        if ($theme === null) {
            return ExperiencePage::SYSTEM_SLUGS;
        }

        return array_values(array_unique([
            ...$this->pages->servableSlugs($theme->id, Channel::WEB),
            ...$this->pages->servableSlugs($theme->id, Channel::CUSTOMER_APP),
        ]));
    }

    private function ready(): bool
    {
        try {
            return Schema::hasTable('experience_campaigns');
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
