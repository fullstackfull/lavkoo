<?php

namespace Tests\Unit\Storefront;

use App\Models\CustomerSegment;
use App\Models\ExperienceCampaign;
use App\Models\ExperienceExperiment;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\CacheKeyScanner;
use Tests\TestCase;

/**
 * A1-15: the caches of data that will differ per market are either keyed per storefront or written down here as market-independent, with the
 * fact each verdict rests on.
 *
 * `ThemeCacheKeysAreStorefrontAwareTest` asks this of the theme code. The caches this one is about are written outside it -- the campaign,
 * experiment and segment resolvers, the banner rows and the auction home list the requirement names -- and nothing read them across the whole
 * of `app/` and `Modules/` before. A cache that is neither scoped nor declared would give one storefront the copy another storefront cached.
 *
 * Each verdict is checked twice over: the scan says what the code does today, and a second test pins the FACT the verdict rests on -- that
 * the rows behind a market-independent cache name no storefront. So the day a campaign, an experiment or a segment is given a market, this
 * test fails and says which cache has to be scoped.
 *
 * It reads text, not behaviour, through `tests/Support/CacheKeyScanner.php`, which proves itself on source written in the theme test. A key
 * built by a method the scanner cannot read is reported as a key held in a variable, and the families below are all built from literals.
 */
class MarketDependentCacheKeysTest extends TestCase
{
    /**
     * The caches A1-15 names, as the scanner writes their keys (`*` stands for what the code builds the key from), and the verdict each one
     * has today: `scoped` for the storefront's own key, or the reason no storefront has a copy of its own.
     *
     * @var array<string, string>
     */
    private const VERDICTS = [
        'commerce_campaign_overrides_*' => 'scoped',
        'commerce_experiments_*' => 'The running experiments of one page, read from rows that name no storefront (ExperienceExperiment has no such column; pinned below). An experiment names a section by its uuid, so only a theme holding that section is changed by it.',
        'commerce_experiment_reach' => 'How many visitors each experiment reached, from the analytics rollup, which has no storefront dimension. Read by the admin builder, which is bound to no storefront.',
        'commerce_segment_ctx_*' => 'What one customer belongs to, under that customer\'s id. A customer belongs to the platform, not to a market (the customer tables name no storefront, A2-01), so the answer is the same in every market they visit.',
        'commerce_segment_keys' => 'The names of the segments that exist, from rows that name no storefront (CustomerSegment has no such column; pinned below).',
        'commerce_segments_live' => 'The segments that are switched on, from the same rows.',
        'cache_banner_type_*_*' => 'scoped',
        'cache_banner_type_main_banner_*' => 'scoped',
        'cache_banner_type_sidebar_banner_*' => 'scoped',
        'cache_banner_type_top_side_banner_*' => 'scoped',
    ];

    /** The families the scan looks for: every key a file keeps that belongs to one of the caches above. */
    private const FAMILIES = '/^(commerce_campaign_overrides_|commerce_experiments_|commerce_experiment_reach|commerce_segment|cache_banner_type_)/';

    /** @var list<string> the roots the whole platform's own code lives in */
    private const ROOTS = ['app', 'Modules'];

    public function test_every_cache_of_the_named_data_is_either_scoped_or_declared(): void
    {
        $undeclared = [];
        $disagreeing = [];

        foreach ($this->keysAcrossThePlatform() as $key => $states) {
            $verdict = self::VERDICTS[$key] ?? null;

            if ($verdict === null) {
                $undeclared[] = $key . ' (' . implode(', ', array_keys($states['files'])) . ')';

                continue;
            }

            $isScoped = $verdict === 'scoped';

            if ($states['scoped'] !== $isScoped || $states['unscoped'] === $isScoped) {
                $disagreeing[] = sprintf(
                    '%s is declared %s, and the code keeps it %s',
                    $key,
                    $isScoped ? 'the storefront\'s own' : 'market-independent',
                    $states['scoped'] ? ($states['unscoped'] ? 'both ways' : 'scoped') : 'unscoped',
                );
            }
        }

        $this->assertSame([], $undeclared, "These caches hold data A1-15 names and are declared nowhere:\n" . implode("\n", $undeclared));
        $this->assertSame([], $disagreeing, "These declarations no longer describe the code:\n" . implode("\n", $disagreeing));
    }

