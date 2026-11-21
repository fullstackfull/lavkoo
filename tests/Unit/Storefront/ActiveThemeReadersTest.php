<?php

namespace Tests\Unit\Storefront;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * GCC F-01.2: every reader of the active theme asks the one storefront-aware resolver (App\Services\Theme\StorefrontThemeResolver), because which theme a storefront
 * serves is decided there and nowhere else. A file that reads the active theme any other way would serve one storefront the theme of another, and nothing else would
 * say so. Checked in the source of the application (code, not comments).
 *
 * It catches the forms a direct read has taken: the Theme model, or the themes table, constrained to the active flag, in one statement. It is a lint, not a proof: a
 * read that hides the model behind an alias or the flag behind a variable is not seen, so a reviewer still reads a new theme query.
 */
class ActiveThemeReadersTest extends TestCase
{
    /** The one file that may read the active theme itself: it is the reader the others ask. */
    private const THE_RESOLVER = 'app/Services/Theme/StorefrontThemeResolver.php';

    /** Where the application keeps code that could read a theme. */
    private const DIRECTORIES = ['app', 'routes', 'Modules', 'config', 'database', 'resources/views', 'resources/themes'];

    /** The model, or the table, the way a query names it. */
    private const NAMES_THE_THEMES = '/\bTheme::|\$this->theme->|\bnew\s+Theme\b|\bDB::table\(\s*[\'"]themes[\'"]/';

    /** The active flag held to a true value: a read of the active theme, or a write that makes one without ThemeManager::activate. */
    private const HOLDS_THE_ACTIVE_FLAG = '/[\'"]is_active[\'"]\s*(?:,|=>)\s*(?:true|TRUE|1|[\'"]1[\'"])(?![\w])|\bwhereIsActive\(\s*(?:true|TRUE|1)\b/';

    /**
     * The readers the platform had when the storefront themes were added, each with what it asks the resolver and how many times. The scan below fails when a reader
     * goes back to its own query; this fails when a reader stops asking.
     *
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function routedReaders(): array
    {
        return [
            'the web renderer' => ['app/Services/Theme/StorefrontThemeRenderer.php', 1, 'app(StorefrontThemeResolver::class)->publishedVersion()'],
            'the app delivery' => ['app/Services/Theme/ThemeDelivery.php', 1, 'app(StorefrontThemeResolver::class)->publishedVersion()'],
            'the banner link' => ['app/Services/Theme/ThemeBannerLink.php', 1, 'app(StorefrontThemeResolver::class)->activeTheme()'],
            'the published version of the active theme' => ['app/Services/Theme/ThemeManager.php', 1, 'app(StorefrontThemeResolver::class)->publishedVersion()'],
            'the theme API page list' => ['app/Http/Controllers/RestAPI/v1/ThemeSectionController.php', 1, '$this->themes->activeTheme()'],
            'the experiments screen' => ['app/Http/Controllers/Admin/Commerce/ExperimentController.php', 1, '$this->themes->publishedVersion()'],
            'the campaigns screen and its page list' => ['app/Http/Controllers/Admin/Commerce/CampaignController.php', 2, '$this->themes->activeTheme()'],
            'the experience health' => ['app/Services/Commerce/ExperienceHealth.php', 1, 'app(StorefrontThemeResolver::class)->publishedVersion()'],
            'the app builder' => ['app/Http/Controllers/Admin/Settings/AppBuilderController.php', 1, '$this->themes->activeTheme()'],
            'the builder pickers and the builder theme of a version' => ['app/Http/Controllers/Admin/Settings/ThemeBuilderController.php', 2, '$this->themes->activeTheme()'],
            'the custom pages of the web' => ['app/Http/Controllers/Web/ExperiencePageController.php', 1, '$this->themes->activeTheme()'],
            'the theme repository' => ['app/Repositories/ThemeRepository.php', 1, '$this->themes->activeTheme()'],
        ];
    }

    #[DataProvider('routedReaders')]
    public function test_every_known_reader_asks_the_resolver(string $file, int $times, string $call): void
    {
        $this->assertSame($times, substr_count($this->codeOf((string) file_get_contents(base_path($file))), $call), $file);
    }

    public function test_the_activation_asks_the_resolver_which_themes_it_competes_with(): void
    {
        $manager = $this->codeOf((string) file_get_contents(base_path('app/Services/Theme/ThemeManager.php')));

        $this->assertSame(1, substr_count($manager, 'app(StorefrontThemeResolver::class)->themesSharingStorefrontWith(theme: $theme)'));
    }

    public function test_no_file_reads_the_active_theme_except_the_resolver(): void
    {
        $offenders = [];
        foreach ($this->filesToScan() as $file) {
            $relative = str_replace(base_path() . '/', '', $file);
            if ($relative === self::THE_RESOLVER) {
                continue;
            }
            foreach ($this->directReads(source: (string) file_get_contents($file), isBlade: str_ends_with($file, '.blade.php')) as $statement) {
                $offenders[] = $relative . ': ' . $statement;
            }
        }

        $this->assertSame([], $offenders, "These files read the active theme without asking StorefrontThemeResolver:\n" . implode("\n", $offenders));
    }

    public function test_the_scan_reaches_the_files_it_claims_to_and_the_resolver_is_the_one_that_it_lets_through(): void
    {
        $files = array_map(static fn (string $file): string => str_replace(base_path() . '/', '', $file), $this->filesToScan());

        foreach (array_column(self::routedReaders(), 0) as $reader) {
            $this->assertContains($reader, $files);
        }
        $this->assertContains('routes/web/routes.php', $files);
        $this->assertContains('resources/views/admin-views/theme/index.blade.php', $files);
        $this->assertContains(self::THE_RESOLVER, $files);
        $this->assertGreaterThan(1000, count($files));
        $this->assertNotSame([], $this->directReads(source: (string) file_get_contents(base_path(self::THE_RESOLVER)), isBlade: false), 'the resolver is let through because it reads the active theme, not because the scan cannot see it');
    }

    /** @return array<string, array{0: string}> */
    public static function directReadsInEveryForm(): array
    {
        return [
            'the model query' => ["<?php\n\$theme = Theme::query()->where('is_active', true)->first();\n"],
            'the model with the namespace' => ["<?php\n\$id = \\App\\Models\\Theme::where('is_active', 1)->value('id');\n"],
            'the model with an array of conditions' => ["<?php\n\$theme = Theme::where(['is_active' => true])->first();\n"],
            'a query broken over several lines' => ["<?php\n\$theme = Theme::query()\n    ->with('versions')\n    ->where('is_active', true)\n    ->first();\n"],
            'a repository holding the model' => ["<?php\nreturn \$this->theme->newQuery()->where('is_active', true)->first();\n"],
            'the table' => ["<?php\n\$theme = DB::table('themes')->where('is_active', 1)->first();\n"],
            'a first-where shortcut' => ["<?php\n\$theme = Theme::firstWhere('is_active', true);\n"],
            'a dynamic where' => ["<?php\n\$theme = Theme::whereIsActive(true)->first();\n"],
            'a theme created active' => ["<?php\nTheme::create(['name' => 'Shop', 'is_active' => true]);\n"],
            'a view' => ["@php(\$theme = \\App\\Models\\Theme::where('is_active', true)->first())\n"],
        ];
    }

