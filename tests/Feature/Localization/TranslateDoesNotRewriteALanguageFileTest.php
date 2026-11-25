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
    private const BASELINE = ['no_locale' => 1327, 'en' => 1352, 'sa' => 4206, 'ae' => 1524];

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
        'resources/views/admin-views/commerce',
        'resources/views/admin-views/theme',
    ];

    /** Where a literal key can be written. */
    private const TREES = ['app', 'resources/views', 'resources/themes', 'Modules'];

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
