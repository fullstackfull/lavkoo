<?php

namespace Tests\Unit\Storefront;

use App\Models\CustomerSegment;
use App\Models\ExperienceCampaign;
use App\Models\ExperienceExperiment;
use App\Services\Analytics\Reporting\AnalyticsReporting;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\CacheKeyScanner;
use Tests\TestCase;

/**
 * GCC F-04.5: every cache key the theme code reads, writes or forgets is either the storefront's own (it is run through StorefrontContext::scopedCacheKey, and a flush forgets the copy of every
 * storefront) or declared global below, with the reason. A key that is neither would give one storefront the copy another storefront cached; a key that is declared and that no code uses is a
 * declaration that went stale. Checked in the source of the theme code by the scanner in `tests/Support/CacheKeyScanner.php`, which proves itself on source written in this file.
 *
 * It is a lint, not a proof: a key built by a method it cannot read is reported as "(a key held in a variable)", and a cache held in a property (`$this->cache->get(...)`) is not seen at all. The
 * facts a declaration rests on (the rows behind a global cache name no storefront) are pinned by their own tests, so that adding a storefront to those rows makes this fail. One declaration rests on a
 * different fact and says so: the customer metrics are read over orders that DO name a storefront and a currency, and CustomerSegmentMetricsTest pins what that makes of the money among them.
 */
class ThemeCacheKeysAreStorefrontAwareTest extends TestCase
{
    /**
     * The keys that are global by nature, as the scanner writes them (`*` stands for what the code builds the key from), each with why no storefront has a copy of its own. None of them holds
     * anything that depends on the storefront of a request: their rows or events name no storefront, or what they hold is read where no storefront is bound.
     *
     * @var array<string, string>
     */
    private const GLOBAL_THEME_CACHE_KEYS = [
        // commerce_campaign_overrides_* was declared here while a campaign named no storefront. A campaign may now be limited to
        // storefronts, so CampaignResolver writes the key through StorefrontCatalog::cacheKey() and forget() clears every storefront's
        // copy -- it is the storefront's own key, not a global one, and the two tests below are what said so.

        'theme_publish_check_*' => 'The findings against one theme version, under the id of that version, which belongs to one theme and so to one storefront. They are asked in the admin panel and by theme:publish-due, which are bound to no storefront.',
        'theme_section_reach' => 'The visitors of each section over thirty days, from the analytics rollup, which has no storefront dimension (AnalyticsReporting::DIMENSIONS). Only the admin builder and the campaign screen read it, and the admin panel is bound to no storefront.',
        'commerce_experiments_*' => 'The running experiments of one page, read from experiments that name no storefront (ExperienceExperiment has no storefront column). An experiment names a section by its uuid, so only a theme that holds that section is changed by it.',
        'commerce_experiment_reach' => 'The visitors of each variant over thirty days, counted over analytics events that carry no storefront. Only the admin experiments screen reads it.',
        'commerce_segment_keys' => 'The keys of the live customer segments, read from segments that name no storefront (CustomerSegment has no storefront column).',
        'commerce_segments_live' => 'The live customer segments, read from segments that name no storefront (CustomerSegment has no storefront column).',
        'commerce_segment_ctx_*' => 'The order metrics of one customer, under the id of the customer: one customer identity serves the six storefronts, and the metrics are read over every order of the customer, whichever storefront took it. The orders they are read over DO name a storefront and a currency, so this one is global in spite of its rows and not because of them: the count, the recency and the registration age are the customer\'s own whichever storefront took the order, while total_spent is a plain SUM of order_amount over orders priced in different currencies and is therefore a figure in no one currency, which no rule should compare against a threshold while storefront currencies are on. Narrowing or converting it is an owner decision of its own, open beside decision 7; CustomerSegmentMetricsTest holds this declaration to what the code does.',
    ];

    /** Where the theme code lives: its services, the commerce services that dress its pages and the screens that edit them. */
    private const DIRECTORIES = ['app/Services/Theme', 'app/Services/Commerce', 'app/Http/Controllers/Admin/Commerce'];

    /** What the name of a file anywhere under app/ says it belongs to the theme code: its controllers, models, commands, repository and provider. */
    private const FILE_NAMES = '/^(Theme|Experience|Experiment|Campaign|CommerceCampaigns|AppBuilder|CustomerSegment)[A-Za-z]*\.php$/';

