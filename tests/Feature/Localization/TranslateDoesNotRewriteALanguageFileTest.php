<?php

namespace Tests\Feature\Localization;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A key defined in no locale is a FILE WRITE on the request path.
 *
 * `translate()` does not fall back quietly. When it meets a key the shop's locale does not hold it appends a humanised English
 * placeholder to `resources/lang/<locale>/new-messages.php` (`App\Utils\language.php`, `getOrPutTranslateMessageValueByKey`), then
 * invalidates OPcache for that file. So a screen whose keys are missing rewrites a language file every time it renders, in production,
 * with whatever file permissions the deployment left behind -- and in the test suite it leaves the repository dirty, which
 * `RestoresLanguageFiles` then reverts, so an edit made to `resources/lang` while a suite runs disappears.
 *
 * **That dirtiness is the detector**, and it is how the pair found on 2026-11-25 was found: a `git stash push -u` over a supposedly
 * clean tree captured two added lines in `resources/lang/en/new-messages.php`, which was `translate()` having invented them during a
 * test run. This test is that detector made deterministic -- read statically from the source rather than from whichever screens a
 * suite happened to render.
 *
 * Two halves, deliberately different in strength:
 *
 *  - **the surfaces this programme owns must be at zero**, and are. A key added to them without its three locales fails here with its
 *    own name, which is the only thing that stops the class coming back one key at a time.
 *  - **the inherited platform is a RATCHET, not a pass.** The figures below are the state of the upstream application and they are
 *    large: of 11,972 distinct literal keys, 1,327 are defined in NO locale, and the Saudi locale -- the locale this platform actually
 *    runs -- is short of 4,206. Fixing those is a programme of its own and is not pretended to be done here. What this does is stop
 *    the number growing: anyone who adds a key without its locales pushes a count past its baseline and is told which keys to look at.
 *
 * The figures are expected to FALL as the backlog is worked, and lowering them here is the whole point of the `<=`; a run that finds
 * fewer than the baseline is green and the baseline should then be lowered to the new figure.
 */
class TranslateDoesNotRewriteALanguageFileTest extends TestCase
{
    /** The locales a GCC market is served in. */
    private const LOCALES = ['en', 'sa', 'ae'];

    /**
     * The inherited upstream backlog, measured on this tree. Lower these when the backlog is worked; never raise them.
     *
     * `no_locale` is the worst case -- the key is absent everywhere, so every shop writes a file for it. The per-locale figures are
     * what one shop writes: a Saudi shop is short of `sa`, and that is the figure with the operational weight.
     */
    private const BASELINE = ['no_locale' => 1311, 'en' => 1336, 'sa' => 4172, 'ae' => 1505];

    /**
     * The surfaces this programme wrote or extended, which must hold no such key at all.
     *
     * Kept apart from `CouponNotificationCheckoutAndStorefrontAreTranslatedTest`'s own list on purpose: that test also demands ARABIC
     * in the Arabic locales, which the upstream screens below could not pass yet. This one asks only the cheaper question -- does the
     * key exist at all -- so it can cover more ground without being blocked on 4,206 translations.
     */
    private const OWNED = [
        'app/Services/Storefront',
        'app/Services/Notification',
        'app/Services/Marketing',
        'app/Services/Theme',
        'app/Services/Commerce',
        'app/Services/Catalog',
        'app/Services/Staff',
        'app/Services/Fulfillment',
        'app/Services/Shipping',
        'app/Services/Returns',
        'app/Services/PlatformAdmin',
        'app/Http/Controllers/Admin/Storefront',
        'app/Http/Controllers/Admin/Notification',
        'app/Http/Controllers/Admin/Commerce',
        'resources/views/admin-views/storefronts',
        'resources/views/admin-views/notification',
        'resources/views/admin-views/notification-accounts',
        'app/Http/Controllers/Admin/CartMarketingController.php',
        'resources/views/admin-views/integrations/cart-marketing.blade.php',
        'resources/views/admin-views/commerce',
        'resources/views/admin-views/theme',
    ];

    /** Where a literal key can be written. */
    private const TREES = ['app', 'resources/views', 'resources/themes', 'Modules'];

    /**
     * Files whose message keys travel as VALUES rather than as the argument of `translate()`.
     *
     * The scan above cannot see these, and that blind spot cost 48 keys. The theme builder's readiness panel and its publish check hand
     * a `reason_key` and a `fix_key` around as data and the Blade renders `translate($finding['reason_key'])`, so every one of them is a
     * string an operator reads and NONE of them looks like a key to a scanner that matches `translate('literal')`. Measured when the
     * coordinator sent three of them over: all 48 were missing from at least one locale, which is the whole diagnostic vocabulary a
     * merchant reads before publishing a storefront, rewriting a language file as it rendered.
     *
     * Read as "every sentence-shaped literal in these files is a message key", which is true of them and is the only rule a scanner can
     * apply to a value whose use is three files away.
     */
    private const KEYS_THAT_TRAVEL_AS_VALUES = [
        'app/Services/Theme/ComponentCapabilityRegistry.php',
        'app/Services/Theme/SectionReadiness.php',
        'app/Services/Theme/PublishValidator.php',
    ];

