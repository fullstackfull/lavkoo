<?php

namespace App\Services\SalesChannels\Amazon;

/**
 * A consumer whose `read()` is correct for **any contiguous slice** of its document, not only for the whole of it.
 *
 * It carries no methods: it is a claim about `read()`, and the claim is what `AmazonReportRunner` needs in order to choose the
 * streaming download over the buffering one. Stated in the type system rather than as a flag so that a reader cannot be
 * streamed by a configuration change nobody read the consequences of.
 *
 * ## What a reader must be true of before it says this
 *
 * 1. **Every row stands alone.** Nothing is derived by scanning the document — no "the first row carrying X describes the
 *    whole file", which is exactly why {@see AmazonSettlementReader} does NOT implement this: it finds the settlement by the
 *    first row with a `settlement-id`, and only the first row of the file carries the period and the deposit total. Hand it a
 *    later batch and it would record a settlement with neither.
 * 2. **Nothing is concluded from absence.** A batch is not the document, so "this SKU was not in the report" is unknowable
 *    until the last byte has arrived. A reader that flagged, delisted or zeroed what it did not see would act on a half-read
 *    file every time a stream was cut short.
 * 3. **Reading the same row twice is the same as reading it once**, because a retried or re-run report repeats rows.
 */
interface ReadsAmazonReportsInBatches extends ReadsAmazonReports
{
}
