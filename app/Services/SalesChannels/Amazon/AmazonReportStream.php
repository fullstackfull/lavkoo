<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelHttp;

/**
 * An Amazon report read in batches, so a catalogue bigger than memory is a long read rather than a refusal.
 *
 * ## The gap this closes, and what it is NOT allowed to do about it
 *
 * `AmazonReports::contents()` buffers the whole body: the HTTP client hands it over complete, so a ceiling there bounds what
 * gets PARSED and not what gets RECEIVED. A seller whose listings report outgrows it got a refusal after paying for the
 * download, and nothing could be read at all.
 *
 * The obvious fix is forbidden. Amazon's own Reports page states that "Unencrypted report content must never be stored on
 * disk, even temporarily", so streaming to a temporary file — the usual answer — is not available. The body is therefore read
 * in chunks straight from the socket, split into whole lines, and handed on in batches; nothing bigger than one batch plus a
 * partial line is ever held, and nothing touches disk.
 *
 * ## Why batches of lines rather than rows
 *
 * `AmazonFlatFile` already handles the three documented hazards — the UTF-8 BOM on the first header name, headers that may
 * arrive in another locale, and amounts in local notation — and getting any of them wrong is a silent wrong number in
 * somebody's accounts. So this class does not parse anything. It finds line boundaries and hands each batch to
 * `AmazonFlatFile` with the document's own header line put back in front, which is a document in its own right as far as that
 * parser is concerned. One parser, one set of hazards, however the bytes arrived.
 *
 * ## And who uses it today
 *
 * `AmazonSettlementReader` is the only implementation of `ReadsAmazonReports` and it `handles()` the settlement report alone,
 * so the type that most needs this -- `GET_MERCHANT_LISTINGS_ALL_DATA`, a whole catalogue -- has no reader at all and its row
 * is marked `amazon_report_has_no_reader`. That reader would be per-row (SKU, quantity, status) and so batch-safe from the
 * start; the settlement reader is not, because it finds its settlement's identifying row by scanning the document and only
 * the first row carries the period and the deposit total. Both facts are recorded in docs/sales-channels/gap-matrix.md rather
 * than worked around here.
 *
 * ## What a half-read document is
 *
 * It is not a short document. If the stream fails or the ceiling is reached part way through, the consumer has already been
 * handed real rows, and saying so is the only honest outcome: `rows_read` is what it is and `refusal` says the rest never
 * arrived. A caller that treated that as the whole report would record a settlement period as reconciled from half its lines.
 * Nothing is retried, because a stream cannot be replayed from the middle.
 */
class AmazonReportStream
{
    /** How many data lines are parsed at a time. Small enough to bound memory, large enough that parsing is not the cost. */
    private const LINES_PER_BATCH = 2000;

    /**
     * A single line longer than this ends the read.
     *
     * The guard against a body with no line breaks at all — a one-line HTML error page from a proxy, say — which would
     * otherwise grow the buffer to the whole document and undo the point of streaming.
     */
    private const LONGEST_LINE = 1_048_576;

    public function __construct(private readonly ChannelHttp $http)
    {
    }

    /**
     * Streams one report document and hands each batch of rows to `$onBatch`.
     *
     * @param callable(AmazonFlatFile): void $onBatch called once per batch, in file order, each carrying the document's own header
     * @param list<string> $required columns the caller cannot work without, checked on every batch by `AmazonFlatFile`
     * @return array{rows_read: int, batches: int, bytes: int, header: list<string>, refusal: string|null}
     */
    public function read(
        SalesChannelAccount $account,
        string $url,
        callable $onBatch,
        array $required = [],
        bool $gzipped = false,
        ?int $ceiling = null,
    ): array {
        $ceiling ??= max(0, (int) config('sales_channels.amazon.report_stream_max_bytes', 0));

        $inflater = null;
        if ($gzipped) {
            // Incremental inflation, so the compressed body is never held whole either. `gzdecode()` would need all of it.
            $inflater = inflate_init(ZLIB_ENCODING_GZIP);
            if ($inflater === false) {
                return ['rows_read' => 0, 'batches' => 0, 'bytes' => 0, 'header' => [], 'refusal' => 'amazon_report_gzip_reader_could_not_start'];
            }
        }

        $buffer = '';
        $headerLine = null;
        $header = [];
        $pending = [];
        $rowsRead = 0;
        $batches = 0;
        $stopped = null;

        $flush = function () use (&$pending, &$headerLine, &$header, &$rowsRead, &$batches, &$stopped, $onBatch, $required): void {
            if ($pending === [] || $headerLine === null || $stopped !== null) {
                return;
            }
            // The header goes back in front of every batch, so each one is a complete document to the parser that knows the
            // hazards. A batch whose header no longer matches what the caller needs is a refusal, not an empty batch.
            $file = AmazonFlatFile::read($headerLine . "\n" . implode("\n", $pending), $required);
            $pending = [];
            if (!$file->isReadable()) {
                $stopped = $file->refusal;

                return;
            }
            $header = $file->header;
            $rowsRead += count($file->rows);
            $batches++;
            $onBatch($file);
        };

        $take = function (string $chunk) use (&$buffer, &$headerLine, &$pending, &$stopped, $flush, $inflater): void {
            if ($stopped !== null) {
                return;
            }
            if ($inflater !== null) {
                $plain = @inflate_add($inflater, $chunk);
                if ($plain === false) {
                    // Amazon said GZIP and this is not gzip. Nothing further can be read, and nothing read so far can be
                    // trusted to be a whole line.
                    $stopped = 'amazon_report_gzip_could_not_be_read';

                    return;
                }
                $chunk = $plain;
            }
            $buffer .= $chunk;

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $break), "\r");
                $buffer = substr($buffer, $break + 1);
                if ($headerLine === null) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $headerLine = $line;

                    continue;
                }
                if (trim($line) === '') {
                    continue;
                }
                $pending[] = $line;
                if (count($pending) >= self::LINES_PER_BATCH) {
                    $flush();
                    if ($stopped !== null) {
                        return;
                    }
                }
            }

            if (strlen($buffer) > self::LONGEST_LINE) {
                // No line break in a megabyte. Whatever this is, it is not a tab-delimited report, and letting the buffer
                // grow would hold the whole document in memory -- the exact thing this class exists to avoid.
                $stopped = 'amazon_report_has_no_line_breaks_within:' . self::LONGEST_LINE;
            }
        };

        $outcome = $this->http->stream(
            account: $account,
            operation: 'amazon.report_download',
            url: $url,
            consume: $take,
            ceiling: $ceiling,
        );

        if ($stopped === null) {
            // The last partial line is a line only if the document did not end with a newline.
            if (trim($buffer) !== '' && $headerLine !== null) {
                $pending[] = rtrim($buffer, "\r");
            } elseif (trim($buffer) !== '' && $headerLine === null) {
                $headerLine = rtrim($buffer, "\r");
            }
            $flush();
        }

        $refusal = $stopped ?? $outcome['refusal'];
        if ($refusal === null && $headerLine === null) {
            // Nothing came back at all. An empty report is its own answer elsewhere; here it means no header was found, and
            // a listings report with no header read as "no listings" is the mistake this module refuses to make.
            $refusal = 'amazon_report_document_is_empty';
        }

        return [
            'rows_read' => $rowsRead,
            'batches' => $batches,
            'bytes' => $outcome['bytes'],
            'header' => $header,
            // **A half-read document says so.** The rows already handed to the consumer are real; the rest never arrived.
            'refusal' => $refusal,
        ];
    }
}
