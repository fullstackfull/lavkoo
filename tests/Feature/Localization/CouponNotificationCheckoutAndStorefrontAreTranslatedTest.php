<?php

namespace Tests\Feature\Localization;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * I1-11: every phrase the coupon, notification, checkout and storefront flows show exists in en, sa and ae, with ARABIC TEXT in the two
 * Arabic locales.
 *
 * `translate()` silently writes a humanised English placeholder into `resources/lang/{locale}/new-messages.php` the first time it meets an
 * unknown key, so a missing translation never fails a page: it shows English in an Arabic store and leaves the repository dirty. A key that
 * is DEFINED but holds English in an Arabic locale is worse, because nothing anywhere notices it -- and the Emirati locale held English for
 * 186 of this set's 299 keys.
 *
 * Both halves are asserted. The second is the one that was never checked, and the fix for most of it was not to invent wording: the two
 * Arabic locales should say the same thing unless a market needs different words, and 169 of those 186 keys already had Arabic in the SAUDI
 * locale, so the Emirati locale now takes it. Reusing the text the repository already holds is better than writing 169 new strings.
 *
 * What this does NOT claim: that the Arabic is approved. Whether each phrase is the right phrase for a customer in each market is I1-11's
 * other criterion and needs a native reader; this asserts that there IS Arabic, which is the half a test can decide.
 */
class CouponNotificationCheckoutAndStorefrontAreTranslatedTest extends TestCase
{
    private const LOCALES = ['en', 'sa', 'ae'];

    private const ARABIC_LOCALES = ['sa', 'ae'];

    /** The files that carry these four flows' user-visible text. */
    private const SOURCES = [
        // The storefront flow: the markets, their lifecycle, their legal texts and everything that reads a storefront.
        'app/Services/Storefront',
        'app/Http/Controllers/Admin/Storefront',
        'resources/views/admin-views/storefronts',
        // The notification flow: the accounts per market, the broadcast and the marketing consent.
        'app/Services/Notification',
        'app/Services/Marketing',
        'app/Http/Controllers/Admin/Notification',
        'app/Http/Requests/Admin/Notification',
        'resources/views/admin-views/notification',
        'resources/views/admin-views/notification-accounts',
        // The campaign and merchandising flow: the markets a campaign runs in, and everything else the commerce screens say.
        'app/Services/Commerce',
        'app/Http/Controllers/Admin/Commerce',
        'resources/views/admin-views/commerce',
        // The coupon flow.
        'app/Http/Controllers/Admin/Promotion/CouponController.php',
        'resources/views/admin-views/coupon',
        // The checkout flow.
        'app/Services/Checkout',
        'resources/themes/default/web-views/checkout',
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

    public function test_every_key_these_flows_use_exists_in_english_and_in_both_arabic_locales(): void
    {
        $keys = $this->keysTheseFlowsUse();

        $this->assertGreaterThan(250, count($keys), 'the scan found far fewer keys than these flows hold: a source path has moved');

        foreach (self::LOCALES as $locale) {
            $missing = array_values(array_filter($keys, fn (string $key): bool => !array_key_exists($key, $this->catalogue[$locale])));

            $this->assertSame([], $missing, "keys missing from resources/lang/{$locale}: " . implode(', ', $missing));
        }
    }

    public function test_every_key_these_flows_use_holds_arabic_in_the_arabic_locales(): void
    {
        $keys = $this->keysTheseFlowsUse();

        foreach (self::ARABIC_LOCALES as $locale) {
            $english = [];

            foreach ($keys as $key) {
                $text = (string) ($this->catalogue[$locale][$key] ?? '');

                if (trim($text) !== '' && preg_match('/\p{Arabic}/u', $text) !== 1) {
                    $english[] = $key;
                }
            }

            $this->assertSame([], $english, "resources/lang/{$locale} holds no Arabic for: " . implode(', ', $english));
        }
    }

    /**
     * Every key written as a literal inside a `translate()` call in these flows.
     *
     * A key built from a value is not seen here and is not meant to be: those are enumerated against their own source of truth where the
     * value comes from an enum (as `Tests\Feature\Fulfillment\TranslationsTest` does for the fulfillment statuses).
     *
     * @return list<string>
     */
    private function keysTheseFlowsUse(): array
    {
        $keys = [];

        foreach ($this->sourceFiles() as $file) {
            if (preg_match_all('/translate\(\s*([\'"])([A-Za-z][A-Za-z0-9_\/]*)\1\s*\)/', (string) file_get_contents($file), $matches)) {
                foreach ($matches[2] as $key) {
                    // A key ending in an underscore is half a sentence the caller completes with a value, so it is not a key of its own.
                    if (!str_ends_with($key, '_')) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        $found = array_keys($keys);
        sort($found);

        return $found;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (self::SOURCES as $relative) {
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