    public function test_the_declarations_rest_on_rows_that_name_no_storefront(): void
    {
        foreach ([new ExperienceCampaign(), new ExperienceExperiment(), new CustomerSegment()] as $model) {
            $this->assertSame(
                [],
                preg_grep('/storefront|country|market/i', $model->getFillable()),
                $model::class . ' names a storefront now, so the cache that holds its rows is no longer market-independent',
            );
        }
    }

    /**
     * The auction home list the requirement names is in no cache of this tree.
     *
     * What exists is one `Cache::forget` of a key an absent add-on would write, under a vendor's id, so it names no market either way. The
     * add-on is not in `Modules/`, so there is nothing here to scope; this is written down so the next reader does not look for it.
     */
    public function test_the_auction_home_list_is_not_a_cache_of_this_tree(): void
    {
        $auctionKeys = [];

        foreach ($this->keysAcrossThePlatform(families: '/auction/i') as $key => $states) {
            $auctionKeys[$key] = array_keys($states['files']);
        }

        $this->assertSame(
            ['auction_vendor_has_products_*' => ['app/Utils/settings.php']],
            $auctionKeys,
            'an auction cache was added: decide whether it belongs to a market before it is kept',
        );
        $this->assertFalse(is_dir(base_path('Modules/Auction')), 'the auction module is in this tree now, so its caches are part of this question');
    }

    /**
     * Emptying the whole cache is coarse and never wrong: it takes away every market's copy, so no storefront is left holding another's.
     *
     * The sites are named so that one added later is noticed -- a flush in a request path would be a performance decision, and in an admin
     * action it is the cheap way to be sure a setting took effect everywhere.
     */
    public function test_the_places_that_empty_the_whole_cache_are_these(): void
    {
        $flushes = [];

        foreach ($this->sourceFiles() as $file) {
            $code = (string) file_get_contents($file);

            if (preg_match('/(?:Cache::|cache\(\)->)flush\(/', $code) === 1) {
                $flushes[] = $this->relative($file);
            }
        }

        sort($flushes);

        $this->assertSame([
            'app/Http/Controllers/Admin/Marketplace/CategoryGovernanceController.php',
            'app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php',
            'app/Http/Controllers/Admin/Marketplace/SellerVerificationController.php',
            'app/Http/Controllers/Admin/Marketplace/SlaController.php',
            'app/Http/Controllers/Admin/Settings/AbandonedCartSettingsController.php',
            'app/Http/Controllers/Admin/Settings/AuthenticationSecurityController.php',
            'app/Services/Platform/Policy.php',
        ], $flushes, 'a place that empties the whole cache was added or moved: it loses every market\'s copy, which is safe but worth knowing');
    }

    /**
     * Every key of the named families, across the platform's own code, with whether the code keeps it scoped, unscoped, or both.
     *
     * @return array<string, array{scoped: bool, unscoped: bool, files: array<string, true>}>
     */
    private function keysAcrossThePlatform(string $families = self::FAMILIES): array
    {
        $found = [];

        foreach ($this->sourceFiles() as $file) {
            $code = (string) file_get_contents($file);

            if (preg_match('/Cache::|cache\(\)/', $code) !== 1) {
                continue;
            }

            foreach ((new CacheKeyScanner($code, CacheKeyScanner::constantsIn($code)))->keys() as $call) {
                if (preg_match($families, $call['key']) !== 1) {
                    continue;
                }

                $found[$call['key']]['scoped'] = ($found[$call['key']]['scoped'] ?? false) || $call['storefront'];
                $found[$call['key']]['unscoped'] = ($found[$call['key']]['unscoped'] ?? false) || !$call['storefront'];
                $found[$call['key']]['files'][$this->relative($file)] = true;
            }
        }

        ksort($found);

        return $found;
    }

    /** @return list<string> every PHP file of the platform's own code */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (self::ROOTS as $root) {
            $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS));

            foreach ($directory as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $this->assertGreaterThan(2000, count($files), 'the scan reads the whole of app/ and Modules/, so a shrinking count means it stopped');

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }
}
