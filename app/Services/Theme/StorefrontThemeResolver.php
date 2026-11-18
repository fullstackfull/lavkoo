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
        if (!$this->keepsThemesByStorefront()) {
            return Theme::query()->where('is_active', true)->first();
        }

        $storefronts = StorefrontResolver::fromConfig();
        $default = $storefronts->defaultCountry();
        // While storefronts are off the only storefront is the default one, whatever a payment callback bound the request to: the page caches key on it the same way.
        $country = $storefronts->isEnabled() ? StorefrontContext::current()->country : $default;

        return $this->activeThemeOf(storefront: $country) ?? ($country === $default ? null : $this->activeThemeOf(storefront: $default));
    }

    /** The published version of the active theme, which is what a customer is shown. */
    public function publishedVersion(): ?ThemeVersion
    {
        $theme = $this->activeTheme();

        return $theme === null ? null : ThemeVersion::query()->where('theme_id', $theme->id)->published()->first();
    }

    /** The themes activating this one competes with: every theme while themes are not kept by storefront, else those of its storefront. */
    public function themesSharingStorefrontWith(Theme $theme): Builder
    {
        return $this->keepsThemesByStorefront() ? $this->themesOf(storefront: $this->storefrontOf(theme: $theme)) : Theme::query();
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

    private function activeThemeOf(string $storefront): ?Theme
    {
        return $this->themesOf(storefront: $storefront)->where('is_active', true)->orderBy('id')->first();
    }

    /** The themes of a storefront: those of the default storefront include the ones that name no country. */
    private function themesOf(string $storefront): Builder
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
