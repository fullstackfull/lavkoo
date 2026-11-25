<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\ThemeRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\AuditLogger;
use App\Services\Theme\BuilderReadiness;
use App\Services\Theme\PublishValidator;
use App\Services\Theme\StorefrontThemeResolver;
use App\Services\Theme\ThemeStorefrontSelection;
use App\Services\Theme\ThemeCopyToMarket;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ThemePermissionService;
use App\Services\Theme\ThemePortabilityService;
use App\Services\Theme\ThemeAssetService;
use App\Models\ThemeAsset;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin management for the Theme System (Phase 1.1 UI).
 *
 * Covers the non-visual half of the theme workflow — list, create, duplicate a version into a new
 * draft, publish a draft, and activate a theme. The visual editor (Phase 1.2) builds on the same
 * services. All actions are non-destructive: publishing archives the previous version rather than
 * overwriting it, so rollback is always possible.
 */
class ThemeManagementController extends BaseController
{
    public function __construct(
        private readonly ThemeRepositoryInterface $themeRepo,
        private readonly ThemeManager             $themeManager,
        private readonly ThemePermissionService   $permissions,
        private readonly ThemePortabilityService  $portability,
        private readonly ThemeAssetService         $assets,
        private readonly BuilderReadiness          $readiness,
        private readonly StorefrontThemeResolver   $storefrontThemes,
        private readonly ThemeStorefrontSelection  $selection,
        private readonly ThemeCopyToMarket         $copies,
        private readonly AuditLogger               $audit,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        // Assets arrived in a later migration than themes, so an installation that has run some but
        // not all migrations must still render this page rather than 500 on a missing table.
        $relations = Schema::hasTable('theme_assets') ? ['versions', 'assets'] : ['versions'];

        // F-15: one storefront's themes at a time, chosen by the selector this page now carries. While themes do not belong to
        // storefronts the selection is the default storefront, `isOffered()` is false, no selector renders and no filter is passed --
        // so a single-market installation lists exactly what it listed.
        $selected = $this->selection->current(request: $request);

        $themes = $this->themeRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: requestString('searchValue') ?: null,
            filters: $this->selection->isOffered() ? [ThemeStorefrontSelection::REQUEST_KEY => $selected] : [],
            relations: $relations,
            dataLimit: 20,
        );

        // App coverage per theme's latest draft, for the publish confirmation: "publish and the
        // app shows 9 of 11 sections" is a decision, the same sentence after publishing is a bug
        // report (spec §55). Only drafts are counted — that is the version a publish would ship.
        // And what would STOP that publish: a section the merchant added and never finished
        // configuring renders nothing, and the only place that used to show was the live site.
        $compatibility = [];
        $readiness = [];
        if (Schema::hasColumn('theme_sections', 'uuid')) {
            $report = app(\App\Services\Theme\ThemeCompatibilityReport::class);
            $validator = app(PublishValidator::class);
            foreach ($themes as $theme) {
                $draft = $theme->versions->where('status', 'draft')->sortByDesc('id')->first();
                if ($draft) {
                    $compatibility[$theme->id] = $report->for($draft);
                    $readiness[$theme->id] = $validator->inspect($draft);
                }
            }
        }

        return view('admin-views.theme.index', [
            'themes'       => $themes,
            'compatibility' => $compatibility,
            'readiness'    => $readiness,
            // A scheduled publish is only as good as the cron that fires it. Offering the control
            // while the scheduler is down would promise a merchant a midnight launch that never
            // happens — the same heartbeat the dashboard's health panel reads.
            'schedulerOk'  => $this->readiness->schedulerIsRunning(),
            'search'       => $request?->get('searchValue'),
            'presets'      => $this->portability->presets(),
            'assetsReady'  => Schema::hasTable('theme_assets'),
            'maxAssetSize' => ThemeAssetService::maxBytes(),
            // The selector, and what it is set to. Empty while there is no choice to make, which is what the view renders nothing for.
            'storefrontChoices' => $this->selection->isOffered() ? $this->selection->options() : [],
            'selectedStorefront' => $selected,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            ...$this->storefrontThemes->newThemeRules(),
        ]);

        $slug = Str::slug($validated['name']);
        if ($slug === '' || $this->themeRepo->slugExists($slug)) {
            ToastMagic::error(translate('a_theme_with_this_name_already_exists') . '!');
            return back()->withInput();
        }

