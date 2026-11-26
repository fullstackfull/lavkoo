<?php

namespace Tests\Support;

/**
 * translate() appends a humanised placeholder to the tracked language files for any key it does not know (the invoice and order templates still
 * use a few that never had a text), so a test that renders them would leave the working tree dirty. The files are put back after the test.
 *
 * Two test processes may share one working tree, so a copy is only taken, and only put back, when it is a whole file, and it is moved into
 * place in one step: a reader never sees half a file and a copy taken while another process was writing is never written back.
 *
 * The copy is taken ONCE PER PROCESS, not once per test. The seven files are 3.8MB together and nothing in a test run legitimately changes
 * them, so re-reading them for each of the twenty-two thousand tests bought nothing and cost the read: what a later test must be put back
 * to is the pristine checkout, which is what the first snapshot of the process holds. {@see \Tests\TestCase} remembers and restores for
 * every test that boots the application, so a suite does not depend on each of its files electing to.
 */
trait RestoresLanguageFiles
{
    /** Every locale the repository tracks a new-messages file for. */
    private const LOCALES = ['ae', 'bd', 'en', 'es', 'in', 'ks', 'sa'];

    /** @var array<string, string>|null the pristine text of each tracked language file, read once per process */
    private static ?array $languageFileCopies = null;

    /** @var array<string, int> the size and modification time the file is known to have, so an unchanged file is never read again */
    private static array $languageFileSizes = [];

    /** @var array<string, int> */
    private static array $languageFileTimes = [];

    protected function rememberLanguageFiles(): void
    {
        if (self::$languageFileCopies !== null) {
            return;
        }

        // Every locale directory git tracks, not only the three a test was once seen to dirty: a refusal
        // rendered with `lang: bd` rewrites resources/lang/bd/new-messages.php whole (var_export
        // normalisation, 530 lines), and a trait that only remembered en/sa/ae left that in the tree
        // (measured; found by Reviewer B).
        self::$languageFileCopies = [];
        foreach (self::LOCALES as $locale) {
            $path = base_path("resources/lang/{$locale}/new-messages.php");
            if (!is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (str_starts_with($contents, "<?php\n") && str_ends_with($contents, "];\n")) {
                self::$languageFileCopies[$path] = $contents;
                self::rememberTheFileIsPristine($path);
            }
        }
    }

    protected function restoreLanguageFiles(): void
    {
        foreach (array_keys($this->keysWrittenToTheLanguageFiles()) as $path) {
            $staging = $path . '.' . getmypid() . '.tmp';
            file_put_contents($staging, self::$languageFileCopies[$path]);
            chmod($staging, fileperms($path) & 0777);
            rename($staging, $path);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
            self::rememberTheFileIsPristine($path);
        }
    }

    /**
     * Which keys each language file has gained since the copy was taken, so that a guard can NAME them rather than report a dirty tree.
     *
     * The common answer is "none", and it is reached without reading a single byte: the size and the modification time are compared
     * first, and the 3.8MB are read only for a file one of those says has moved. translate() rewrites the whole file through AtomicFile,
     * which replaces it, so a write always moves both.
     *
     * @return array<string, list<string>> path => the keys it gained
     */
    protected function keysWrittenToTheLanguageFiles(): array
    {
        $written = [];
        clearstatcache();

        foreach (self::$languageFileCopies ?? [] as $path => $contents) {
            if (!is_file($path)) {
                continue;
            }
            if (filesize($path) === self::$languageFileSizes[$path] && filemtime($path) === self::$languageFileTimes[$path]) {
                continue;
            }

            $now = (string) file_get_contents($path);
            if ($now === $contents) {
                self::rememberTheFileIsPristine($path);
                continue;
            }

            $written[$path] = array_values(array_diff(self::keysIn($now), self::keysIn($contents)));
        }

        return $written;
    }

    private static function rememberTheFileIsPristine(string $path): void
    {
        clearstatcache(true, $path);
        self::$languageFileSizes[$path] = (int) filesize($path);
        self::$languageFileTimes[$path] = (int) filemtime($path);
    }

    /**
     * The keys of a language file, read from its text rather than by including it: a file this process has already included is answered
     * from OPcache, and the whole point here is to read what is on disk now.
     *
     * @return list<string>
     */
    private static function keysIn(string $contents): array
    {
        // Either quote style: translate() writes every key through var_export, which single-quotes it, but `bd` and `in` still hold the
        // double-quoted rows they were first written with -- so a regex that read only one style saw the whole file change the moment one
        // key was appended and rewrote it, and reported 377 new keys instead of one (measured).
        preg_match_all('/^\t([\'"])((?:\\\\.|(?!\1).)*)\1 => /m', $contents, $matches);

        return array_map(
            static fn (string $key): string => str_replace(['\\\'', '\\"', '\\\\'], ["'", '"', '\\'], $key),
            $matches[2],
        );
    }
}
