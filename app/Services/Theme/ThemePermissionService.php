<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Models\ThemeVersion;
use Throwable;

/**
 * Separate view / edit / publish permissions for the theme system (Phase 1 security requirement).
 *
 * The spec asks for these to be distinct because they carry very different risk: viewing a theme is
 * harmless, editing a draft is recoverable, but PUBLISHING changes the live storefront for every
 * customer. A single "themes" permission would let anyone who can tweak a colour also push it live.
 *
 * It layers on the existing admin RBAC rather than inventing a parallel system: the coarse
 * `themes_and_addons` module grant is still required (enforced by route middleware), and these
 * finer capabilities are read from the role's module_access. A role that predates this feature and
 * simply has the module keeps view+edit, so nothing breaks for existing admins — but publishing is
 * granted explicitly.
 *
 * The capabilities say WHAT an employee may do. Until F-15 nothing said WHERE, so an employee scoped
 * to the Emirati market who held `theme_publish` could publish, restore, activate or delete the
 * SAUDI storefront's theme: the capability answered yes and no code compared the theme with a
 * market. {@see mayWorkOn()} is that second half, and it is deliberately a separate question rather
 * than folded into {@see can()} — most callers of `can()` hold no theme, and a check that silently
 * passes when it has nothing to judge is worse than no check.
 */
class ThemePermissionService
{
    public const VIEW    = 'theme_view';
    public const EDIT    = 'theme_edit';
    public const PUBLISH = 'theme_publish';

    /**
     * Two capabilities that are edits with a wider blast radius than editing one section.
     *
     * Restoring replaces the draft somebody may be part-way through composing, and the global
     * styles are the colours and type of every page at once. Neither changes what customers see
     * until a publish, which is why they sit beside EDIT rather than beside PUBLISH — but a shop
     * that wants a junior staff member arranging sections without repainting the whole storefront
     * needs them to be separable, and they were not.
     */
    public const RESTORE = 'theme_restore';
    public const STYLES  = 'theme_styles';

    /** The coarse module that already gates the theme routes. */
    private const MODULE = 'themes_and_addons';

    public function __construct(
        private readonly StorefrontThemeResolver $themes,
        private readonly ThemeStorefrontSelection $reach,
    ) {
    }

    /**
     * Whether the signed-in employee may work on this theme AT ALL, whatever capability they hold: it is a theme of a market they hold.
     *
     * True of every theme while themes do not belong to storefronts, and for every employee the staff country scopes do not restrict —
     * the master admin, an employee with no scope row, and everybody while that switch is off. A theme that does not exist is never
     * workable, so a caller that could not load one gets a no rather than a yes by default.
     */
    public function mayWorkOn(?Theme $theme): bool
    {
        if (!$this->themes->keepsThemesByStorefront()) {
            return $theme !== null;
        }

        return $theme !== null && $this->reach->mayReach($this->themes->storefrontOf(theme: $theme));
    }

    /**
     * The same question asked of whatever a screen has in hand -- a theme, a version or an asset -- where READING the theme may itself
     * fail.
     *
     * The schedule path is deliberately resilient to a theme read that throws (`StorefrontThemeScheduledPublishTest`: the schedule is
     * still stored, and its audit line simply names no storefront), so a guard that made that read load-bearing would turn a tolerated
     * failure into a 500. A market that cannot be read is not a market an employee can be judged against: an employee the scopes do not
     * restrict is not being judged at all and proceeds exactly as before, and a restricted one is refused, because failing OPEN there
     * would hand them every market the moment a theme read failed. The exception is not reported here -- the write path that follows
     * reports it once, and reporting the same failure twice buys nothing.
     */
    public function mayWorkOnTheThemeOf(Theme|ThemeVersion|ThemeAsset|null $subject): bool
    {
        try {
            $theme = $subject instanceof Theme ? $subject : $subject?->theme;
        } catch (Throwable) {
            return !$this->reach->restrictsTheSignedInEmployee();
        }

        return $this->mayWorkOn($theme instanceof Theme ? $theme : null);
    }

    /** The three verbs F-15 names, each asked of one theme: the capability and the market together. */
    public function canEditTheme(?Theme $theme): bool
    {
        return $this->canEdit() && $this->mayWorkOn($theme);
    }

    public function canPublishTheme(?Theme $theme): bool
    {
        return $this->canPublish() && $this->mayWorkOn($theme);
    }

    public function canRestoreTheme(?Theme $theme): bool
    {
        return $this->canRestore() && $this->mayWorkOn($theme);
    }

    public function can(string $capability): bool
    {
        $admin = auth('admin')->user();
        if (!$admin) {
            return false;
        }

        // Master admin (role 1) keeps full access, consistent with the rest of the panel.
        if ((int) ($admin->admin_role_id ?? 0) === 1) {
            return true;
        }

        $granted = $this->grantedKeys($admin);

        // Explicit grant always wins.
        if (in_array($capability, $granted, true)) {
            return true;
        }

        // Backward compatibility: an existing role holding the module keeps everything it could do
        // before this feature — which is everything except publishing. Pushing to the live
        // storefront must be granted deliberately; restoring and restyling could already be done by
        // anyone with the module, and taking that away from existing roles would be a silent
        // regression dressed as security.
        if (in_array(self::MODULE, $granted, true)) {
            return $capability !== self::PUBLISH;
        }

        return false;
    }

    public function canView(): bool
    {
        return $this->can(self::VIEW);
    }

    public function canEdit(): bool
    {
        return $this->can(self::EDIT);
    }

    public function canPublish(): bool
    {
        return $this->can(self::PUBLISH);
    }

    public function canRestore(): bool
    {
        return $this->can(self::RESTORE);
    }

    public function canManageStyles(): bool
    {
        return $this->can(self::STYLES);
    }

    /**
     * Every capability a role can be granted, with the label the role form shows.
     *
     * These were enforced from the day they were written and offered by nothing: the role form had
     * no theme section, so `theme_publish` — the one capability that deliberately does NOT come
     * with the module — could not be granted to anybody. Any admin who was not the master admin
     * could compose a page and never publish it, with no way for anyone to fix that.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::VIEW    => 'theme_view_the_builder',
            self::EDIT    => 'theme_edit_pages_and_sections',
            self::PUBLISH => 'theme_publish_to_the_live_storefront',
            self::RESTORE => 'theme_restore_an_older_version',
            self::STYLES  => 'theme_manage_global_styles',
        ];
    }

    /** @return array<int, string> */
    private function grantedKeys(object $admin): array
    {
        $access = $admin->role->module_access ?? null;
        if (is_string($access)) {
            $decoded = json_decode($access, true);
            $access = is_array($decoded) ? $decoded : [];
        }

        return is_array($access) ? array_values(array_filter($access, 'is_string')) : [];
    }
}