    /** Files of the theme code that are in neither of the above. */
    private const FILES = ['app/Utils/theme-helpers.php'];

    /** Where the application keeps code that could name a cache key. */
    private const WHOLE_APPLICATION = ['app', 'Modules', 'routes', 'config', 'database'];

    /** A key that says it belongs to the theme code, wherever it is kept. */
    private const NAMES_A_THEME_CACHE = '/theme|campaign|experiment|experience|segment|section_reach/i';

    /**
     * Keys the scan must find in the theme code as the storefront's own, by the file that makes the calls. The day a file stops being read, or one of these keys stops being scoped, this fails.
     *
     * @var array<string, list<string>>
     */
    private const STOREFRONT_KEYS_OF_THE_THEME_CODE = [
        'app/Services/Theme/StorefrontThemeRenderer.php' => ['storefront_theme_page_*', 'storefront_theme_global_settings'],
        'app/Services/Theme/ThemeDelivery.php' => ['theme_delivery_revision', 'theme_delivery_*_*'],
    ];

    /** @var array<string, list<array{method: string, key: string, storefront: bool}>>|null */
    private static ?array $callsOfTheThemeCode = null;

    /** @var array<string, array<string, string>>|null */
    private static ?array $constantsOfTheApplication = null;

    public function test_every_cache_key_of_the_theme_code_is_the_storefronts_own_or_declared_global(): void
    {
        $undeclared = [];
        foreach ($this->callsOfTheThemeCode() as $file => $calls) {
            foreach ($this->keysThatAreNeither(calls: $calls, declared: self::GLOBAL_THEME_CACHE_KEYS) as $call) {
                $undeclared[] = "{$file}: Cache::{$call}";
            }
        }

        $this->assertSame([], $undeclared, "These cache keys are not the storefront's own and are not declared global in GLOBAL_THEME_CACHE_KEYS:\n" . implode("\n", $undeclared));
    }

    public function test_every_declared_global_key_is_one_the_code_uses(): void
    {
        $unused = $this->declarationsNoCallUses(calls: $this->everyCallOfTheThemeCode(), declared: self::GLOBAL_THEME_CACHE_KEYS);

        $this->assertSame([], $unused, 'These declared keys are used by no code: ' . implode(', ', $unused));
    }

    public function test_no_declared_global_key_is_also_written_under_a_storefront(): void
    {
        $both = $this->declarationsAlsoScoped(calls: $this->everyCallOfTheThemeCode(), declared: self::GLOBAL_THEME_CACHE_KEYS);

        $this->assertSame([], $both, 'These keys are declared global and are also run through the storefront: one of the two is wrong.');
    }

