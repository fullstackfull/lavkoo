<?php

namespace Tests\Support;

/**
 * translate() appends any key it does not know to the tracked language files. The wallet refusals tested here ask for keys no language
 * file defines (need_Sufficient_Amount_Balance, Insufficient_Wallet_Balance and others), so a test that reaches them would write into
 * resources/lang.
 *
 * The test points translate() at a locale that has no language files. translate() then cannot read a file, so it cannot write one: it
 * falls back to the humanised key. A digest of every language file is taken before the test and compared after it, so a write that
 * slipped through another way fails the test instead of passing silently.
 */
trait KeepsTranslateOffLanguageFiles
{
    private const LOCALE_WITHOUT_LANGUAGE_FILES = 'zz-no-language-files';

    /** @var array<string, string> */
    private array $languageFileDigests = [];

    protected function keepTranslateOffLanguageFiles(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('resources/lang/' . self::LOCALE_WITHOUT_LANGUAGE_FILES));
        $this->languageFileDigests = $this->digestsOfLanguageFiles();
        session(['local' => self::LOCALE_WITHOUT_LANGUAGE_FILES]);
    }

    protected function assertLanguageFilesUntouched(): void
    {
        if ($this->languageFileDigests === []) {
            return;
        }
        $this->assertSame($this->languageFileDigests, $this->digestsOfLanguageFiles(), 'a file under resources/lang changed during the test');
    }

    /** @return array<string, string> */
    private function digestsOfLanguageFiles(): array
    {
        $digests = [];
        foreach (glob(base_path('resources/lang/*/*.php')) ?: [] as $file) {
            $digests[$file] = (string) md5_file($file);
        }

        return $digests;
    }
}
