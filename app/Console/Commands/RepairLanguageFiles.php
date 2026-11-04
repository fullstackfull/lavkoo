<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Repair a language file that no longer parses.
 *
 * `translate()` appends every unknown key to resources/lang/{locale}/new-messages.php, and that
 * file is included on every translate() call — so one entry whose text carries a quote, a
 * backslash or a $ used to make the file unparsable and every page fatal at once. The writer no
 * longer produces such entries; this command rescues a file already written by the old one,
 * on a server where editing PHP by hand is not an option.
 */
class RepairLanguageFiles extends Command
{
    protected $signature = 'lang:repair {--locale= : Repair one locale instead of all of them}';

    protected $description = 'Rewrite unparsable translation files, keeping every entry that can still be read';

    public function handle(): int
    {
        $locales = $this->option('locale')
            ? [$this->option('locale')]
            : array_map('basename', glob(base_path('resources/lang/*'), GLOB_ONLYDIR));

        $repaired = 0;

        foreach ($locales as $locale) {
            foreach (['new-messages.php', 'messages.php'] as $file) {
                $path = base_path('resources/lang/' . $locale . '/' . $file);
                if (!is_file($path)) {
                    continue;
                }

                if ($this->parses($path)) {
                    continue;
                }

                $entries = $this->salvage($path);
                if ($entries === []) {
                    $this->error("{$locale}/{$file}: unparsable, and nothing could be salvaged. Restore it from git.");
                    continue;
                }

                copy($path, $path . '.broken');
                file_put_contents($path, $this->render($entries));

                if (!$this->parses($path)) {
                    copy($path . '.broken', $path);
                    $this->error("{$locale}/{$file}: repair did not produce a parsable file; the original is untouched.");
                    continue;
                }

                $repaired++;
                $this->info("{$locale}/{$file}: repaired, " . count($entries) . ' entries kept (old file saved as ' . basename($path) . '.broken).');
            }
        }

        $this->line($repaired === 0 ? 'Every language file parses.' : "Repaired {$repaired} file(s).");

        return self::SUCCESS;
    }

    private function parses(string $path): bool
    {
        // A separate process: a ParseError in an include would otherwise be raised here, and a file
        // included once is cached for the rest of this run even after it is rewritten.
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

        return $status === 0;
    }

    /**
     * Read what the broken file still says, line by line.
     *
     * The old writer put one entry per line, so a line that carries an unescaped quote can be read
     * by taking everything between the FIRST and the LAST quote of its value — the exact text the
     * merchant meant, quotes and all.
     *
     * @return array<string, string>
     */
    private function salvage(string $path): array
    {
        $entries = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (!preg_match('/^\s*["\'](.+?)["\']\s*=>\s*(.*),\s*$/', $line, $matches)) {
                continue;
            }

            $value = trim($matches[2]);
            if (mb_strlen($value) >= 2 && in_array(mb_substr($value, 0, 1), ['"', "'"], true)) {
                $value = mb_substr($value, 1, mb_strlen($value) - 2);
            }

            $entries[$matches[1]] = stripcslashes($value);
        }

        return $entries;
    }

    /** @param array<string, string> $entries */
    private function render(array $entries): string
    {
        $contents = "<?php\n\nreturn [\n";
        foreach ($entries as $key => $value) {
            $contents .= "\t" . var_export((string) $key, true) . ' => ' . var_export((string) $value, true) . ",\n";
        }

        return $contents . "];\n";
    }
}
