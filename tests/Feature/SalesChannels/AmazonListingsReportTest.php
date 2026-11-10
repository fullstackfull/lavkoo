<?php

namespace Tests\Feature\SalesChannels;

use App\Models\AmazonReportRun;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Amazon\AmazonListingsReader;
use App\Services\SalesChannels\Amazon\AmazonReportRunner;
use App\Services\SalesChannels\Amazon\AmazonReportTypes;
use App\Services\SalesChannels\Amazon\AmazonSettlementReader;
use App\Services\SalesChannels\Amazon\ReadsAmazonReportsInBatches;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * `GET_MERCHANT_LISTINGS_ALL_DATA` — the whole catalogue, the report the streaming download was built for, and until now the
 * one that was fetched and thrown away because nothing understood it.
 *
 * The tests here are mostly about the two ways this reader could do harm rather than good:
 *
 *  - **it could erase what another source knew.** `ListingSyncService` writes a listing's every remote field from one
 *    `RemoteListing`, so a source that does not report a field writes NULL over it. This document carries no barcode and no
 *    variation parent at all, so routing it through that service would quietly wipe, on every report, what the Listings Items
 *    API had read. The reader therefore writes the quantity, the status and `last_seen_at` and NOTHING else, and the test
 *    below pins every other column.
 *  - **it could conclude something from a half-read document.** A batch is not the document, so nothing here may act on a
 *    SKU's absence. The truncated-document test checks that the rows already written stay written and the run says the rest
 *    never came — rather than looking like a complete report from a seller with fewer listings.
 *
 * And it ships off: with the switch false the type is named `amazon_report_has_no_reader` exactly as before.
 */
class AmazonListingsReportTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const LWA = 'https://api.amazon.com/auth/o2/token';

    private const SP = 'https://sellingpartnerapi-eu.amazon.com';

    private const REPORTS = self::SP . '/reports/2021-06-30/reports';

    private const DOCUMENTS = self::SP . '/reports/2021-06-30/documents/*';

    private const DOWNLOAD = 'https://amazon-reports.s3.example.test/doc.tsv*';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.amazon.listings_report_enabled' => true]);
        $this->account = $this->amazonAccount();
    }

    public function test_the_type_still_has_no_reader_while_the_switch_is_off(): void
    {
        // The shipped state. Nothing about a report run changes until somebody turns this on.
        config(['sales_channels.amazon.listings_report_enabled' => false]);
        $run = $this->requestedRun();
        $this->listing('SKU-1', quantity: 1);
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active']]));

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame('amazon_report_has_no_reader:' . AmazonReportTypes::ALL_LISTINGS, $run->fresh()->failure_code);
        $this->assertSame(1, (int) $this->listingRow('SKU-1')->remote_quantity, 'the report wrote something with the switch off');
    }

    public function test_quantity_and_status_are_mirrored_onto_the_listing_we_hold(): void
    {
        $run = $this->requestedRun();
        $this->listing('SKU-1', quantity: 1);
        $this->listing('SKU-2', quantity: 2);
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active'], ['SKU-2', '0', 'Incomplete']]));

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, $stats['read']);
        $this->assertSame([AmazonReportRun::READ, 2, 0], [$run->fresh()->state, $run->fresh()->rows_read, $run->fresh()->rows_refused]);
        $this->assertSame([7, 'Active'], [(int) $this->listingRow('SKU-1')->remote_quantity, $this->listingRow('SKU-1')->remote_status]);
        $this->assertSame([0, 'Incomplete'], [(int) $this->listingRow('SKU-2')->remote_quantity, $this->listingRow('SKU-2')->remote_status]);
        $this->assertNotNull($this->listingRow('SKU-1')->last_seen_at);
    }

    public function test_nothing_the_report_does_not_own_is_written_over(): void
    {
        // **The reason this reader is a mirror and not a catalogue source.** A price or a title erased because a different
        // document did not mention it is a silent wrong number, and this report carries no barcode or parent at all.
        $run = $this->requestedRun();
        $this->listing('SKU-1', quantity: 1, extra: [
            'external_sku' => 'SKU-1', 'external_id' => 'B000ASIN01', 'external_barcode' => '5901234123457',
            'external_parent_id' => 'PARENT-1', 'external_title' => 'A title a person checked',
            'fulfillment_mode' => 'fbm', 'remote_price' => '41.500000', 'remote_currency' => 'SAR',
            'product_id' => 10, 'status' => SalesChannelProductMapping::MAPPED,
        ]);
        $before = $this->listingRow('SKU-1');
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active']]));

        app(AmazonReportRunner::class)->work($this->account);

        $after = $this->listingRow('SKU-1');
        $this->assertSame([7, 'Active'], [(int) $after->remote_quantity, $after->remote_status], 'the two columns it does own');
        foreach (['external_sku', 'external_id', 'external_barcode', 'external_parent_id', 'external_title', 'fulfillment_mode', 'remote_price', 'remote_currency', 'product_id', 'status', 'payload_hash'] as $column) {
            $this->assertSame($before->{$column}, $after->{$column}, $column . ' was written over by the listings report');
        }
        $this->assertSame(AmazonReportRun::READ, $run->fresh()->state);
    }

    public function test_a_report_never_looks_fresher_than_a_publish_that_came_after_it(): void
    {
        // A report is requested, generated by Amazon later, and read later still. Stamped with the moment it was READ, an
        // hours-old snapshot would outrank a publish that happened in between -- and `StockPlanner` skips a quantity whose
        // read-back already matches, so the skip would be silent and wrong. The request time is used instead: the snapshot
        // cannot predate it, and erring old only costs an extra publish.
        $run = $this->requestedRun();
        $run->forceFill(['requested_at' => CarbonImmutable::parse('2026-11-17 06:00:00 UTC')])->save();
        $this->listing('SKU-1', quantity: 1, extra: [
            'last_published_at' => CarbonImmutable::parse('2026-11-17 08:00:00 UTC'),
            'last_published_quantity' => 42,
        ]);
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active']]));

        app(AmazonReportRunner::class)->work($this->account);

        $row = $this->listingRow('SKU-1');
        $this->assertSame('2026-11-17 06:00:00', $row->last_seen_at->toDateTimeString(), 'the read time was used instead of the request time');
        $this->assertFalse($row->readBackIsNewer(), 'an older report outranked a newer publish');
        $this->assertSame(42, $row->shownQuantity(), 'the channel is believed to show a stale figure');
        $this->assertSame(7, (int) $row->remote_quantity, 'and the report was still recorded');
    }

    public function test_a_sku_amazon_lists_and_we_hold_no_listing_for_is_named_never_created(): void
    {
        $run = $this->requestedRun();
        $this->listing('SKU-1', quantity: 1);
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active'], ['SKU-UNKNOWN', '3', 'Active']]));

        app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, SalesChannelProductMapping::count(), 'the report invented a listing');
        $this->assertSame(1, $run->fresh()->rows_read);
        $this->assertSame('amazon_listings_not_held', $run->fresh()->outcome);
        $this->assertStringContainsString('SKU-UNKNOWN', (string) $run->fresh()->failure_detail);
    }

    public function test_a_row_with_no_sku_or_an_unreadable_quantity_is_counted_rather_than_guessed(): void
    {
        $run = $this->requestedRun();
        $this->listing('SKU-1', quantity: 4);
        $this->fakeDocument($this->report([['SKU-1', 'not a number', 'Active'], ['', '3', 'Active']]));

        app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame([0, 2], [$run->fresh()->rows_read, $run->fresh()->rows_refused]);
        $this->assertSame(4, (int) $this->listingRow('SKU-1')->remote_quantity, 'an unreadable quantity became a number');
    }

    public function test_a_catalogue_larger_than_one_batch_is_read_in_batches(): void
    {
        $run = $this->requestedRun();
        $rows = [];
        for ($i = 1; $i <= 2500; $i++) {
            $this->listing('SKU-' . $i, quantity: 0);
            $rows[] = ['SKU-' . $i, (string) $i, 'Active'];
        }
        $this->fakeDocument($this->report($rows));

        app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame([AmazonReportRun::READ, 2500], [$run->fresh()->state, $run->fresh()->rows_read]);
        $this->assertSame(1, (int) $this->listingRow('SKU-1')->remote_quantity);
        $this->assertSame(2500, (int) $this->listingRow('SKU-2500')->remote_quantity, 'the last batch never arrived');
        // Streaming holds no document, so there is nothing honest to hash.
        $this->assertNull($run->fresh()->document_hash);
    }

    public function test_a_document_that_stops_part_way_keeps_the_rows_it_gave_and_says_the_rest_never_came(): void
    {
        // **The dangerous answer.** A truncated catalogue that looked merely short would read as a seller who had delisted
        // most of their stock. The rows handed over are real and stay written; the run says the rest never arrived.
        //
        // `ChannelHttp::stream()` reads 64 KiB at a time and checks the ceiling BEFORE handing the chunk on, so a ceiling
        // under one chunk refuses before any row is read. This one sits above one chunk and below the document.
        config(['sales_channels.amazon.report_stream_max_bytes' => 100000]);
        $run = $this->requestedRun();
        $rows = [];
        for ($i = 1; $i <= 6000; $i++) {
            $rows[] = ['SKU-' . $i, (string) $i, 'Active'];
        }
        // Listings for the SKUs near the front, which arrive, and one near the back, which never does.
        for ($i = 1; $i <= 2000; $i++) {
            $this->listing('SKU-' . $i, quantity: 0);
        }
        $this->listing('SKU-5999', quantity: 11);
        $this->fakeDocument($this->report($rows));

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $fresh = $run->fresh();
        $this->assertSame(1, $stats['failed']);
        $this->assertStringStartsWith('stream_is_larger_than_we_will_read', (string) $fresh->failure_code);
        $this->assertGreaterThan(0, $fresh->rows_read, 'the rows it did hand over were real and must be admitted');
        $this->assertLessThan(6000, $fresh->rows_read, 'the whole document was read despite the ceiling');
        $this->assertStringContainsString('before the document stopped arriving', (string) $fresh->failure_detail);
        $this->assertSame(1, (int) $this->listingRow('SKU-1')->remote_quantity, 'a row that did arrive was not kept');

        // And NOTHING was concluded from the SKUs that never came: the listing at the back keeps its quantity and has not
        // been marked seen. A reader that flagged or zeroed what it did not see would do it on every cut-short stream.
        $late = $this->listingRow('SKU-5999');
        $this->assertSame([11, null, null], [(int) $late->remote_quantity, $late->remote_status, $late->last_seen_at]);
    }

    public function test_reading_the_same_report_twice_leaves_the_same_two_values(): void
    {
        $this->listing('SKU-1', quantity: 1);
        foreach (['REPORT-1', 'REPORT-2'] as $reportId) {
            $run = $this->requestedRun($reportId);
            $this->fakeDocument($this->report([['SKU-1', '7', 'Active']]), $reportId);
            app(AmazonReportRunner::class)->work($this->account);
            $this->assertSame(AmazonReportRun::READ, $run->fresh()->state);
        }

        $this->assertSame([7, 'Active'], [(int) $this->listingRow('SKU-1')->remote_quantity, $this->listingRow('SKU-1')->remote_status]);
        $this->assertSame(1, SalesChannelProductMapping::count());
    }

    public function test_the_settlement_reader_does_not_claim_to_be_batch_safe(): void
    {
        // It finds the settlement by the first row carrying a `settlement-id`, and only the first row of the file carries the
        // period and the deposit total — so a later batch would record a settlement with neither. The claim is a type, so
        // this cannot be switched on by configuration.
        $this->assertNotInstanceOf(ReadsAmazonReportsInBatches::class, app(AmazonSettlementReader::class));
        $this->assertInstanceOf(ReadsAmazonReportsInBatches::class, app(AmazonListingsReader::class));
    }

    /** @param array<string, mixed> $extra */
    private function listing(string $sku, int $quantity, array $extra = []): void
    {
        SalesChannelProductMapping::create($extra + [
            'account_id' => $this->account->id,
            'listing_key' => $sku,
            'external_sku' => $sku,
            'status' => SalesChannelProductMapping::UNMAPPED,
            'remote_quantity' => $quantity,
        ]);
    }

    private function listingRow(string $sku): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::where('account_id', $this->account->id)->where('listing_key', $sku)->firstOrFail();
    }

    /** @param list<list<string>> $rows */
    private function report(array $rows): string
    {
        $text = "seller-sku\tquantity\tstatus\n";
        foreach ($rows as $row) {
            $text .= implode("\t", $row) . "\n";
        }

        return $text;
    }

    private function requestedRun(string $reportId = 'REPORT-1'): AmazonReportRun
    {
        return AmazonReportRun::create([
            'seller_id' => 0,
            'account_id' => $this->account->id,
            'report_type' => AmazonReportTypes::ALL_LISTINGS,
            'request_key' => AmazonReportRun::keyFor((int) $this->account->id, AmazonReportTypes::ALL_LISTINGS, $reportId . ':2026-10-01..2026-10-31'),
            'requested' => true,
            'window_start' => CarbonImmutable::parse('2026-10-01'),
            'window_end' => CarbonImmutable::parse('2026-10-31'),
            'amazon_report_id' => $reportId,
            'state' => AmazonReportRun::REQUESTED,
            'requested_at' => now(),
        ]);
    }

    private function fakeDocument(string $contents, string $reportId = 'REPORT-1'): void
    {
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600]),
            self::REPORTS . '/*' => Http::response(['payload' => [
                'reportId' => $reportId,
                'reportType' => AmazonReportTypes::ALL_LISTINGS,
                'processingStatus' => 'DONE',
                'createdTime' => '2026-11-17T08:00:00Z',
                'reportDocumentId' => 'DOC-1',
            ]]),
            self::DOCUMENTS => Http::response(['payload' => ['reportDocumentId' => 'DOC-1', 'url' => 'https://amazon-reports.s3.example.test/doc.tsv?sig=1']]),
            // A closure, not one response: a streamed body is read once and detaches, so a second report must get its own.
            self::DOWNLOAD => static fn (): PromiseInterface => Http::response($contents),
        ]);
    }
}
