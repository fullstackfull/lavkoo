<?php

namespace App\Services\FileManager;

use App\Services\SellerCenter\Copy;

/**
 * What became of an archive the file manager was given, for the admin who uploaded it.
 *
 * A refusal that has a number in it (too many files, too much to unpack) names the limit that was crossed. Any other refusal means the archive
 * holds nothing the gallery stores.
 */
final class ArchiveReport
{
    public const TOO_MANY_FILES = 'the_archive_holds_more_than_n_files';

    public const TOO_LARGE = 'the_archive_unpacks_to_more_than_n_mb';

    private const NOTHING_TO_STORE = 'the_archive_holds_no_file_that_can_be_stored';

    private ?string $refusal = null;

    private int $limit = 0;

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
}