        $this->themeManager->createTheme([
            'name'            => $validated['name'],
            'slug'            => $slug,
            'description'     => $validated['description'] ?? null,
            'is_active'       => false,
            'is_system'       => false,
            'created_by_type' => 'admin',
            'created_by_id'   => auth('admin')->id(),
            // A theme created from a storefront's own list belongs to that storefront unless the form says otherwise, so the admin does
            // not have to say twice which market they are working on.
            ...$this->storefrontThemes->newThemeAttributes(
                requestedCountry: $validated[StorefrontThemeResolver::COLUMN] ?? $this->selection->current(request: $request),
            ),
        ]);

        ToastMagic::success(translate('theme_created_successfully'));
        return $this->backToIndex();
    }

    /** Activate a theme (exclusive — all others are deactivated atomically, or only those of its storefront while themes are kept by storefront). */
    public function activate(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        if (!$this->permissions->canPublish()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_publish_a_theme') . '!');
            return $this->backToIndex();
        }

        $theme = $this->themeRepo->getFirstWhere(params: ['id' => $request['id']]);
        if (!$theme instanceof Theme) {
            ToastMagic::error(translate('theme_not_found') . '!');
            return $this->backToIndex();
        }

        if ($refusal = $this->refuseAnotherMarket($theme)) {
            return $refusal;
        }

        $this->themeManager->activate($theme);
        ToastMagic::success(translate('theme_activated_successfully'));

        return $this->backToIndex();
    }

    /** Delete a theme with all its versions and stored asset files. Active and system themes are protected. */
    public function delete(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        if (!$this->permissions->canPublish()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_publish_a_theme') . '!');
            return $this->backToIndex();
        }

        $theme = $this->themeRepo->getFirstWhere(params: ['id' => $request['id']]);
        if (!$theme instanceof Theme) {
            ToastMagic::error(translate('theme_not_found') . '!');
            return $this->backToIndex();
        }

        if ($refusal = $this->refuseAnotherMarket($theme)) {
            return $refusal;
        }

        if ($theme->is_active || $theme->is_system) {
            ToastMagic::error(translate('active_and_system_themes_cannot_be_deleted') . '!');
            return $this->backToIndex();
        }

        try {
            \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory('theme-assets/' . $theme->id);
        } catch (\Throwable) {
            // missing files must not block removing the theme
        }

        // The pages table has no FK cascade (its migration is guarded/additive) — left alone,
        // a deleted theme's pages linger and their slugs block re-use forever.
        try {
            \App\Models\ExperiencePage::query()->where('theme_id', $theme->id)->delete();
        } catch (\Throwable) {
            // unmigrated installs have no pages to remove
        }

        $theme->delete();
        ToastMagic::success(translate('theme_deleted_successfully'));

        return $this->backToIndex();
    }

    /** Publish a draft version (previous published version becomes archived). */
    public function publishVersion(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        if (!$this->permissions->canPublish()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_publish_a_theme') . '!');
            return $this->backToIndex();
        }

        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        if ($refusal = $this->refuseAnotherMarket($version)) {
            return $refusal;
        }

        // The last check before a draft becomes the shop. A section the merchant never finished
        // configuring does not render, and until now the only place that showed was the live site.
        $findings = app(PublishValidator::class)->inspect($version);

        if ($findings['blocking'] !== []) {
            ToastMagic::error(
                count($findings['blocking']) . ' ' . translate('sections_are_not_ready_to_publish') . '!',
            );

            return $this->backToIndex()->with('theme_publish_findings', $findings);
        }

        $this->themeManager->publish($version, $request['change_note'] ?? null);
        ToastMagic::success(translate('theme_version_published_successfully'));

        return $findings['warnings'] === []
            ? $this->backToIndex()
            : $this->backToIndex()->with('theme_publish_findings', $findings);
    }

    /**
     * Set (or clear) the moment a draft becomes the shop.
     *
     * Held to the same check as publishing now, because a schedule a merchant sets is a publish
     * they will not be watching: telling them at midnight is telling nobody.
     */
    public function scheduleVersion(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        if (!$this->permissions->canPublish()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_publish_a_theme') . '!');
            return $this->backToIndex();
        }

        $version = ThemeVersion::find($request['version_id']);
        if (!$version || !Schema::hasColumn($version->getTable(), 'publish_at')) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        if ($refusal = $this->refuseAnotherMarket($version)) {
            return $refusal;
        }

        // Only a draft can go live later: scheduling an already-published or archived version
        // stored a date the runner ignores forever — a promise the product quietly never kept.
        if (!$request->boolean('cancel') && $version->status !== ThemeVersion::STATUS_DRAFT) {
            ToastMagic::error(translate('only_a_draft_can_be_scheduled') . '!');
            return $this->backToIndex();
        }

        $previousMoment = $version->publish_at;

        if ($request->boolean('cancel')) {
            $version->forceFill(['publish_at' => null])->save();

            if ($previousMoment !== null) {
                $this->recordScheduleChange(action: 'theme.scheduled_publish_unset', version: $version, before: $previousMoment, after: null);
            }

            ToastMagic::success(translate('scheduled_publish_cancelled'));

            return $this->backToIndex();
        }

        $moment = $this->parseMoment($request['publish_at'] ?? null);
        if ($moment === null) {
            ToastMagic::error(translate('choose_a_date_and_time_in_the_future') . '!');
            return $this->backToIndex();
        }

        $findings = app(PublishValidator::class)->inspect($version);
        if ($findings['blocking'] !== []) {
            ToastMagic::error(
                count($findings['blocking']) . ' ' . translate('sections_are_not_ready_to_publish') . '!',
            );

            return $this->backToIndex()->with('theme_publish_findings', $findings);
        }

        $note = is_string($request['change_note'] ?? null) ? trim($request['change_note']) : '';
        $version->forceFill([
            'publish_at' => $moment,
            // Written now so the note travels with the version the command later publishes: at
            // fire time there is nobody to ask what this publish was for.
            'change_note' => $note !== '' ? mb_substr($note, 0, 300) : $version->change_note,
        ])->save();

        $this->recordScheduleChange(action: 'theme.scheduled_publish_set', version: $version, before: $previousMoment, after: $moment);

        ToastMagic::success(translate('this_version_will_publish_on') . ' ' . $moment->toDayDateTimeString());

        return $this->backToIndex();
    }

    /**
     * The audit line of a schedule set, changed or unset by hand, which names the storefront of the theme the way the lines of ThemeManager do. A lookup of the storefront that fails is
     * reported and adds nothing: the schedule is saved by now, and the line is not worth an error page.
     */
    private function recordScheduleChange(string $action, ThemeVersion $version, ?CarbonInterface $before, ?CarbonInterface $after): void
    {
        $storefront = rescue(
            callback: function () use ($version): array {
                $theme = $this->storefrontThemes->keepsThemesByStorefront() ? $version->theme : null;

                return $theme === null ? [] : $this->storefrontThemes->auditContextOf(theme: $theme);
            },
            rescue: [],
        );

        $this->audit->record(
            action: $action,
            subject: $version,
            before: ['publish_at' => $before?->toIso8601String()],
            after: ['publish_at' => $after?->toIso8601String()],
            context: ['theme_id' => $version->theme_id] + $storefront,
        );
    }

    /**
     * Whether the server cron that fires scheduled work has run recently.
     *
     * Ten minutes, matching the system health panel: the schedule pings every five, so one missed
     * beat is noise and two is a stopped cron.
     */

    /** A future moment from the form, or null when it is unparseable or already past. */
    private function parseMoment(mixed $input): ?\Illuminate\Support\Carbon
    {
        if (!is_string($input) || trim($input) === '') {
            return null;
        }

        try {
            $moment = \Illuminate\Support\Carbon::parse($input);
        } catch (\Throwable) {
            return null;
        }

        return $moment->isFuture() ? $moment : null;
    }

    /** Duplicate a version into a fresh draft (revision / edit-a-copy workflow). */
    public function duplicateVersion(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        if ($refusal = $this->refuseAnotherMarket($version)) {
            return $refusal;
        }

        $this->themeManager->createDraftFrom($version);
        ToastMagic::success(translate('draft_created_successfully'));

        return $this->backToIndex();
    }

    /** Restore an archived/older version into a new draft (non-destructive). */
    public function restoreVersion(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        // Its own capability: restoring replaces the draft somebody may be part-way through
        // composing, which is a bigger thing than editing one section of it.
        if (!$this->permissions->canRestore()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_restore_a_version') . '!');
            return $this->backToIndex();
        }

        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        if ($refusal = $this->refuseAnotherMarket($version)) {
            return $refusal;
        }

        $draft = $this->themeManager->restoreVersion($version);
        ToastMagic::success(translate('version_restored_into_a_new_draft') . ' #' . $draft->id);

        return $this->backToIndex();
    }

    /** Download a version as a portable JSON file. */
    public function exportVersion(Request $request): StreamedResponse|RedirectResponse
    {
        if (!$this->permissions->canView()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_view_a_theme') . '!');
            return $this->backToIndex();
        }

        $version = ThemeVersion::with('theme')->find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        // An export is a complete copy of a market's storefront in one file -- its sections, its settings and the ids it points at. A
        // read of another market's theme is exactly what the staff scopes exist to refuse, so it is refused here as well as the writes.
        if ($refusal = $this->refuseAnotherMarket($version)) {
            return $refusal;
        }

        $payload = $this->portability->export($version);
        $filename = 'theme-' . ($version->theme?->slug ?? 'export') . '-v' . $version->id . '.json';

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Download the example theme file.
     *
     * Same payload the Minimal Luxury preset imports, handed over as a .json so a merchant can see
     * exactly what a theme file looks like, edit it, and import it back.
     */
    public function downloadExample(): StreamedResponse
    {
        $payload = $this->portability->exampleTheme();

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, 'example-theme-minimal-luxury.json', ['Content-Type' => 'application/json']);
    }

    /** Import an uploaded theme file into a NEW inactive theme (never overwrites, never publishes). */
    public function importTheme(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $request->validate([
            'theme_file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:2048'],
            'name'       => ['nullable', 'string', 'max:191'],
        ]);

        $decoded = json_decode(file_get_contents($request->file('theme_file')->getRealPath()), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ToastMagic::error(translate('the_file_is_not_valid_json') . '!');
            return $this->backToIndex();
        }

        $imported = $this->portability->import(
            payload: $decoded,
            nameOverride: $request->get('name'),
            adminId: auth('admin')->id(),
            storefront: $this->selection->current(request: $request),
        );

        if (!$imported['theme']) {
            ToastMagic::error(translate('import_failed') . ': ' . implode(', ', $imported['result']['errors']));
            return $this->backToIndex();
        }

        foreach ($imported['result']['warnings'] as $warning) {
            // Shown raw: each warning carries payload-specific detail, and translate() on a
            // dynamic string mints a new language key per import file.
            ToastMagic::warning($warning);
        }
        ToastMagic::success(translate('theme_imported_successfully'));

        return $this->backToIndex();
    }

    /**
     * Copy a version into another market as that market's own draft (F-11).
     *
     * A merchant who has composed one storefront starts the next from it instead of from an empty draft. The copy is a DRAFT owned by
     * the target market -- a plain export/import would have produced a second theme of the DEFAULT market, because an imported theme
     * names no country and a theme that names none is the default storefront's.
     *
     * What could not be brought is reported rather than left for a shopper to find: a product, category, brand or deal the source
     * theme names by id that the target market does not offer renders an empty rail.
     */
    public function copyToMarket(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $request->validate([
            'version_id' => ['required', 'integer'],
            'market'     => ['required', 'string', 'size:2'],
            'name'       => ['nullable', 'string', 'max:191'],
        ]);

        $version = ThemeVersion::query()->with('theme')->find($request->integer('version_id'));

        if ($version === null) {
            ToastMagic::error(translate('that_theme_version_was_not_found') . '!');
            return $this->backToIndex();
        }

        // The SOURCE has to be a theme this employee may work on. The destination is the copier's own rule, and a restricted employee
        // may not send a copy to a market they do not hold either.
        if ($refusal = $this->refuseAnotherMarket($version)) {
            return $refusal;
        }

        if (!$this->selection->mayReach((string) $request->input('market'))) {
            ToastMagic::error(translate('this_theme_belongs_to_another_market') . '!');
            return $this->backToIndex();
        }

        $copied = $this->copies->copy($version, (string) $request->input('market'), $request->get('name'), auth('admin')->id());

        if ($copied['refused'] !== null) {
            ToastMagic::error(translate($copied['refused']) . '!');
            return $this->backToIndex();
        }

        $this->audit->record(
            action: 'theme.copied_to_market',
            subject: $copied['theme'],
            after: $copied['report'],
        );

        ToastMagic::success(translate('the_theme_was_copied_as_a_draft_of_that_market'));

        foreach ($this->whatTheCopyCouldNotBring($copied['report']) as $warning) {
            ToastMagic::warning($warning);
        }

        return $this->backToIndex();
    }

    /**
     * The sentences an operator needs after a copy, each naming the ids rather than counting them: "three products are missing" sends
     * them hunting, and a gate that prints a count and withholds the lines costs a round trip every time it fires.
     *
     * @param array<string, mixed> $report
     * @return array<int, string>
     */
    private function whatTheCopyCouldNotBring(array $report): array
    {
        $warnings = [];

        foreach ((array) ($report['catalogue_missing'] ?? []) as $kind => $ids) {
            $warnings[] = translate('this_market_does_not_offer_these') . ' ' . translate(str_replace('_', ' ', (string) $kind))
                . ': ' . implode(', ', (array) $ids);
        }

        foreach ((array) ($report['catalogue_not_checked'] ?? []) as $kind => $ids) {
            $warnings[] = translate('nothing_on_this_platform_can_place_these_in_a_market_yet') . ' '
                . translate(str_replace('_', ' ', (string) $kind)) . ': ' . implode(', ', (array) $ids);
        }

        if (($report['assets']['not_copied'] ?? []) !== []) {
            $warnings[] = translate('these_images_could_not_be_copied_and_the_copy_has_none_of_its_own')
                . ': ' . implode(', ', (array) $report['assets']['not_copied']);
        }

        if (($report['banners']['missing'] ?? []) !== []) {
            $warnings[] = translate('these_banner_rows_are_gone_so_their_sections_point_at_nothing')
                . ': ' . implode(', ', (array) $report['banners']['missing']);
        }

        return $warnings;
    }

    /** Create a theme from a built-in preset. */
    public function importPreset(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $presets = $this->portability->presets();
        $key = (string) $request['preset'];
        if (!array_key_exists($key, $presets)) {
            ToastMagic::error(translate('unknown_preset') . '!');
            return $this->backToIndex();
        }

        $imported = $this->portability->import($presets[$key]['payload'], $request->get('name'), auth('admin')->id());
        $imported['theme']
            ? ToastMagic::success(translate('theme_created_from_preset_successfully'))
            : ToastMagic::error(translate('import_failed') . '!');

        return $this->backToIndex();
    }

    /** Upload an image asset for a theme (logo, favicon, background). */
    public function uploadAsset(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        // The `max` here only produces a friendlier error early; the service re-checks the size and
        // sniffs the real MIME type, because request validation trusts the client-supplied type.
        $request->validate([
            'asset'    => ['required', 'file', 'max:' . (int) (ThemeAssetService::maxBytes() / 1024)],
            'theme_id' => ['required', 'integer'],
            'label'    => ['nullable', 'string', 'max:120'],
        ]);

        $theme = $this->themeRepo->getFirstWhere(params: ['id' => $request['theme_id']]);
        if (!$theme instanceof Theme) {
            ToastMagic::error(translate('theme_not_found') . '!');
            return $this->backToIndex();
        }

        if ($refusal = $this->refuseAnotherMarket($theme)) {
            return $refusal;
        }

        $result = $this->assets->upload($theme, $request->file('asset'), $request->get('label'), auth('admin')->id());
        $result['asset']
            ? ToastMagic::success(translate('asset_uploaded_successfully'))
            : ToastMagic::error(translate($result['error'] ?? 'the_upload_failed'));

        return $this->backToIndex();
    }

    public function deleteAsset(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $asset = ThemeAsset::find($request['id']);
        if ($asset && ($refusal = $this->refuseAnotherMarket($asset))) {
            return $refusal;
        }

        if ($asset) {
            $this->assets->delete($asset);
            ToastMagic::success(translate('asset_deleted_successfully'));
        }

        return $this->backToIndex();
    }

    /**
     * Refuses an action on a theme of a market this employee does not hold (F-15, criterion 3).
     *
     * The capability check above each of these actions says whether the employee may publish, edit or restore AT ALL; this says whether
     * they may do it HERE. Both are needed: an employee scoped to AE who holds `theme_publish` passed every check in this controller and
     * could publish, activate, delete, schedule, restore or re-skin the SAUDI storefront. Returns null when the action may proceed, so a
     * caller reads as `if ($refusal = ...) { return $refusal; }` and cannot forget the early return.
     */
    private function refuseAnotherMarket(Theme|ThemeVersion|ThemeAsset|null $subject): ?RedirectResponse
    {
        if ($this->permissions->mayWorkOnTheThemeOf($subject)) {
            return null;
        }

        ToastMagic::error(translate('this_theme_belongs_to_another_market') . '!');

        return $this->backToIndex();
    }

    private function blockedOnDemo(): bool
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return true;
        }
        return false;
    }

    /**
     * Back to Theme Management, or to the builder page the action was fired from.
     *
     * The builder's go-live bar activates and publishes without leaving the canvas, so a merchant
     * who clicks "publish" there stays on the page they were composing.
     */
    private function backToIndex(): RedirectResponse
    {
        $builderPage = request('return_to_builder');
        if ($builderPage && in_array($builderPage, ['home', 'header', 'footer'], true)) {
            return redirect()->route('admin.theme.builder.index', ['page' => $builderPage]);
        }

        return redirect()->route('admin.theme.index');
    }
}
