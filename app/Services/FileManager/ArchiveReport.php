<?php

namespace App\Services\FileManager;

use App\Services\SellerCenter\Copy;
use Illuminate\Support\Str;

/**
 * What became of an archive the file manager was given, for the admin who uploaded it.
 *
 * A refusal that has a number in it (too many files, too much to unpack) names the limit that was crossed. Any other refusal means the archive
 * holds nothing the gallery stores. Whatever the upload leaves out of an archive that it does store is reported with the first names.
 */
final class ArchiveReport
{
    public const TOO_MANY_FILES = 'the_archive_holds_more_than_n_files';

    public const TOO_LARGE = 'the_archive_unpacks_to_more_than_n_mb';

    private const NOTHING_TO_STORE = 'the_archive_holds_no_file_that_can_be_stored';

    private const NAMES_SHOWN = 5;

    private const NAME_LENGTH = 60;

    private ?string $refusal = null;

    private int $limit = 0;

    private int $skipped = 0;

    /** @var list<string> */
    private array $skippedNames = [];

    /** @param self::TOO_MANY_FILES|self::TOO_LARGE $reason the key of the sentence that says why; `:limit` in it is the number that was crossed */
    public function refuse(string $reason, int $limit): void
    {
        $this->refusal = $reason;
        $this->limit = $limit;
    }

    /** Why nothing was stored. An upload that was refused for no reason named here held nothing the gallery stores. */
    public function refusalMessage(): string
    {
        return Copy::line($this->refusal ?? self::NOTHING_TO_STORE, ['limit' => $this->limit]);
    }

    /** An entry of the archive that is not stored although it is a file: a script, a page, a name the disk refuses, an entry nobody can read. */
    public function skip(string $name): void
    {
        $this->skipped++;
        if (count($this->skippedNames) < self::NAMES_SHOWN) {
            $this->skippedNames[] = $name;
        }
    }

    /**
     * How many files were left out and the first names, as one sentence; nothing when no file was left out or the archive was refused for a
     * number (that sentence says all there is). The names are the archive's own, so whoever shows the sentence shows it as text.
     */
    public function skippedMessage(): ?string
    {
        if ($this->skipped === 0 || $this->refusal !== null) {
            return null;
        }

        $names = array_map($this->readable(...), $this->skippedNames);
        if ($this->skipped > count($names)) {
            $names[] = Copy::clause('and_n_more', ['count' => $this->skipped - count($names)]);
        }

        return Copy::choice('one_file_of_the_archive_was_not_stored', 'n_files_of_the_archive_were_not_stored', $this->skipped, ['names' => implode(', ', $names)]);
    }

    /** A name without the characters that change how a line looks (a control, a bidirectional mark, a zero width joiner) and no longer than a line. */
    private function readable(string $name): string
    {
        return Str::limit((string)preg_replace('/\p{C}+/u', '?', mb_scrub($name)), self::NAME_LENGTH);
    }
}
