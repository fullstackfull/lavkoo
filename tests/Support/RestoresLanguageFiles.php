<?php

namespace Tests\Support;

/**
 * translate() appends a humanised placeholder to the tracked language files for any key it does not know (the invoice and order templates still
 * use a few that never had a text), so a test that renders them would leave the working tree dirty. The files are put back after the test.
 *
 * Two test processes may share one working tree, so a copy is only taken, and only put back, when it is a whole file, and it is moved into
 * place in one step: a reader never sees half a file and a copy taken while another process was writing is never written back.
 */
trait RestoresLanguageFiles
{
    /** @var array<string, string> */
    private array $languageFileCopies = [];

    protected function rememberLanguageFiles(): void
    {
        foreach (['en', 'sa', 'ae'] as $locale) {
            $path = base_path("resources/lang/{$locale}/new-messages.php");
            $contents = (string) file_get_contents($path);
            if (str_starts_with($contents, "<?php\n") && str_ends_with($contents, "];\n")) {
                $this->languageFileCopies[$path] = $contents;
            }
        }
    }

    protected function restoreLanguageFiles(): void
    {
        foreach ($this->languageFileCopies as $path => $contents) {
            if ((string) file_get_contents($path) === $contents) {
                continue;
            }

            $staging = $path . '.' . getmypid() . '.tmp';
            file_put_contents($staging, $contents);
            chmod($staging, fileperms($path) & 0777);
            rename($staging, $path);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
        }
    }
}