    #[DataProvider('directReadsInEveryForm')]
    public function test_the_scan_sees_a_direct_read(string $source): void
    {
        $this->assertNotSame([], $this->directReads(source: $source, isBlade: !str_starts_with($source, '<?php')));
    }

    /** @return array<string, array{0: string}> */
    public static function whatIsNotADirectRead(): array
    {
        return [
            'asking the resolver' => ["<?php\n\$theme = \$this->themes->activeTheme();\n"],
            'a theme created inactive' => ["<?php\nTheme::create(['name' => 'Shop', 'is_active' => false]);\n"],
            'the flag of a theme that is already loaded' => ["<?php\nreturn (bool) \$version->theme?->is_active;\n"],
            'another model with the same flag' => ["<?php\n\$gateways = Setting::where(['settings_type' => 'payment_config', 'is_active' => 1])->get();\n"],
            'a filter that takes the flag from the request' => ["<?php\n\$query = \$this->theme->with(\$relations)->when(isset(\$filters['is_active']), fn (\$q) => \$q->where(['is_active' => \$filters['is_active']]));\n"],
            'a sentence in a comment' => ["<?php\n// Theme::query()->where('is_active', true)->first() is what the readers used to do.\n/** \$this->theme->where('is_active', true) */\n"],
            'a sentence in a view comment' => ["{{-- Theme::where('is_active', true)->first() --}}\n"],
        ];
    }

    #[DataProvider('whatIsNotADirectRead')]
    public function test_the_scan_leaves_alone_what_is_not_a_direct_read(string $source): void
    {
        $this->assertSame([], $this->directReads(source: $source, isBlade: !str_starts_with($source, '<?php')));
    }

    /**
     * The statements of a source that read the active theme themselves.
     *
     * @return list<string>
     */
    private function directReads(string $source, bool $isBlade): array
    {
        $code = $isBlade ? (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source) : $this->codeOf($source);
        $statements = $isBlade ? [$code] : explode(';', $code);
        $reads = [];

        foreach ($statements as $statement) {
            if (preg_match(self::NAMES_THE_THEMES, $statement) === 1 && preg_match(self::HOLDS_THE_ACTIVE_FLAG, $statement) === 1) {
                $reads[] = trim((string) preg_replace('/\s+/', ' ', $statement));
            }
        }

        return $reads;
    }

    /** The source without its comments: what the scan looks at, so that a sentence about a read is not mistaken for one. */
    private function codeOf(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    /** @return list<string> */
    private function filesToScan(): array
    {
        $files = [];
        foreach (self::DIRECTORIES as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
