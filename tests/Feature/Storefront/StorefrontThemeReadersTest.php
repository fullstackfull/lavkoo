<?php

namespace Tests\Feature\Storefront;

use App\Contracts\Repositories\ThemeRepositoryInterface;
use App\Http\Controllers\Admin\Commerce\CampaignController;
use App\Http\Controllers\Admin\Commerce\ExperimentController;
use App\Http\Controllers\Admin\Settings\AppBuilderController;
use App\Http\Controllers\Admin\Settings\ThemeBuilderController;
use App\Http\Controllers\Web\ExperiencePageController;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Commerce\ExperienceHealth;
use App\Services\Storefront\StorefrontContext;
use App\Services\Theme\ThemeBannerLink;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ThemeManager;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-01.0 to F-01.2, one reader at a time: every place that reads the active theme answers for the storefront it is asked for. The world has a Saudi theme (it names
 * no country), an Emirati theme, and a Kuwaiti storefront that has no theme of its own. Each theme is told apart by its revision, by the height of its spacer, by the
 * banner type its banner section names and by the one custom page it owns.
 *
 * Switched on, a storefront is given its own theme, else the Saudi one, and so is the admin panel, which no middleware binds to a storefront. Switched off, the
 * theme that was activated last is the answer for every storefront, whatever country it was made for: the column is not read.
 */
class StorefrontThemeReadersTest extends TestCase
{
    use BuildsThemeWorld;

    /** The fourteen places that read the active theme when the storefront themes were added. */
    private const READERS = [
        'the web renderer', 'the app delivery', 'the banner link', 'the published version of the active theme', 'the theme API page list',
        'the custom pages of the web', 'the experiments screen', 'the campaigns screen', 'the campaign page list', 'the experience health',
        'the app builder', 'the builder pickers', 'the builder theme of a version', 'the theme repository',
    ];

