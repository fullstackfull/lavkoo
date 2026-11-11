<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Amazon\AmazonFlatFile;
use App\Services\SalesChannels\Amazon\AmazonReportStream;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * Reading a report bigger than memory, without putting it on disk.
 *
 * The buffering reader refuses a document only AFTER the whole body has arrived, so its ceiling bounds what gets parsed and
 * not what gets received: a seller whose listings report outgrew it paid for a download and could read none of it.
 *
 * **The usual fix is forbidden here.** Amazon's own Reports page states that "Unencrypted report content must never be stored
 * on disk, even temporarily", so streaming to a temporary file is not available. The body is read in chunks, split into whole
 * lines, and parsed in batches by `AmazonFlatFile` — which keeps the three documented hazards (the UTF-8 BOM, headers in
 * another locale, amounts in local notation) in one place rather than reimplementing them for streamed bytes.
 *
 * The tests below are mostly about the ways a partial read can lie:
 *
 *  - a document that ends mid-stream has handed over REAL rows, and must say so rather than look short;
 *  - a body with no line breaks must not be buffered whole, which would undo the whole exercise;
 *  - a gzip body that is not gzip must stop rather than hand on bytes nobody could tell from rows;
 *  - and a header in another locale must refuse on every batch, because an empty parse of a listings report reads as
 *    "this seller has no listings".
 */
class AmazonReportStreamTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const DOWNLOAD = 'https://amazon-reports.s3.example.test/doc.tsv';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config(['sales_channels.enabled' => true]);
        $this->account = $this->amazonAccount();
    }

    public function test_a_report_is_read_in_batches_and_every_row_arrives_once(): void
    {
        $rows = 4500;
        Http::fake([self::DOWNLOAD => Http::response($this->aListingsReport($rows))]);
        $seen = [];
        $batches = [];

        $result = app(AmazonReportStream::class)->read(
            account: $this->account,
            url: self::DOWNLOAD,
            onBatch: function (AmazonFlatFile $file) use (&$seen, &$batches): void {
                $batches[] = count($file->rows);
                foreach ($file->rows as $row) {
                    $seen[] = $file->cell($row, 'seller-sku');
                }
            },
            required: ['seller-sku', 'price'],
        );

        $this->assertNull($result['refusal']);
        $this->assertSame($rows, $result['rows_read']);
        $this->assertCount($rows, $seen);
        $this->assertSame($rows, count(array_unique($seen)), 'a row was handed over twice');
        $this->assertSame('SKU-1', $seen[0]);
        $this->assertSame('SKU-' . $rows, $seen[$rows - 1], 'the rows did not arrive in file order');
        // Batched, not one go: that is the whole point. 4,500 rows at 2,000 a batch is three.
        $this->assertGreaterThan(1, count($batches), 'the whole document was parsed in one piece');
        $this->assertSame($rows, array_sum($batches));
    }

    public function test_every_batch_carries_the_documents_own_header(): void
    {
        // The header is put back in front of each batch, so the parser that knows the hazards sees a whole document every
        // time -- rather than this class reimplementing BOM, locale and delimiter handling for streamed bytes.
        Http::fake([self::DOWNLOAD => Http::response($this->aListingsReport(2500))]);
        $headers = [];

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$headers): void {
            $headers[] = $file->header;
        }, ['seller-sku']);

        $this->assertNull($result['refusal']);
        $this->assertGreaterThan(1, count($headers));
        foreach ($headers as $header) {
            $this->assertSame(['seller-sku', 'price', 'quantity'], $header);
        }
        $this->assertSame(['seller-sku', 'price', 'quantity'], $result['header']);
    }

    public function test_the_byte_order_mark_is_handled_on_streamed_bytes_too(): void
    {
        // Amazon: "This report includes a UTF-8 Byte Order Mark (BOM) as the first 3 bytes." It rides on the FIRST header
        // name, so every lookup of the first column misses -- quietly, because a missing column reads as an empty cell.
        Http::fake([self::DOWNLOAD => Http::response("\xEF\xBB\xBF" . $this->aListingsReport(3))]);
        $first = null;

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$first): void {
            $first ??= $file->cell($file->rows[0], 'seller-sku');
        }, ['seller-sku']);

        $this->assertNull($result['refusal']);
        $this->assertSame('SKU-1', $first, 'the BOM was read as part of the first column name');
    }

    public function test_a_header_in_another_locale_refuses_rather_than_reporting_no_rows(): void
    {
        // Amazon: "Requesting the same report within the cache period ... may return headers in a different locale than
        // requested." A translated header matches none of our names, and an empty parse of a listings report is
        // indistinguishable from a seller with nothing listed.
        Http::fake([self::DOWNLOAD => Http::response("artikel-sku\tpreis\tmenge\nABC\t10,00\t5\n")]);
        $batches = 0;

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function () use (&$batches): void {
            $batches++;
        }, ['seller-sku', 'price']);

        $this->assertSame(0, $batches, 'rows from an unrecognised header were handed to the consumer');
        $this->assertSame(0, $result['rows_read']);
        $this->assertStringStartsWith('amazon_report_columns_not_found', (string) $result['refusal']);
    }

    public function test_a_document_that_stops_part_way_says_so_and_keeps_the_rows_it_gave(): void
    {
        // **The dangerous answer.** A half-read report that looked merely short would have a settlement period recorded as
        // reconciled from half its lines. The rows handed over are real; the refusal says the rest never came.
        config(['sales_channels.amazon.report_stream_max_bytes' => 4096]);
        Http::fake([self::DOWNLOAD => Http::response($this->aListingsReport(5000))]);
        $rows = 0;

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$rows): void {
            $rows += count($file->rows);
        }, ['seller-sku']);

        $this->assertNotNull($result['refusal'], 'a truncated report was reported as a complete one');
        $this->assertStringStartsWith('stream_is_larger_than_we_will_read', (string) $result['refusal']);
        $this->assertGreaterThan(0, $result['bytes']);
        $this->assertLessThan(5000, $rows, 'the whole document was read despite the ceiling');
    }

    public function test_a_body_with_no_line_breaks_is_not_buffered_whole(): void
    {
        // A one-line error page from a proxy. Letting the buffer grow would hold the whole document in memory, which is the
        // one thing this class exists to avoid.
        Http::fake([self::DOWNLOAD => Http::response(str_repeat('x', 2 * 1024 * 1024))]);

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, static function (): void {
        }, ['seller-sku']);

        $this->assertStringStartsWith('amazon_report_has_no_line_breaks_within', (string) $result['refusal']);
        $this->assertSame(0, $result['rows_read']);
    }

    public function test_a_gzipped_report_is_inflated_as_it_arrives(): void
    {
        Http::fake([self::DOWNLOAD => Http::response(gzencode($this->aListingsReport(2500)))]);
        $rows = 0;

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$rows): void {
            $rows += count($file->rows);
        }, ['seller-sku'], gzipped: true);

        $this->assertNull($result['refusal']);
        $this->assertSame(2500, $rows);
    }

    public function test_a_body_amazon_called_gzip_and_is_not_stops_rather_than_handing_on_bytes(): void
    {
        Http::fake([self::DOWNLOAD => Http::response($this->aListingsReport(3))]);

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, static function (): void {
        }, ['seller-sku'], gzipped: true);

        $this->assertSame('amazon_report_gzip_could_not_be_read', $result['refusal']);
        $this->assertSame(0, $result['rows_read']);
    }

    public function test_a_download_that_failed_is_never_an_empty_report(): void
    {
        // Five minutes is all the presigned URL is good for, so this is the ordinary failure -- and reading it as an empty
        // report would record a seller's whole catalogue as gone.
        Http::fake([self::DOWNLOAD => Http::response('', 403)]);

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, static function (): void {
        }, ['seller-sku']);

        $this->assertSame(0, $result['rows_read']);
        $this->assertSame('stream_refused:403', $result['refusal']);
    }

    public function test_an_empty_body_is_a_refusal_and_not_a_seller_with_nothing_listed(): void
    {
        Http::fake([self::DOWNLOAD => Http::response('')]);

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, static function (): void {
        }, ['seller-sku']);

        $this->assertSame('amazon_report_document_is_empty', $result['refusal']);
    }

    public function test_a_document_whose_last_line_has_no_newline_keeps_that_row(): void
    {
        // The off-by-one that loses the last row of every report that does not end with a newline.
        Http::fake([self::DOWNLOAD => Http::response("seller-sku\tprice\tquantity\nSKU-1\t10.00\t5\nSKU-2\t11.00\t6")]);
        $seen = [];

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$seen): void {
            foreach ($file->rows as $row) {
                $seen[] = $file->cell($row, 'seller-sku');
            }
        }, ['seller-sku']);

        $this->assertNull($result['refusal']);
        $this->assertSame(['SKU-1', 'SKU-2'], $seen, 'the last row was dropped for want of a trailing newline');
    }

    public function test_the_stream_answers_to_the_same_switches_as_every_other_call(): void
    {
        // **Why streaming lives on the transport.** A caller that reached for the HTTP client directly would answer to none
        // of the gates -- the switches, the capability checks, the rate limiter, the log line. This asserts the first of
        // those: with the module off, the stream sends nothing.
        config(['sales_channels.enabled' => false]);

        // The gate REFUSES rather than returning: `assertAllowed` throws for every call in this module, and the streaming
        // door is no exception to it. Asserting the throw is the stronger claim -- a return value could be ignored.
        try {
            app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, static function (): void {
            }, ['seller-sku']);
            $this->fail('the stream ran with the module switched off');
        } catch (\App\Services\SalesChannels\Exceptions\ChannelUnavailableException $refused) {
            $this->assertSame(\App\Services\SalesChannels\Exceptions\ChannelUnavailableException::GLOBAL_OFF, $refused->reason);
        }
        Http::assertNothingSent();
    }

    public function test_the_stream_writes_a_log_line_with_the_size_and_not_the_content(): void
    {
        // A report body is a seller's own catalogue or settlement data, and this one is too big to belong in a log whatever
        // it held. The line still has to exist: a call nobody can reconstruct afterwards is its own problem.
        Http::fake([self::DOWNLOAD => Http::response($this->aListingsReport(5))]);

        app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, static function (): void {
        }, ['seller-sku']);

        $logged = \App\Models\SalesChannelApiLog::where('operation', 'amazon.report_download')->firstOrFail();
        $line = (string) json_encode($logged->getAttributes());
        $this->assertStringNotContainsString('SKU-1', $line, 'the report body was written to the log');
        $this->assertStringContainsString('streamed', $line, 'the log says nothing about what came back');
    }

    public function test_a_transport_that_stops_mid_row_does_not_hand_the_fragment_on_as_a_row(): void
    {
        // **The fragment case.** `ChannelHttp::stream()` reads 64 KiB at a time and, at the byte ceiling or on a dropped
        // transfer, sets its refusal WITHOUT setting this class's own `$stopped`. The bytes therefore stop wherever that
        // chunk stopped — inside a row — and the trailing buffer is a FRAGMENT. Handed on it would read as an ordinary
        // short row: the cell at the cut becomes a prefix of itself (`300` as `3`) and every later column disappears, and
        // nothing downstream can tell it from a real row. The complete lines already buffered are real and must survive.
        $rows = 6000;
        config(['sales_channels.amazon.report_stream_max_bytes' => 100000]);
        Http::fake([self::DOWNLOAD => Http::response($this->aListingsReport($rows))]);
        $seen = [];

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$seen): void {
            foreach ($file->rows as $row) {
                $seen[] = [$file->cell($row, 'seller-sku'), $file->cell($row, 'price'), $file->cell($row, 'quantity')];
            }
        }, ['seller-sku', 'price', 'quantity']);

        $this->assertNotNull($result['refusal'], 'a truncated report was reported as a complete one');
        $this->assertNotSame([], $seen, 'the rows that did arrive were dropped with the fragment');
        $this->assertLessThan($rows, count($seen));

        // Every row handed over carries all three columns, and the last one is whole rather than cut.
        foreach ($seen as $index => $row) {
            $this->assertNotNull($row[2], 'row ' . $index . ' lost its last column, so a fragment was handed on as a row');
            $this->assertSame('', '' . preg_replace('/^SKU-\d+$/', '', (string) $row[0]), 'a SKU arrived cut: ' . $row[0]);
        }
        $last = $seen[count($seen) - 1];
        $expected = (int) substr((string) $last[0], 4);
        $this->assertSame((string) $expected, $last[2], 'the final row handed over was a fragment, not a row');
        $this->assertSame(number_format(10 + $expected / 100, 2, '.', ''), $last[1]);
    }

    /** A listings report shaped like Amazon's: a tab-delimited header row, then one line per SKU. */
    public function test_a_body_shorter_than_the_response_promised_is_refused_and_not_read_as_the_whole_document(): void
    {
        // **A transfer can end short of the document with no error at all:** a peer that closes cleanly part way through a
        // truncated S3 transfer leaves no exception and no status, and `eof()` simply becomes true. Proven against a real
        // socket while this was being written -- 7000 bytes delivered of a promised 7118, `refusal` null, and the last row
        // handed on was the fragment `SKU-394\t`. Measured against what the response promised it is unmistakable.
        $whole = $this->aListingsReport(40);
        $cut = substr($whole, 0, (int) (strlen($whole) * 0.6));
        Http::fake([self::DOWNLOAD => Http::response($cut, 200, ['Content-Length' => (string) strlen($whole)])]);
        $seen = [];

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$seen): void {
            foreach ($file->rows as $row) {
                $seen[] = $file->cell($row, 'seller-sku');
            }
        }, ['seller-sku']);

        $this->assertNotNull($result['refusal'], 'a document cut off part way was reported as a whole one');
        $this->assertStringStartsWith('stream_ended_early:', (string) $result['refusal']);
        $this->assertStringContainsString((string) strlen($whole), (string) $result['refusal'], 'the refusal names what was promised');
        $this->assertLessThan(40, count($seen), 'it cannot have seen rows that never arrived');
    }

    public function test_a_whole_body_that_matches_the_promised_length_is_not_refused(): void
    {
        // The converse, and the one that matters more: a check that refuses whole documents would be worse than the defect
        // it closes, because every report would become unreadable.
        $whole = $this->aListingsReport(40);
        Http::fake([self::DOWNLOAD => Http::response($whole, 200, ['Content-Length' => (string) strlen($whole)])]);
        $seen = 0;

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$seen): void {
            $seen += count($file->rows);
        }, ['seller-sku']);

        $this->assertNull($result['refusal']);
        $this->assertSame(40, $seen);
    }

    public function test_a_body_that_promises_nothing_is_read_as_before(): void
    {
        // A chunked response carries no `Content-Length` to measure against, so it must behave exactly as it always has
        // rather than being refused for failing to promise anything.
        $whole = $this->aListingsReport(12);
        Http::fake([self::DOWNLOAD => Http::response($whole, 200, ['Transfer-Encoding' => 'chunked'])]);
        $seen = 0;

        $result = app(AmazonReportStream::class)->read($this->account, self::DOWNLOAD, function (AmazonFlatFile $file) use (&$seen): void {
            $seen += count($file->rows);
        }, ['seller-sku']);

        $this->assertNull($result['refusal']);
        $this->assertSame(12, $seen);
    }

    private function aListingsReport(int $rows): string
    {
        $text = "seller-sku\tprice\tquantity\n";
        for ($i = 1; $i <= $rows; $i++) {
            $text .= 'SKU-' . $i . "\t" . number_format(10 + $i / 100, 2, '.', '') . "\t" . $i . "\n";
        }

        return $text;
    }
}
