<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * The one place that answers "which theme does this storefront serve" (GCC F-01). Every reader of the active theme asks here, and a scan test fails when a file reads
 * it any other way.
 *
 * While the storefront themes are switched off (gcc.storefronts.themes.enabled), or before the themes migration has run, one theme is active for the whole platform and
 * that is the answer for every request, exactly as it always was: nothing else is read, not even the storefront. While they are on, a theme belongs to the storefront its
 * `storefront_country` names (NULL, or the default country, is the default storefront's), and a request is served the active theme of its storefront, or the default
 * storefront's when its own has none. A request that no middleware bound to a storefront (the admin panel, a queue, a console command) is the default storefront's, and so
 * is every request while storefronts are off (GCC_STOREFRONTS_ENABLED), because the caches of the theme are keyed by storefront only while they are on.
 */
#[Singleton]
final class StorefrontThemeResolver
{
    public const COLUMN = 'storefront_country';

    private const TABLE = 'themes';

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $columnAnswer = null;

    /** Whether themes belong to storefronts: the switch is on and the themes migration has run. */
    public function keepsThemesByStorefront(): bool
    {
        return (bool) config('gcc.storefronts.themes.enabled') && $this->columnReady();
    }

    /** The active theme of the storefront the request is served from, else the default storefront's. */
    public function activeTheme(): ?Theme
    {
        // The switch is read BEFORE the request's storefront, and that order is a property somebody pinned: switched off, the storefront
        // is not read at all, so a configuration that cannot be resolved does not break the one theme the platform serves
        // (`StorefrontThemeSwitchTest::test_switched_off_the_storefront_is_not_read_at_all`). Delegating straight to the method below
        // resolves the storefront first and threw. Found by that test, which is what it is for.
        if (!$this->keepsThemesByStorefront()) {
            return Theme::query()->where('is_active', true)->first();
        }

        return $this->activeThemeServedBy(storefront: $this->requestStorefront());
    }

    /** The published version of the active theme, which is what a customer is shown. */
    public function publishedVersion(): ?ThemeVersion
    {
        return $this->publishedVersionOf(theme: $this->activeTheme());
    }

    /**
     * The active theme the storefront NAMED would be served -- its own, else the default storefront's -- for a reader asking about a
     * market nobody is being served from (I2-12: the readiness check of a market an operator is about to open).
     *
     * {@see self::activeTheme()} is this question asked of the request's own storefront, so there is one answer and not two: a readiness
     * report that resolved the theme its own way could say a market is ready while the renderer serves it nothing.
     */
    public function activeThemeServedBy(string $storefront): ?Theme
    {
        if (!$this->keepsThemesByStorefront()) {
            return Theme::query()->where('is_active', true)->first();
        }

        $default = $this->defaultStorefront();

        return $this->activeThemeOf(storefront: $storefront) ?? ($storefront === $default ? null : $this->activeThemeOf(storefront: $default));
    }

    /** The published version a named storefront would serve, which is what its customers would be shown. */
    public function publishedVersionServedBy(string $storefront): ?ThemeVersion
    {
        return $this->publishedVersionOf(theme: $this->activeThemeServedBy(storefront: $storefront));
    }

    /**
     * The design identity every storefront shares (F-12): the settings of the default storefront's published theme, which a theme of another storefront lays its own settings over, key by key, so that it
     * overrides the keys it sets and inherits the rest. Empty for a theme of the default storefront, which is the identity, for a version that belongs to no theme, for every theme while themes are not
     * kept by storefront, and while the default storefront has nothing published.
     *
     * @return array<string, mixed>
     */
    public function sharedIdentityBeneath(?ThemeVersion $version): array
    {
        if ($version === null || !$this->keepsThemesByStorefront()) {
            return [];
        }

        $theme = $version->theme;
        if ($theme === null || $this->storefrontOf(theme: $theme) === $this->defaultStorefront()) {
            return [];
        }

        $shared = $this->publishedVersionOf(theme: $this->activeThemeOf(storefront: $this->defaultStorefront()))?->settings;

        return is_array($shared) ? $shared : [];
    }

    /**
     * Whether a version may be previewed on this request (F-07): while themes are kept by storefront, only a version of a theme of the storefront the request is served from, so a preview token or a
     * session preview of one storefront's draft is ignored on every other storefront, as a foreign token is. A theme that names no country is the default storefront's. Any version while they are not.
     */
    public function mayPreview(ThemeVersion $version): bool
    {
        if (!$this->keepsThemesByStorefront()) {
            return true;
        }

        $theme = $version->theme;

        return $theme !== null && $this->storefrontOf(theme: $theme) === $this->requestStorefront();
    }

    /** The themes activating this one competes with: every theme while themes are not kept by storefront, else those of its storefront. */
    public function themesSharingStorefrontWith(Theme $theme): Builder
    {
        return $this->keepsThemesByStorefront() ? $this->themesOf(storefront: $this->storefrontOf(theme: $theme)) : Theme::query();
    }

    /**
     * The storefronts whose cached copies of a theme go stale when it changes (F-02): only its own, unless it is the default storefront's, which every storefront without a theme of
     * its own falls back to, or themes are not kept by storefront, where one theme serves all. Null means every storefront, which is what a change always reached.
     *
     * @return list<string>|null
     */
    public function storefrontsAffectedBy(Theme $theme): ?array
    {
        if (!$this->keepsThemesByStorefront()) {
            return null;
        }

        $storefront = $this->storefrontOf(theme: $theme);

        return $storefront === $this->defaultStorefront() ? null : [$storefront];
    }

    /**
     * What an audit line of a change of a theme adds to say which storefront it was for. Nothing while themes are not kept by storefront, so the lines stay what they were.
     *
     * @return array<string, string>
     */
    public function auditContextOf(Theme $theme): array
    {
        return $this->keepsThemesByStorefront() ? ['storefront' => $this->storefrontOf(theme: $theme)] : [];
    }

    /** The storefront a theme belongs to: the default one for a theme that names no country. */
    public function storefrontOf(Theme $theme): string
    {
        return (string) ($theme->getAttribute(self::COLUMN) ?: $this->defaultStorefront());
    }

    /**
     * What each storefront a request can reach is served, for the admin list. A storefront with no theme of its own is shown with the default storefront's, and
     * one that has none to show is shown with no theme: the built-in pages.
     *
     * @return list<array{country: string, label: string, theme: ?Theme, inherited: bool}>
     */
    public function servedByStorefront(): array
    {
        if (!$this->keepsThemesByStorefront()) {
            return [];
        }

        $default = $this->defaultStorefront();
        $defaultTheme = $this->activeThemeOf(storefront: $default);

        return array_map(function (array $storefront) use ($default, $defaultTheme): array {
            $own = $storefront['country'] === $default ? $defaultTheme : $this->activeThemeOf(storefront: $storefront['country']);
            $served = $own ?? $defaultTheme;

            return [
                'country' => $storefront['country'],
                'label' => ProductCountry::label(country: $storefront['country']),
                'theme' => $served,
                'inherited' => $own === null && $served !== null,
            ];
        }, StorefrontResolver::fromConfig()->activeStorefronts());
    }

    /**
     * The validation of the storefront field of a new theme: a configured country. Every configured country is offered, open or not, because a theme is prepared
     * before its storefront opens. Nothing is validated while themes are not kept by storefront.
     *
     * @return array<string, list<mixed>>
     */
    public function newThemeRules(): array
    {
        return $this->keepsThemesByStorefront() ? [self::COLUMN => ['nullable', Rule::in(ProductCountry::options())]] : [];
    }

    /**
     * What a theme that is created now records of its storefront: nothing while themes are not kept by storefront, NULL for the default storefront and for a country
     * that is not a configured one (the theme is then the default storefront's), the country otherwise.
     *
     * @return array<string, string|null>
     */
    public function newThemeAttributes(mixed $requestedCountry): array
    {
        if (!$this->keepsThemesByStorefront()) {
            return [];
        }

        $isAnotherConfiguredStorefront = $requestedCountry !== $this->defaultStorefront() && in_array($requestedCountry, ProductCountry::options(), true);

        return [self::COLUMN => $isAnotherConfiguredStorefront ? $requestedCountry : null];
    }

    private function publishedVersionOf(?Theme $theme): ?ThemeVersion
    {
        return $theme === null ? null : ThemeVersion::query()->where('theme_id', $theme->id)->published()->first();
    }

    private function activeThemeOf(string $storefront): ?Theme
    {
        return $this->themesOf(storefront: $storefront)->where('is_active', true)->orderBy('id')->first();
    }

    /** The themes of a storefront: those of the default storefront include the ones that name no country. */
    public function themesOf(string $storefront): Builder
    {
        $default = $this->defaultStorefront();

        return Theme::query()->when(
            $storefront === $default,
            fn (Builder $themes) => $themes->where(fn (Builder $own) => $own->whereNull(self::COLUMN)->orWhere(self::COLUMN, $default)),
            fn (Builder $themes) => $themes->where(self::COLUMN, $storefront),
        );
    }

    private function defaultStorefront(): string
    {
        return StorefrontResolver::fromConfig()->defaultCountry();
    }

    /** The storefront the request is served from. While storefronts are off the only storefront is the default one, whatever a payment callback bound the request to: the page caches key on it the same way. */
    private function requestStorefront(): string
    {
        return StorefrontResolver::fromConfig()->isEnabled() ? StorefrontContext::current()->country : $this->defaultStorefront();
    }

    private function columnReady(): bool
    {
        $answer = $this->columnAnswer;
        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasColumn(self::TABLE, self::COLUMN);
        $this->columnAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