    /**
     * The sentence-shaped literals in those files that are NOT message keys, each with why.
     *
     * Six are section TYPES, which name a renderer rather than a sentence; two are internals. A list of exemptions is a list of excuses,
     * so it is short, it is specific, and anything added to those files is covered by default.
     */
    private const NOT_A_MESSAGE_KEY = [
        // Section types: the identifier of a renderer. The label an operator reads comes from the registry's own `label` field.
        'brand_showcase', 'recently_viewed', 'trending_searches', 'interest_tiles', 'vendor_slider', 'shipping_cutoff',
        // The prefix of an audit action, and a column name.
        'theme_publish_check_', 'theme_version_id',
    ];

    /** @var array<string, array<string, string>> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::LOCALES as $locale) {
            $this->catalogue[$locale] = array_merge(
                include base_path("resources/lang/{$locale}/messages.php"),
                include base_path("resources/lang/{$locale}/new-messages.php"),
            );
        }
    }

    public function test_no_key_of_a_surface_this_programme_owns_is_missing_from_any_locale(): void
    {
        $keys = $this->keysUnder(self::OWNED);

        $this->assertGreaterThan(400, count($keys), 'the scan found far fewer keys than these surfaces hold: a path has moved');

        foreach (self::LOCALES as $locale) {
            $missing = $this->keysMissingFrom($keys, $locale);

            $this->assertSame([], $missing, "these keys would make translate() rewrite resources/lang/{$locale}: " . implode(', ', $missing));
        }
    }

    /**
     * The theme builder's readiness and publish-check vocabulary exists in all three locales.
     *
     * Zero, not a ratchet: these are the sentences a merchant reads to find out why a section will not appear, and this programme owns
     * every one of them.
     */
    public function test_every_message_key_that_travels_as_a_value_exists_in_all_three_locales(): void
    {
        $keys = $this->sentenceShapedLiteralsIn(self::KEYS_THAT_TRAVEL_AS_VALUES);

        $this->assertGreaterThan(40, count($keys), 'the scan found far fewer keys than these files hold: a file has moved or a shape changed');

        foreach (self::LOCALES as $locale) {
            $missing = $this->keysMissingFrom($keys, $locale);

            $this->assertSame([], $missing, "a reason or fix key the builder renders is missing from resources/lang/{$locale}: " . implode(', ', $missing));
        }
    }

    /**
     * Every literal in these files long enough to be a sentence rather than an identifier, minus the ones that are not keys.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function sentenceShapedLiteralsIn(array $paths): array
    {
        $keys = [];

        foreach ($this->filesUnder($paths) as $file) {
            if (preg_match_all('/\x27([a-z][a-z0-9_]{12,})\x27/', (string) file_get_contents($file), $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $key) {
                if (!in_array($key, self::NOT_A_MESSAGE_KEY, true)) {
                    $keys[$key] = true;
                }
            }
        }

        $found = array_keys($keys);
        sort($found);

        return $found;
    }

    public function test_the_number_of_keys_defined_in_no_locale_at_all_has_not_grown(): void
    {
        $keys = $this->keysUnder(self::TREES);
        $nowhere = array_values(array_filter(
            $keys,
            fn (string $key): bool => !array_key_exists($key, $this->catalogue['en'])
                && !array_key_exists($key, $this->catalogue['sa'])
                && !array_key_exists($key, $this->catalogue['ae']),
        ));

        $this->assertLessThanOrEqual(
            self::BASELINE['no_locale'],
            count($nowhere),
            'a key defined in no locale was added. Every shop writes a language file for it on the request that renders it. '
            . 'The whole set is: ' . implode(', ', $nowhere),
        );
    }

    public function test_the_number_of_keys_each_locale_is_short_of_has_not_grown(): void
    {
        $keys = $this->keysUnder(self::TREES);

        foreach (self::LOCALES as $locale) {
            $missing = $this->keysMissingFrom($keys, $locale);

            $this->assertLessThanOrEqual(
                self::BASELINE[$locale],
                count($missing),
                "a key missing from resources/lang/{$locale} was added, so a shop in that locale now rewrites that file. "
                . 'The whole set is: ' . implode(', ', $missing),
            );
        }
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function keysMissingFrom(array $keys, string $locale): array
    {
        return array_values(array_filter($keys, fn (string $key): bool => !array_key_exists($key, $this->catalogue[$locale])));
    }

    /**
     * Every key written as a literal inside a `translate()` call under these paths.
     *
     * A key built from a value is not seen here and is not meant to be: `translate($status)` is enumerated against the enum it comes
     * from, in that feature's own test.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function keysUnder(array $paths): array
    {
        $keys = [];

        foreach ($this->filesUnder($paths) as $file) {
            if (preg_match_all('/translate\(\s*([\'"])([A-Za-z][A-Za-z0-9_\/]*)\1\s*\)/', (string) file_get_contents($file), $matches) === 0) {
                continue;
            }

            foreach ($matches[2] as $key) {
                // A key ending in an underscore is half a sentence the caller completes with a value, so it is not a key of its own.
                if (!str_ends_with($key, '_')) {
                    $keys[$key] = true;
                }
            }
        }

        $found = array_keys($keys);
        sort($found);

        return $found;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function filesUnder(array $paths): array
    {
        $files = [];

        foreach ($paths as $relative) {
            $path = base_path($relative);

            $this->assertFileExists($path, "a source path of this test has moved: {$relative}");

            if (is_file($path)) {
                $files[] = $path;
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
