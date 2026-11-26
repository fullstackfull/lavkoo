<?php

namespace Tests\Feature\Localization;

use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The mechanism that keeps a test run out of `resources/lang` (M2), from the inside.
 *
 * {@see \Tests\Unit\Ci\CiLanguageFilesStayUnchangedTest} proves the CI gate and the switch that names an offending test. This proves the
 * two properties of the restore itself that a gate cannot see, each of which was once a defect:
 *
 *   - **every locale, not the three a test was once seen to dirty.** A refusal rendered with `lang: ks` rewrites that locale's file, and
 *     a trait that remembered `en`, `sa` and `ae` left it in the tree (found by Reviewer B). The parameters below are every locale the
 *     repository tracks a file for;
 *   - **the copy is the PRISTINE one the process read first, never whatever the test before left.** The old copy was taken afresh in each
 *     test's `setUp`, so a key written by a test with no restore of its own was adopted as the baseline by the next test that had one, and
 *     put back by it -- a dirty tree that every later restore then preserved.
 *
 * `translate()` itself is unchanged and is meant to be: it still appends the key, which is what the assertions here read. What changed is
 * that the file is put back afterwards.
 */
class ATestRunLeavesTheLanguageFilesAloneTest extends TestCase
{
    /** @return array<string, array{0: string}> every locale the repository tracks a new-messages file for */
    public static function everyTrackedLocale(): array
    {
        return ['ae' => ['ae'], 'bd' => ['bd'], 'en' => ['en'], 'es' => ['es'], 'in' => ['in'], 'ks' => ['ks'], 'sa' => ['sa']];
    }

    private function aKeyNoLocaleHolds(): string
    {
        return 'zz_language_restore_' . getmypid() . '_' . $this->name();
    }

    private function path(string $locale): string
    {
        return base_path("resources/lang/{$locale}/new-messages.php");
    }

    #[DataProvider('everyTrackedLocale')]
    public function test_a_key_written_in_any_locale_is_named_and_put_back(string $locale): void
    {
        $key = $this->aKeyNoLocaleHolds();
        $before = (string) file_get_contents($this->path($locale));
        Session::put('local', $locale);

        translate($key);

        $this->assertStringContainsString($key, (string) file_get_contents($this->path($locale)), 'translate() still appends the key');
        $this->assertSame(
            [$this->path($locale) => [$key]],
            $this->keysWrittenToTheLanguageFiles(),
            'the write is named, with the file and the key, which is what the switch reports and the gate cannot',
        );

        $this->restoreLanguageFiles();

        $this->assertSame($before, (string) file_get_contents($this->path($locale)), "{$locale} is put back exactly as it was");
        $this->assertSame([], $this->keysWrittenToTheLanguageFiles(), 'and nothing is left to report');
    }

    public function test_a_write_in_two_locales_at_once_is_put_back_in_both(): void
    {
        $key = $this->aKeyNoLocaleHolds();
        $before = ['bd' => (string) file_get_contents($this->path('bd')), 'in' => (string) file_get_contents($this->path('in'))];

        foreach (['bd', 'in'] as $locale) {
            Session::put('local', $locale);
            translate($key);
        }

        $this->assertSame([$this->path('bd'), $this->path('in')], array_keys($this->keysWrittenToTheLanguageFiles()));

        $this->restoreLanguageFiles();

        foreach ($before as $locale => $contents) {
            $this->assertSame($contents, (string) file_get_contents($this->path($locale)));
        }
    }

    /**
     * The copy is the one this process read first. A file already holding a key -- a test before this one wrote it and did not put it
     * back -- is not adopted as the baseline, so one unguarded test cannot make every restore after it preserve its key.
     */
    public function test_a_dirty_file_is_never_adopted_as_the_copy_to_put_back(): void
    {
        $path = $this->path('in');
        $before = (string) file_get_contents($path);
        $key = $this->aKeyNoLocaleHolds();

        file_put_contents($path, substr($before, 0, -3) . "\t'{$key}' => 'Written by hand',\n];\n");
        $this->rememberLanguageFiles();

        $this->assertSame([$path => [$key]], $this->keysWrittenToTheLanguageFiles(), 'remembering again does not re-read the file');

        $this->restoreLanguageFiles();

        $this->assertSame($before, (string) file_get_contents($path));
    }

    /** The cheap path: a run that wrote nothing reads none of the 3.8MB, which is why this may sit in the base class at all. */
    public function test_a_file_nothing_wrote_to_is_reported_without_being_read(): void
    {
        $this->assertSame([], $this->keysWrittenToTheLanguageFiles());
    }
}
