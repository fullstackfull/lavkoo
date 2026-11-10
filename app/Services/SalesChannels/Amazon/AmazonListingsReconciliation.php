<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\AmazonReportRun;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;

/**
 * Compares `GET_MERCHANT_LISTINGS_ALL_DATA` — every listing the seller has at Amazon — against the listings we hold, and
 * **writes nothing anywhere except the report run's own row.**
 *
 * ## This is a comparison, NOT a stock sync, and the distinction is the whole design
 *
 * It does not update `remote_quantity`, `remote_status` or `last_seen_at`, and it reads no quantity from the document at
 * all. Nothing it does can reach `StockPlanner`, `StockPricePublisher`, or what the channel is believed to be showing.
 *
 * That is a decision with a reason. An earlier attempt mirrored the report's quantity onto the listing row and was withdrawn:
 * `remote_quantity` and `last_seen_at` together ARE the read-back, which `readBackIsNewer()`, `shownQuantity()`,
 * `StockPlanner::notAboveTheChannel()`, `everySaleIsKnown()`, `StockPricePublisher::skipReason()` and `settleByReadBack()`
 * all build decisions on — and a report carries no timestamp fit to order against a publish. `requested_at` can be null for
 * this type (`AmazonReportRunner::discover()` adopts reports the seller asked for in Seller Central), Amazon serves cached
 * reports for hours, and the consumers disagree about which direction is safe: `readBackIsNewer()` wants the older instant,
 * while `everySaleIsKnown()` passes it to `OrderFreshness::importedSince()`, where an older instant makes the
 * "every sale is imported" proof EASIER and so permits a raise above a channel that has already sold down. No single value
 * satisfies both, so this reader supplies neither. Keeping quantities in step needs Amazon's own generation time stored on
 * the run, which is a separate, larger change.
 *
 * ## What it records, in the run's existing columns
 *
 * `rows_read` is the rows carrying a usable SKU and `rows_refused` the rows that do not. The comparison itself goes in
 * `outcome` as a stable code and in `failure_detail` as one bounded sentence: how many of the document's rows name a listing
 * we hold, how many name one we do not, and at most twenty of those names as examples. The document itself is never stored,
 * and the sample is capped so that a catalogue none of which we hold cannot grow either the column or this object.
 *
 * ## Complete, refused, and unconfirmed are three different answers
 *
 * A comparison is only a comparison if the whole document arrived, so `finish()` is told whether it did:
 *
 *  - **complete** — the document was read whole: `listings_reconciled`, and the figures are counts.
 *  - **unconfirmed** — rows arrived and then the transport stopped: `listings_reconciliation_unconfirmed`, and every figure
 *    is a floor. The runner leaves such a run FAILED, so nothing downstream reads it as a finished comparison.
 *  - **refused** — nothing usable arrived at all: `listings_reconciliation_refused`, and no figure is claimed.
 *
 * What it deliberately does NOT report is how many listings we hold that the report never mentioned. That is the one figure
 * this shape cannot honestly produce: it needs the document's whole SKU set in hand, which is what streaming exists to
 * avoid, and marking rows as seen would be a write into exactly the columns this reader refuses to touch.
 *
 * ## And it ships off
 *
 * While `sales_channels.amazon.listings_reconciliation_enabled` is false, `handles()` refuses the type and the runner names
 * it `amazon_report_has_no_reader` exactly as before.
 */
class AmazonListingsReconciliation implements ReadsAmazonReportsInBatches
{
    /** How many unheld SKU names are kept as examples. A sample, because the alternative grows with the catalogue. */
    private const NAMES_KEPT = 20;

    /**
     * Per-run counters, keyed by run id and released in `finish()`.
     *
     * One instance of this class serves every run in a process (it is a single entry in the runner's consumer list), so the
     * state cannot be plain properties: a second run would inherit the first one's totals.
     *
     * @var array<int, array{rows: int, held: int, absent: int, unusable: int, samples: array<string, true>}>
     */
    private array $tally = [];