    /** What tells a theme apart: its revision, the height of its spacer, the banner type of its banner section, and the custom page it owns. */
    private const THEMES = [
        'Saudi' => ['country' => null, 'revision' => 3, 'height' => 41, 'banner' => 'Saudi banner', 'page' => 'saudi-offers'],
        'Emirati' => ['country' => 'AE', 'revision' => 7, 'height' => 42, 'banner' => 'Emirati banner', 'page' => 'emirati-offers'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->openEveryStorefront();

        foreach (self::THEMES as $name => $identity) {
            $theme = $this->giveStorefrontTheme(name: $name, country: $identity['country'], revision: $identity['revision'], spacerHeight: $identity['height'], page: $identity['page']);
            ThemeSection::query()->create([
                'theme_version_id' => $theme->publishedVersion->id, 'page' => 'home', 'type' => 'store_banner', 'sort_order' => 2, 'settings' => ['banner_type' => $identity['banner']],
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /** Every reader asked from the admin panel (no storefront), from the default storefront, from one that has a theme and from one that has none. */
    public static function everyReaderFromEveryStorefront(): array
    {
        $cases = [];
        foreach (self::READERS as $reader) {
            foreach ([null, 'SA', 'AE', 'KW'] as $storefront) {
                $cases["{$reader} from " . ($storefront ?? 'the admin panel')] = [$reader, $storefront];
            }
        }

        return $cases;
    }

    #[DataProvider('everyReaderFromEveryStorefront')]
    public function test_switched_on_every_reader_answers_the_theme_of_its_storefront_else_the_default_storefronts(string $reader, ?string $storefront): void
    {
        $this->activateWithTheSwitchOn('Saudi', 'Emirati');

        $this->assertSame($storefront === 'AE' ? 'Emirati' : 'Saudi', $this->themeUsedBy($reader, $storefront));
    }

    #[DataProvider('everyReaderFromEveryStorefront')]
    public function test_switched_off_every_reader_answers_the_theme_that_was_activated_last_whatever_the_storefront(string $reader, ?string $storefront): void
    {
        $this->activateWithTheSwitchOff('Saudi');
        $this->activateWithTheSwitchOff('Emirati');
        $this->assertSame(['Emirati'], Theme::query()->where('is_active', true)->pluck('name')->all());

        $this->assertSame('Emirati', $this->themeUsedBy($reader, $storefront));
    }

    #[DataProvider('everyReaderFromEveryStorefront')]
    public function test_a_storefront_that_has_no_theme_of_its_own_and_whose_default_has_none_is_served_no_theme(string $reader, ?string $storefront): void
    {
        $this->activateWithTheSwitchOn('Emirati');

        $expected = match (true) {
            $storefront === 'AE' => 'Emirati',
            // The App Builder falls back to the newest theme when none is active, as it always did. Narrowing that to a storefront the admin chooses is F-15.
            $reader === 'the app builder' => 'Emirati',
            default => null,
        };
        $this->assertSame($expected, $this->themeUsedBy($reader, $storefront));
    }

    /** The way the platform ran before the storefronts: each activation deactivates every other theme. */
    private function activateWithTheSwitchOff(string $name): void
    {
        app(ThemeManager::class)->activate(Theme::query()->where('name', $name)->firstOrFail());
    }

    private function activateWithTheSwitchOn(string ...$names): void
    {
        $this->switchStorefrontThemesOn();
        foreach ($names as $name) {
            app(ThemeManager::class)->activate(Theme::query()->where('name', $name)->firstOrFail());
        }
    }

    /** The name of the theme a reader used for a request from this storefront, or null when it used none. */
    private function themeUsedBy(string $reader, ?string $storefront): ?string
    {
        $storefront === null ? $this->app->forgetInstance(StorefrontContext::class) : $this->serveRequestFromStorefront($storefront);

        return match ($reader) {
            'the web renderer' => $this->nameWhere('height', $this->drawnSpacerHeight()),
            'the app delivery' => $this->nameWhere('revision', app(ThemeDelivery::class)->revision()['revision']),
            'the banner link' => $this->nameWhere('banner', $this->privately(app(ThemeBannerLink::class), 'activeThemeSections')->first()?->settings['banner_type']),
            'the published version of the active theme' => app(ThemeManager::class)->activeThemePublishedVersion()?->theme->name,
            'the theme API page list' => $this->nameOfThePageTheApiServes($storefront),
            'the custom pages of the web' => $this->nameOfThePageTheWebServes(),
            'the experiments screen' => $this->nameOfVersion($this->privately(app(ExperimentController::class), 'publishedVersionId')),
            'the campaigns screen' => $this->nameOfThePageListed(app(CampaignController::class)->index(null)->getData()['pages']),
            'the campaign page list' => $this->nameOfThePageListed($this->privately(app(CampaignController::class), 'servablePageSlugs')),
            'the experience health' => $this->nameOfVersion($this->privately(app(ExperienceHealth::class), 'publishedVersionId')),
            'the app builder' => $this->privately(app(AppBuilderController::class), 'activeTheme')?->name,
            'the builder pickers' => $this->nameOfThePageListed(array_column(app(ThemeBuilderController::class)->resources(Request::create('/', 'GET', ['resource' => 'experience_page']))->getData(true)['options'], 'value')),
            'the builder theme of a version' => $this->privately(app(ThemeBuilderController::class), 'resolveThemeFor', Request::create('/', 'POST'))?->name,
            'the theme repository' => app(ThemeRepositoryInterface::class)->getActiveTheme()?->name,
        };
    }

    private function nameWhere(string $identity, mixed $value): ?string
    {
        foreach (self::THEMES as $name => $themeIdentity) {
            if ($themeIdentity[$identity] === $value) {
                return $name;
            }
        }

        return null;
    }

    private function nameOfVersion(?int $versionId): ?string
    {
        return $versionId === null ? null : ThemeVersion::query()->findOrFail($versionId)->theme->name;
    }

    /** @param list<string> $slugs */
    private function nameOfThePageListed(array $slugs): ?string
    {
        foreach (self::THEMES as $name => $identity) {
            if (in_array($identity['page'], $slugs, true)) {
                return $name;
            }
        }

        return null;
    }

    private function nameOfThePageTheApiServes(?string $storefront): ?string
    {
        foreach (self::THEMES as $name => $identity) {
            $response = $this->getJson('/api/v1/theme/sections?page=' . $identity['page'], $storefront === null ? [] : ['X-Storefront' => $storefront]);
            if ($response->status() === 200) {
                return $name;
            }
        }

        return null;
    }

    private function nameOfThePageTheWebServes(): ?string
    {
        foreach (self::THEMES as $name => $identity) {
            try {
                app(ExperiencePageController::class)->show($identity['page']);

                return $name;
            } catch (NotFoundHttpException) {
                continue;
            }
        }

        return null;
    }

    private function privately(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }
}
