<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\AmazonReportRun;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use Carbon\CarbonInterface;

/**
 * Reads `GET_MERCHANT_LISTINGS_ALL_DATA` — every listing the seller has at Amazon — and mirrors each row's **quantity and
 * status** onto the listing we already hold for that SKU.
 *
 * ## Why this reader exists at all
 *
 * It is the report the streaming download was built for: a whole catalogue, the one document that outgrows memory. Until now
 * nothing understood it, so its runs ended `amazon_report_has_no_reader` — fetched and then thrown away. It is also the cheap
 * way to learn what Amazon currently holds: the Listings Items API answers per SKU at five calls a second, so a catalogue of
 * any size is one report rather than thousands of calls.
 *
 * ## What it deliberately does NOT do: it is a mirror, not a catalogue
 *
 * It writes `remote_quantity`, `remote_status` and `last_seen_at`, and **nothing else** — never the price, the title, the ASIN,
 * the fulfilment mode or the variation parent, and it never creates a listing row.
 *
 * That is a decision, not an omission. `ListingSyncService` writes a listing's every remote field from one `RemoteListing`, so
 * a source that does not know a field writes NULL over it. This document does not carry a barcode or a variation parent at
 * all, and the columns it does carry beyond the three required ones are not guaranteed by any Amazon page to be in the header
 * — so routing it through that service would quietly erase, on every report, what the Listings Items API had read. Erasing a
 * known price because a different source did not mention it is the kind of silent wrong number this module is written to
 * avoid, so the report is confined to the two facts it is authoritative about.
 *
 * A SKU with no listing row of ours is therefore **counted and named, never created** — the catalogue is the API read's job,
 * and a row invented here would carry a title and a price nobody verified. Making this a catalogue source is possible and is a
 * larger change: it needs `ListingSyncService` to be told which fields a source does not report, so that absent means
 * "unknown" instead of "NULL".
 *
 * ## Batch-safe, and why it may say so
 *
 * Every row stands alone, nothing is read from the document as a whole, nothing is concluded from a SKU's absence (so no
 * listing is ever flagged unseen from here), and reading a row twice writes the same two values. See
 * {@see ReadsAmazonReportsInBatches}.
 *
 * ## And it ships off
 *
 * `sales_channels.amazon.listings_report_enabled` is false, and while it is false `handles()` refuses the type, so the runner
 * names it `amazon_report_has_no_reader` exactly as it does today. Switching the reader on changes what a report run does with
 * a document, which is a decision for whoever owns the data, not a default.
 */
class AmazonListingsReader implements ReadsAmazonReportsInBatches
{
    public function handles(string $reportType): bool
    {
        return $reportType === AmazonReportTypes::ALL_LISTINGS && self::isEnabled();
    }

    public static function isEnabled(): bool
    {
        return (bool) config('sales_channels.amazon.listings_report_enabled', false);
    }

    /**
     * @return array{outcome: string|null, rows_read: int, rows_refused: int, detail: string|null}
     */
    public function read(AmazonReportRun $run, AmazonFlatFile $file, SalesChannelAccount $account): array
    {
        $read = 0;
        $refused = 0;
        $unknown = [];
        $seenAt = $this->snapshotTakenAt($run);

        foreach ($file->rows as $row) {
            $sku = trim((string) ($file->cell($row, 'seller-sku') ?? ''));
            if ($sku === '') {
                // A listing row with no SKU identifies nothing. Counted and named, because a document that is partly
                // unreadable must not look complete.
                $refused++;

                continue;
            }

            $quantity = $this->quantity($file->cell($row, 'quantity'));
            if ($quantity === null) {
                $refused++;

                continue;
            }

            $updated = SalesChannelProductMapping::where('account_id', $account->id)
                ->where('listing_key', $sku)
                ->update([
                    'remote_quantity' => $quantity,
                    'remote_status' => $this->status($file->cell($row, 'status')),
                    'last_seen_at' => $seenAt,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                // Amazon lists a SKU we hold no listing for. Named rather than created: see the class note.
                $unknown[$sku] = true;

                continue;
            }

            $read++;
        }

        return [
            'outcome' => $unknown === [] ? null : 'amazon_listings_not_held:' . count($unknown),
            'rows_read' => $read,
            'rows_refused' => $refused,
            'detail' => $unknown === [] ? null : 'SKUs Amazon lists and we hold no listing for: ' . mb_substr(implode(', ', array_slice(array_keys($unknown), 0, 20)), 0, 500),
        ];
    }

    /**
     * When this snapshot describes the catalogue — and deliberately the EARLIEST moment it could.
     *
     * `last_seen_at` is not a bookkeeping field: with `remote_quantity` it is the read-back, and
     * `SalesChannelProductMapping::readBackIsNewer()` compares it against `last_published_at` to decide what the channel is
     * believed to show. A report is requested, generated by Amazon some time later, and read later still, so stamping it with
     * the moment we happened to read it would make an hours-old snapshot look newer than a publish that came after it — and
     * `StockPlanner` would then skip a quantity it should have sent, because the stale figure already matched.
     *
     * Amazon gives no generation time this row keeps, so the request is used: the snapshot cannot predate the request. Erring
     * OLD is the safe direction — at worst a quantity is published again, where erring new would silently skip one.
     */
    private function snapshotTakenAt(AmazonReportRun $run): CarbonInterface
    {
        return $run->requested_at ?? now();
    }

    /** The quantity cell, or null when it is absent or not a whole number — never a guess, and never a silent zero. */
    private function quantity(?string $cell): ?int
    {
        $value = trim((string) $cell);

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function status(?string $cell): ?string
    {
        $value = trim((string) $cell);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }
}
