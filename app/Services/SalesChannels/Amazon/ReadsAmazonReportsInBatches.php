<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\AmazonReportRun;

/**
 * A consumer whose `read()` is correct for **any contiguous slice** of its document, and which is told once at the end
 * whether the whole document actually arrived.
 *
 * The claim lives in the type system rather than in a flag so that a reader cannot be put on the streaming path by a
 * configuration change nobody read the consequences of.
 *
 * ## What a reader must be true of before it says this
 *
 * 1. **Every row stands alone.** Nothing is derived by scanning the document — no "the first row carrying X describes the
 *    whole file", which is exactly why {@see AmazonSettlementReader} does NOT implement this: it finds the settlement by the
 *    first row with a `settlement-id`, and only the first row of the file carries the period and the deposit total. Hand it a
 *    later batch and it would record a settlement with neither.
 * 2. **Nothing is concluded from absence until `finish()` says the document was whole.** A batch is not the document, so
 *    "this SKU was not in the report" is unknowable until the last byte has arrived.
 * 3. **Reading the same row twice is the same as reading it once**, because a retried or re-run report repeats rows.
 */
interface ReadsAmazonReportsInBatches extends ReadsAmazonReports
{
    /**
     * Called exactly once after the last batch of a run, before the row is written.
     *
     * `$documentComplete` is the only place a batch-safe reader may act on what it did NOT see: false means the transport
     * stopped part way, so any total it has is a floor and not a count. A reader that summarised without being told this
     * would describe a truncated document as a finished comparison — the mistake the whole streaming path is written to
     * avoid.
     *
     * Implementations must release whatever per-run state they accumulated, because one instance serves every run in a
     * process.
     *
     * @return array{outcome: string|null, detail: string|null}
     */
    public function finish(AmazonReportRun $run, bool $documentComplete): array;
}