    public function test_the_scan_reads_the_theme_code_and_finds_the_keys_that_are_the_storefronts_own(): void
    {
        $calls = $this->callsOfTheThemeCode();
        $files = array_map(fn (string $file): string => $this->relative($file), $this->filesOfTheThemeCode());

        foreach (self::STOREFRONT_KEYS_OF_THE_THEME_CODE as $file => $keys) {
            $own = array_column(array_filter($calls[$file] ?? [], static fn (array $call): bool => $call['storefront']), 'key');

            foreach ($keys as $key) {
                $this->assertContains($key, $own, "{$file} is not read, or its key {$key} is no longer the storefront's own");
            }
        }
        foreach (['app/Services/Theme/SectionReach.php', 'app/Services/Theme/PublishValidator.php', 'app/Services/Commerce/CampaignResolver.php', 'app/Services/Commerce/ExperimentResolver.php', 'app/Services/Commerce/SegmentResolver.php', 'app/Http/Controllers/Admin/Commerce/ExperimentController.php'] as $file) {
            $this->assertArrayHasKey($file, $calls, "{$file} is not read, or names no key any more");
        }
        foreach (['app/Utils/theme-helpers.php', 'app/Models/ThemeVersion.php', 'app/Console/Commands/ThemePublishDue.php', 'app/Http/Controllers/Admin/Settings/ThemeSettingsController.php'] as $file) {
            $this->assertContains($file, $files, "{$file} is not among the files of the theme code");
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> a file of the theme code, the call as it is written there with the storefront in its key and without it, and the key the scan must then find to be neither the storefront's own nor declared */
    public static function callsThatCouldLoseTheirStorefront(): array
    {
        return [
            'the revision the app asks for' => ['app/Services/Theme/ThemeDelivery.php', "StorefrontContext::current()->scopedCacheKey(self::CACHE_PREFIX . 'revision')", "self::CACHE_PREFIX . 'revision'", 'remember(theme_delivery_revision)'],
            'the page the website draws' => ['app/Services/Theme/StorefrontThemeRenderer.php', 'StorefrontContext::current()->scopedCacheKey(self::CACHE_KEY_PREFIX . $page)', 'self::CACHE_KEY_PREFIX . $page', 'remember(storefront_theme_page_*)'],
            'the colours of the page head' => ['app/Services/Theme/StorefrontThemeRenderer.php', 'StorefrontContext::current()->scopedCacheKey(self::GLOBAL_SETTINGS_CACHE_KEY)', 'self::GLOBAL_SETTINGS_CACHE_KEY', 'remember(storefront_theme_global_settings)'],
        ];
    }

    #[DataProvider('callsThatCouldLoseTheirStorefront')]
    public function test_a_key_of_the_theme_code_is_found_when_its_storefront_is_taken_out_of_the_real_source(string $file, string $scoped, string $plain, string $found): void
    {
        $source = (string) file_get_contents(base_path($file));

        $mutated = str_replace($scoped, $plain, $source, $replaced);

        $this->assertSame(1, $replaced, 'the call is written another way now, and this test has to be told how');
        $this->assertSame([], $this->keysThatAreNeither(calls: $this->scan(code: $source, constants: $this->constantsOfTheApplication()), declared: self::GLOBAL_THEME_CACHE_KEYS));
        $this->assertSame([$found], $this->keysThatAreNeither(calls: $this->scan(code: $mutated, constants: $this->constantsOfTheApplication()), declared: self::GLOBAL_THEME_CACHE_KEYS));
    }

    public function test_no_file_outside_the_theme_code_keeps_a_cache_key_that_names_a_theme(): void
    {
        $inTheThemeCode = array_map(fn (string $file): string => $this->relative($file), $this->filesOfTheThemeCode());
        $constants = $this->constantsOfTheApplication();
        $strays = [];

        foreach ($this->filesThatMentionTheCache(directories: self::WHOLE_APPLICATION) as $file) {
            if (in_array($this->relative($file), $inTheThemeCode, true)) {
                continue;
            }
            foreach ($this->scan(code: (string) file_get_contents($file), constants: $constants) as $call) {
                if (preg_match(self::NAMES_A_THEME_CACHE, $call['key']) === 1) {
                    $strays[] = $this->relative($file) . ": Cache::{$call['method']}({$call['key']})";
                }
            }
        }

        $this->assertSame([], $strays, "These keys name a theme and are kept by a file the scan of the theme code does not read:\n" . implode("\n", $strays));
    }

    public function test_the_rows_and_events_behind_the_global_caches_name_no_storefront(): void
    {
        foreach ([new ExperienceCampaign(), new ExperienceExperiment(), new CustomerSegment()] as $model) {
            $this->assertSame([], preg_grep('/storefront|country|market/i', $model->getFillable()), $model::class . ' names a storefront: the cache that holds its rows is no longer global');
        }
        $this->assertSame([], preg_grep('/storefront|market/i', AnalyticsReporting::DIMENSIONS), 'the analytics rollup has a storefront dimension: the reach caches are no longer global');
    }

    // ---- the scan proves itself on source written here ---------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: list<array{0: string, 1: string, 2: bool}>}> a source and the calls the scan must find in it: the method, the key and whether it is the storefront's own */
    public static function sourcesAndTheirKeys(): array
    {
        $flush = <<<'PHP'
            class Pages {
                private const PAGES = 'pages_';
                private const SETTINGS = 'settings';

                public function flush(?array $storefronts = null): void
                {
                    $keysOf = static fn (string $key): array => $storefronts === null ? StorefrontContext::keysForEveryStorefront($key) : StorefrontContext::keysForStorefronts(countries: $storefronts, key: $key);

                    foreach ($pages as $page) {
                        foreach ($keysOf(self::PAGES . $page) as $key) {
                            Cache::forget($key);
                        }
                    }
                    foreach ($keysOf(self::SETTINGS) as $key) {
                        Cache::forget($key);
                    }
                }
            }
            PHP;

        return [
            'a plain key' => ["Cache::remember('theme_section_reach', 300, fn () => 1);", [['remember', 'theme_section_reach', false]]],
            'a key with a part that is built' => ["Cache::forget('commerce_experiments_' . \$page);", [['forget', 'commerce_experiments_*', false]]],
            'a key of a constant and a variable' => ["class Reader { private const PREFIX = 'theme_x_'; public function read(\$page) { return Cache::remember(self::PREFIX . \$page, 60, fn () => 1); } }", [['remember', 'theme_x_*', false]]],
            'a key in double quotes' => ['Cache::put("theme_{$id}_x", 1);', [['put', 'theme_*_x', false]]],
            'a key of the storefront' => ["Cache::remember(StorefrontContext::current()->scopedCacheKey('theme_page_' . \$page), 600, fn () => 1);", [['remember', 'theme_page_*', true]]],
            'a key of the storefront on several lines, as the delivery writes it' => ["Cache::remember(\n    StorefrontContext::current()->scopedCacheKey(\n        'theme_delivery_' . \$page . '_' . \$this->fingerprint(\$viewer) . \$this->campaignKeyPart(\$page),\n    ),\n    600,\n    fn () => 1,\n);", [['remember', 'theme_delivery_*_*', true]]],
            'a key of the storefront from the catalogue' => ["Cache::remember(StorefrontCatalog::cacheKey('products_max'), 600, fn () => 1);", [['remember', 'products_max', true]]],
            'a flush that forgets the key of every storefront' => [$flush, [['forget', 'pages_*', true], ['forget', 'settings', true]]],
            'a variable given the key of the storefront' => ["\$key = StorefrontContext::current()->scopedCacheKey('scoped_key');\nCache::forget(\$key);", [['forget', 'scoped_key', true]]],
            'a variable given a plain key' => ["\$key = 'plain_key';\nCache::forget(\$key);", [['forget', 'plain_key', false]]],
            'a variable given a plain key and then the key of the storefront' => ["\$key = 'plain_key';\nCache::forget(\$key);\n\$key = StorefrontContext::current()->scopedCacheKey('scoped_key');\nCache::forget(\$key);", [['forget', 'plain_key', false], ['forget', 'scoped_key', true]]],
            'a key from a variable that holds its first part' => ["\$prefix = 'p_';\nCache::forget(\$prefix . \$id);", [['forget', 'p_*', false]]],
            'a key handed to a closure' => ["function warm(string \$page): void { \$key = 'warm_' . \$page; \$build = function () use (\$key) { return Cache::remember(\$key, 60, fn () => 1); }; }", [['remember', 'warm_*', false]]],
            'a key that is a parameter' => ['function drop(string $key): void { Cache::forget($key); }', [['forget', CacheKeyScanner::UNKNOWN_KEY, false]]],
            'a key built by a method' => ['Cache::remember($this->keyFor($page), 60, fn () => 1);', [['remember', CacheKeyScanner::UNKNOWN_KEY, false]]],
            'arguments that are named' => ["Cache::remember(ttl: 60, key: 'named_key', callback: fn () => 1);", [['remember', 'named_key', false]]],
            'the facade by its full name' => ["\\Illuminate\\Support\\Facades\\Cache::forget('qualified_' . \$id);", [['forget', 'qualified_*', false]]],
            'the helper' => ["cache()->forget('helper_key');", [['forget', 'helper_key', false]]],
            'a store picked first' => ["Cache::store('redis')->put('stored_key', 1, 60);\nCache::tags(['theme'])->remember('tagged_key', 60, fn () => 1);", [['put', 'stored_key', false], ['remember', 'tagged_key', false]]],
            'every call that names a key' => ["Cache::put('k', 1);\nCache::add('k', 1);\nCache::forever('k', 1);\nCache::get('k');\nCache::has('k');\nCache::pull('k');\nCache::forget('k');\nCache::rememberForever('k', fn () => 1);\nCache::missing('k');\nCache::increment('k');\nCache::decrement('k');", array_map(static fn (string $method): array => [$method, 'k', false], ['put', 'add', 'forever', 'get', 'has', 'pull', 'forget', 'rememberForever', 'missing', 'increment', 'decrement'])],
        ];
    }

    /** @param list<array{0: string, 1: string, 2: bool}> $expected */
    #[DataProvider('sourcesAndTheirKeys')]
    public function test_the_scan_finds_the_keys_of_a_source_and_whether_each_is_the_storefronts_own(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->rowsOf(code: $source));
    }

    public function test_the_scan_reads_a_key_built_from_the_constant_of_another_class(): void
    {
        $constants = CacheKeyScanner::constantsIn(code: "class Other { public const KEY = 'other_key'; private const TYPED = 'typed_key'; public const NUMBER = 5; }");

        $this->assertSame(['Other' => ['KEY' => 'other_key', 'TYPED' => 'typed_key']], $constants);
        $this->assertSame([['forget', 'other_key', false]], $this->rowsOf(code: 'Cache::forget(Other::KEY);', constants: $constants));
        $this->assertSame([['forget', CacheKeyScanner::UNKNOWN_KEY, false]], $this->rowsOf(code: 'Cache::forget(Unknown::KEY);', constants: $constants));
    }

    /** @return array<string, array{0: string}> */
    public static function sourcesThatNameNoKey(): array
    {
        return [
            'a comment' => ["// Cache::remember('commented', 60, fn () => 1);\n/* Cache::forget('commented'); */\n"],
            'a doc comment' => ["/** Cache::put('commented', 1) */\nfunction nothing() {}\n"],
            'a sentence in a string' => ["\$sentence = 'Cache::remember(x) is how it is cached';\n"],
            'a flush, which names no key' => ['Cache::flush();'],
            'another facade' => ["Session::get('theme_x');\nConfig::get('theme_x');"],
            'a class whose name ends in Cache' => ["ThemeCache::remember('theme_x', 1, fn () => 1);"],
            'a method of that name on something else' => ["\$request->get('theme_x');\n\$this->cache->get('theme_x');"],
            'the import of the facade' => ["use Illuminate\\Support\\Facades\\Cache;\n"],
            'a function that is called cache and takes a key' => ["cache('theme_x');"],
            'the helper with nothing after it' => ["cache() && has('theme_x');"],
        ];
    }

    #[DataProvider('sourcesThatNameNoKey')]
    public function test_the_scan_leaves_alone_what_names_no_key_of_the_cache(string $source): void
    {
        $this->assertSame([], $this->scan(code: $source));
    }

    public function test_a_key_that_is_neither_the_storefronts_own_nor_declared_is_found(): void
    {
        $calls = $this->scan(code: "Cache::remember('theme_new_cache', 60, fn () => 1);\nCache::remember(StorefrontContext::current()->scopedCacheKey('theme_scoped'), 60, fn () => 1);\nCache::forget('commerce_experiments_' . \$page);");

        $this->assertSame(['remember(theme_new_cache)'], $this->keysThatAreNeither(calls: $calls, declared: ['commerce_experiments_*' => 'a reason']));
    }

    public function test_a_key_of_the_storefront_that_loses_its_storefront_is_found(): void
    {
        $before = $this->scan(code: "Cache::remember(StorefrontContext::current()->scopedCacheKey('theme_delivery_revision'), 600, fn () => 1);");
        $after = $this->scan(code: "Cache::remember('theme_delivery_revision', 600, fn () => 1);");

        $this->assertSame([], $this->keysThatAreNeither(calls: $before, declared: []));
        $this->assertSame(['remember(theme_delivery_revision)'], $this->keysThatAreNeither(calls: $after, declared: []));
    }

    public function test_a_forget_of_a_plain_key_for_a_key_that_is_scoped_elsewhere_is_found(): void
    {
        $calls = $this->scan(code: "Cache::remember(StorefrontContext::current()->scopedCacheKey('theme_delivery_revision'), 600, fn () => 1);\nCache::forget('theme_delivery_revision');");

        $this->assertSame(['forget(theme_delivery_revision)'], $this->keysThatAreNeither(calls: $calls, declared: []));
    }

    public function test_a_declaration_that_no_code_uses_is_found(): void
    {
        $calls = $this->scan(code: "Cache::remember('theme_section_reach', 60, fn () => 1);");

        $this->assertSame(['gone_key'], $this->declarationsNoCallUses(calls: $calls, declared: ['theme_section_reach' => 'a reason', 'gone_key' => 'a reason']));
    }

    public function test_a_declaration_for_a_key_that_is_also_the_storefronts_own_is_found(): void
    {
        $calls = $this->scan(code: "Cache::remember('theme_section_reach', 60, fn () => 1);\nCache::remember(StorefrontContext::current()->scopedCacheKey('theme_section_reach'), 60, fn () => 1);");

        $this->assertSame(['theme_section_reach'], $this->declarationsAlsoScoped(calls: $calls, declared: ['theme_section_reach' => 'a reason']));
    }

    // ---- what the checks above ask of the calls the scan finds -------------------------------------------------------------------------------

    /**
     * The calls whose key is not the storefront's own and is not declared.
     *
     * @param list<array{method: string, key: string, storefront: bool}> $calls
     * @param array<string, string> $declared
     * @return list<string>
     */
    private function keysThatAreNeither(array $calls, array $declared): array
    {
        $neither = array_filter($calls, static fn (array $call): bool => !$call['storefront'] && !array_key_exists($call['key'], $declared));

        return array_values(array_map(static fn (array $call): string => "{$call['method']}({$call['key']})", $neither));
    }

    /**
     * @param list<array{method: string, key: string, storefront: bool}> $calls
     * @param array<string, string> $declared
     * @return list<string> the declared keys that no call that is not the storefront's own uses
     */
    private function declarationsNoCallUses(array $calls, array $declared): array
    {
        $used = array_column(array_filter($calls, static fn (array $call): bool => !$call['storefront']), 'key');

        return array_values(array_diff(array_keys($declared), $used));
    }

    /**
     * @param list<array{method: string, key: string, storefront: bool}> $calls
     * @param array<string, string> $declared
     * @return list<string> the declared keys that some call runs through the storefront
     */
    private function declarationsAlsoScoped(array $calls, array $declared): array
    {
        $scoped = array_column(array_filter($calls, static fn (array $call): bool => $call['storefront']), 'key');

        return array_values(array_intersect(array_keys($declared), $scoped));
    }

    // ---- the source of the application --------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, array<string, string>> $constants
     * @return list<array{method: string, key: string, storefront: bool}>
     */
    private function scan(string $code, array $constants = []): array
    {
        return (new CacheKeyScanner(code: $code, constants: $constants))->keys();
    }

    /**
     * @param array<string, array<string, string>> $constants
     * @return list<array{0: string, 1: string, 2: bool}> the method, the key and whether it is the storefront's own, of each call the scan finds
     */
    private function rowsOf(string $code, array $constants = []): array
    {
        return array_map(static fn (array $call): array => [$call['method'], $call['key'], $call['storefront']], $this->scan(code: $code, constants: $constants));
    }

    /** @return list<array{method: string, key: string, storefront: bool}> */
    private function everyCallOfTheThemeCode(): array
    {
        return array_merge(...array_values($this->callsOfTheThemeCode()));
    }

    /** @return array<string, list<array{method: string, key: string, storefront: bool}>> the calls that name a cache key in each file of the theme code that names one, by its path from the root of the application */
    private function callsOfTheThemeCode(): array
    {
        if (self::$callsOfTheThemeCode === null) {
            $constants = $this->constantsOfTheApplication();
            self::$callsOfTheThemeCode = [];
            foreach ($this->filesOfTheThemeCode() as $file) {
                $calls = $this->scan(code: (string) file_get_contents($file), constants: $constants);
                if ($calls !== []) {
                    self::$callsOfTheThemeCode[$this->relative($file)] = $calls;
                }
            }
        }

        return self::$callsOfTheThemeCode;
    }

    /** @return array<string, array<string, string>> the string constants of every class that mentions the cache, so that a key built from another class's constant is read */
    private function constantsOfTheApplication(): array
    {
        if (self::$constantsOfTheApplication === null) {
            self::$constantsOfTheApplication = [];
            foreach ($this->filesThatMentionTheCache(directories: ['app', 'Modules']) as $file) {
                self::$constantsOfTheApplication = [...self::$constantsOfTheApplication, ...CacheKeyScanner::constantsIn(code: (string) file_get_contents($file))];
            }
        }

        return self::$constantsOfTheApplication;
    }

    /** @return list<string> */
    private function filesOfTheThemeCode(): array
    {
        $files = array_map(static fn (string $file): string => base_path($file), self::FILES);
        foreach ($this->phpFilesUnder(directories: self::DIRECTORIES) as $file) {
            $files[] = $file;
        }
        foreach ($this->phpFilesUnder(directories: ['app']) as $file) {
            if (preg_match(self::FILE_NAMES, basename($file)) === 1) {
                $files[] = $file;
            }
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function filesThatMentionTheCache(array $directories): array
    {
        return array_values(array_filter($this->phpFilesUnder(directories: $directories), static fn (string $file): bool => stripos((string) file_get_contents($file), 'cache') !== false));
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private function phpFilesUnder(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }
}
