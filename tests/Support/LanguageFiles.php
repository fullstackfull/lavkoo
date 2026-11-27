<?php

namespace Tests\Support;

/**
 * The pristine text of the tracked language files, read ONCE PER PROCESS, and what a test has written into them since.
 *
 * It is a class and not part of {@see RestoresLanguageFiles} because a trait's static property belongs to the class that uses the trait,
 * not to the trait: measured on PHP 8.3, a parent and a child that both `use` it each get their own. 196 classes use it, the seven files
 * are 3.8MB together, and a copy per class would have held up to three quarters of a gigabyte for the length of a run -- where the
 * per-instance copy it replaced was freed after each test. One class, one copy.
 */
final class LanguageFiles
{
    /** Every locale the repository tracks a new-messages file for. */
    private const LOCALES = ['ae', 'bd', 'en', 'es', 'in', 'ks', 'sa'];

    /** @var array<string, string>|null the pristine text of each file */
    private static ?array $copies = null;

    /** @var array<string, int> the size and modification time each file is known to have, so an unchanged file is never read again */
    private static array $sizes = [];

    /** @var array<string, int> */
    private static array $times = [];

    /**
     * Takes the copy, once.
     *
     * A copy is only taken of a WHOLE file: two test processes may share one working tree, and a copy taken while another process was
     * part-way through a write must never be written back.
     */
    public static function remember(string $directory): void
    {
        if (self::$copies !== null) {
            return;
        }

        // Every locale directory git tracks, not only the three a test was once seen to dirty: a refusal rendered with `lang: bd`
        // rewrites resources/lang/bd/new-messages.php whole (var_export normalisation, 530 lines), and a trait that only remembered
        // en/sa/ae left that in the tree (measured; found by Reviewer B).
        self::$copies = [];
        foreach (self::LOCALES as $locale) {
            $path = $directory . "/resources/lang/{$locale}/new-messages.php";
            if (!is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (str_starts_with($contents, "<?php\n") && str_ends_with($contents, "];\n")) {
                self::$copies[$path] = $contents;
                self::markPristine($path);
            }
        }
    }

    /**
     * Which keys each file has gained since the copy was taken, so that a guard can NAME them rather than report a dirty tree.
     *
     * The common answer is "none", and it is reached without reading a single byte: the size and the modification time are compared
     * first, and the 3.8MB are read only for a file one of those says has moved. translate() rewrites the whole file through AtomicFile,
     * which replaces it, so a write always moves both.
     *
     * @return array<string, list<string>> path => the keys it gained
     */
    public static function keysWritten(): array
    {
        $written = [];
        clearstatcache();

        foreach (self::$copies ?? [] as $path => $contents) {
            if (!is_file($path) || (filesize($path) === self::$sizes[$path] && filemtime($path) === self::$times[$path])) {
                continue;
            }

            $now = (string) file_get_contents($path);
            if ($now === $contents) {
                self::markPristine($path);
                continue;
            }

            $written[$path] = array_values(array_diff(self::keysIn($now), self::keysIn($contents)));
        }

        return $written;
    }

    /** Puts back every file a test wrote to, in one step each, so a reader never sees half a file. */
    public static function restore(): void
    {
        foreach (array_keys(self::keysWritten()) as $path) {
            $staging = $path . '.' . getmypid() . '.tmp';
            file_put_contents($staging, self::$copies[$path]);
            chmod($staging, fileperms($path) & 0777);
            rename($staging, $path);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
            self::markPristine($path);
        }
    }

    private static function markPristine(string $path): void
    {
        clearstatcache(true, $path);
        self::$sizes[$path] = (int) filesize($path);
        self::$times[$path] = (int) filemtime($path);
    }

    /**
     * The keys of a language file, read from its text rather than by including it: a file this process has already included is answered
     * from OPcache, and the whole point here is to read what is on disk now.
     *
     * Either quote style: translate() writes every key through var_export, which single-quotes it, but `bd` and `in` still hold the
     * double-quoted rows they were first written with -- so a regex that read only one style saw the whole file change the moment one key
     * was appended and the file was rewritten normalised, and reported 377 new keys instead of one (measured).
     *
     * @return list<string>
     */
    private static function keysIn(string $contents): array
    {
        preg_match_all('/^\t([\'"])((?:\\\\.|(?!\1).)*)\1 => /m', $contents, $matches);

        return array_map(
            static fn (string $key): string => str_replace(['\\\'', '\\"', '\\\\'], ["'", '"', '\\'], $key),
            $matches[2],
        );
    }
}