    public function handles(string $reportType): bool
    {
        return $reportType === AmazonReportTypes::ALL_LISTINGS && self::isEnabled();
    }

    private static function isEnabled(): bool
    {
        return (bool) config('sales_channels.amazon.listings_reconciliation_enabled', false);
    }

    /**
     * @return array{outcome: string|null, rows_read: int, rows_refused: int, detail: string|null}
     */
    public function read(AmazonReportRun $run, AmazonFlatFile $file, SalesChannelAccount $account): array
    {
        $runId = (int) $run->id;
        $this->tally[$runId] ??= ['rows' => 0, 'held' => 0, 'absent' => 0, 'unusable' => 0, 'samples' => []];

        $occurrences = [];
        $unusable = 0;
        foreach ($file->rows as $row) {
            $sku = trim((string) ($file->cell($row, 'seller-sku') ?? ''));
            if ($sku === '') {
                // A listing row with no SKU identifies nothing. Counted, because a document that is partly unreadable must
                // not look complete.
                $unusable++;

                continue;
            }
            $occurrences[$sku] = ($occurrences[$sku] ?? 0) + 1;
        }

        // One query for the batch, not one per row: the SKUs are deduplicated for the lookup and the counts below are still
        // per ROW, so a SKU Amazon repeats is reported as the two rows it is rather than silently collapsed.
        $held = $occurrences === []
            ? []
            : SalesChannelProductMapping::where('account_id', $account->id)
                ->whereIn('listing_key', array_keys($occurrences))
                ->pluck('listing_key')
                ->all();
        $heldKeys = array_fill_keys($held, true);

        $rows = 0;
        foreach ($occurrences as $sku => $count) {
            $rows += $count;
            if (isset($heldKeys[$sku])) {
                $this->tally[$runId]['held'] += $count;

                continue;
            }
            $this->tally[$runId]['absent'] += $count;
            if (count($this->tally[$runId]['samples']) < self::NAMES_KEPT) {
                $this->tally[$runId]['samples'][$sku] = true;
            }
        }

        $this->tally[$runId]['rows'] += $rows;
        $this->tally[$runId]['unusable'] += $unusable;

        // The summary belongs to the whole document, so it is built in finish() and nothing is claimed per batch.
        return ['outcome' => null, 'rows_read' => $rows, 'rows_refused' => $unusable, 'detail' => null];
    }

    /**
     * @return array{outcome: string|null, detail: string|null}
     */
    public function finish(AmazonReportRun $run, bool $documentComplete): array
    {
        $runId = (int) $run->id;
        $tally = $this->tally[$runId] ?? ['rows' => 0, 'held' => 0, 'absent' => 0, 'unusable' => 0, 'samples' => []];
        unset($this->tally[$runId]);

        if ($tally['rows'] === 0 && $tally['unusable'] === 0) {
            return [
                'outcome' => 'listings_reconciliation_refused',
                'detail' => 'No row of this document could be compared, so nothing is claimed about the catalogue.',
            ];
        }

        $examples = array_keys($tally['samples']);
        $sentence = $documentComplete
            ? sprintf(
                'Compared %d rows: %d name a listing we hold, %d name one we do not.',
                $tally['rows'],
                $tally['held'],
                $tally['absent'],
            )
            : sprintf(
                'The document stopped part way, so these are floors and NOT a complete comparison: %d rows seen, %d name a listing we hold, %d name one we do not.',
                $tally['rows'],
                $tally['held'],
                $tally['absent'],
            );

        if ($tally['unusable'] > 0) {
            $sentence .= sprintf(' %d rows carried no SKU and were not compared.', $tally['unusable']);
        }
        if ($examples !== []) {
            $sentence .= ' Not held, for example: ' . implode(', ', $examples) . '.';
        }
        // Counts only, never the document: this reader holds no quantity and writes to no listing.
        $sentence .= ' No listing, stock or price figure was written.';

        return [
            'outcome' => $documentComplete ? 'listings_reconciled' : 'listings_reconciliation_unconfirmed',
            'detail' => mb_substr($sentence, 0, 2000),
        ];
    }
}
