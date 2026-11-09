<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\AmazonReportRun;
use App\Models\SalesChannelAccount;

/**
 * Something that makes sense of one kind of Amazon report document.
 *
 * Fetching a report and understanding it are separate jobs on purpose. `AmazonReports` and `AmazonReportRunner` know about
 * polling, presigned URLs, gzip and the one-call-a-minute allowance; a consumer knows what a settlement means or what a listing
 * row says, and nothing about HTTP. So a new report type is a new consumer, and a change to how Amazon hands documents over
 * touches none of them.
 *
 * A report type with no consumer is **named** by the runner rather than counted as read: a row that said "read" when nothing
 * understood the document would read as "the settlement was imported".
 */
interface ReadsAmazonReports
{
    /** Is this the type this consumer understands? */
    public function handles(string $reportType): bool;

    /**
     * Reads one document that has already been parsed into rows by its own header.
     *
     * It is handed the parsed file rather than the bytes, so no consumer repeats the BOM, the locale or the delimiter handling
     * — and none of them can quietly skip it.
     *
     * `rows_refused` is not an error path: a settlement line whose amount cannot be read, or a listing row with no SKU, is
     * COUNTED and named so a person can see that the document was not wholly understood. A consumer that returned only its
     * successes would make a partly-read report look complete.
     *
     * @return array{outcome: string|null, rows_read: int, rows_refused: int, detail: string|null}
     */
    public function read(AmazonReportRun $run, AmazonFlatFile $file, SalesChannelAccount $account): array;
}
