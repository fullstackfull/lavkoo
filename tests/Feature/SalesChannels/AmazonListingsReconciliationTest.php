<?php

namespace Tests\Feature\SalesChannels;

use App\Models\AmazonReportRun;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Amazon\AmazonListingsReconciliation;
use App\Services\SalesChannels\Amazon\AmazonReportRunner;
use App\Services\SalesChannels\Amazon\AmazonReportTypes;
use App\Services\SalesChannels\Amazon\AmazonSettlementReader;
use App\Services\SalesChannels\Amazon\ReadsAmazonReportsInBatches;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * `GET_MERCHANT_LISTINGS_ALL_DATA` compared against the listings we hold — **a comparison, not a stock sync.**
 *
 * The owner chose this shape over mirroring the report's quantity onto the listing row, and the tests here are mostly about
 * holding it to that choice. The load-bearing one is `test_a_complete_document_writes_nothing_outside_the_run_row`: it
 * snapshots every table except `amazon_report_runs` before and after the read and demands they be identical, so no later
 * edit can quietly start writing `remote_quantity`, `remote_status` or `last_seen_at` — the columns that feed
 * `StockPlanner`, the publisher and what the channel is believed to be showing.
 *
 * The rest cover the ways a streamed comparison can lie: a document that stops mid-row must be recorded as a floor and NOT
 * as a finished comparison; a header in another locale must claim nothing at all; rows with no SKU must be counted rather
 * than ignored; a SKU Amazon repeats must be reported as the rows it is; and two runs must not share counters, because one
 * instance of the reader serves every run in a process.
 */
class AmazonListingsReconciliationTest extends TestCase
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
        config(['sales_channels.enabled' => true, 'sales_channels.amazon.listings_reconciliation_enabled' => true]);
        $this->account = $this->amazonAccount();
    }

    public function test_the_type_still_has_no_reader_while_the_switch_is_off(): void
    {
        config(['sales_channels.amazon.listings_reconciliation_enabled' => false]);
        $run = $this->requestedRun();
        $this->listing('SKU-1');
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active']]));

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame('amazon_report_has_no_reader:' . AmazonReportTypes::ALL_LISTINGS, $run->fresh()->failure_code);
    }

    public function test_a_complete_document_writes_nothing_outside_the_run_row(): void
    {
        // **The property the whole shape exists for.** Not "the quantity is right" — nothing is written at all.
        $run = $this->requestedRun();
        $this->listing('SKU-1', ['remote_quantity' => 3, 'remote_status' => 'Active', 'last_seen_at' => CarbonImmutable::parse('2026-11-01 00:00:00'), 'remote_price' => '41.500000', 'last_published_quantity' => 9]);
        $this->listing('SKU-2', ['remote_quantity' => 0, 'remote_status' => 'Incomplete']);
        $before = $this->everythingButTheRunRows();
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active'], ['SKU-2', '99', 'Inactive'], ['SKU-NOPE', '5', 'Active']]));

        app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame($before, $this->everythingButTheRunRows(), 'the comparison wrote somewhere it must never write');
        $fresh = $run->fresh();
        $this->assertSame([AmazonReportRun::READ, 'listings_reconciled'], [$fresh->state, $fresh->outcome]);
        $this->assertSame([3, 0], [$fresh->rows_read, $fresh->rows_refused]);
        $this->assertStringContainsString('Compared 3 rows: 2 name a listing we hold, 1 name one we do not', (string) $fresh->failure_detail);
        $this->assertStringContainsString('SKU-NOPE', (string) $fresh->failure_detail);
        $this->assertStringContainsString('No listing, stock or price figure was written', (string) $fresh->failure_detail);
    }

    public function test_a_catalogue_larger_than_one_batch_is_counted_once_and_still_writes_nothing(): void
    {
        $run = $this->requestedRun();
        $rows = [];
        for ($i = 1; $i <= 2500; $i++) {
            $rows[] = ['SKU-' . $i, (string) $i, 'Active'];
        }
        for ($i = 1; $i <= 1200; $i++) {
            $this->listing('SKU-' . $i);
        }
        $before = $this->everythingButTheRunRows();
        $this->fakeDocument($this->report($rows));

        app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame($before, $this->everythingButTheRunRows());
        $fresh = $run->fresh();
        $this->assertSame([AmazonReportRun::READ, 2500], [$fresh->state, $fresh->rows_read], 'a row was counted twice or lost across batches');
        $this->assertStringContainsString('Compared 2500 rows: 1200 name a listing we hold, 1300 name one we do not', (string) $fresh->failure_detail);
        // Streaming holds no document, so there is nothing honest to hash.
        $this->assertNull($fresh->document_hash);
    }

    public function test_a_document_that_stops_mid_row_is_a_floor_and_never_a_finished_comparison(): void
    {
        // Above one 64 KiB chunk and below the document, so rows really are handed over before the transport stops.
        config(['sales_channels.amazon.report_stream_max_bytes' => 100000]);
        $run = $this->requestedRun();
        $rows = [];
        for ($i = 1; $i <= 6000; $i++) {
            $rows[] = ['SKU-' . $i, (string) $i, 'Active'];
        }
        $this->listing('SKU-1');
        $before = $this->everythingButTheRunRows();
        $this->fakeDocument($this->report($rows));

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame($before, $this->everythingButTheRunRows());
        $fresh = $run->fresh();
        $this->assertSame(1, $stats['failed'], 'a truncated document was counted as read');
        $this->assertSame(AmazonReportRun::FAILED, $fresh->state, 'FAILED, so nothing downstream reads it as a finished comparison');
        $this->assertSame('listings_reconciliation_unconfirmed', $fresh->outcome);
        // One classifier for every unreadable document, whichever path fetched it, with the specific refusal kept in the
        // detail -- an operator who only ever sees the summary never learns WHY the document stopped.
        $this->assertSame('amazon_report_document_unreadable', $fresh->failure_code);
        $this->assertStringContainsString('stream_is_larger_than_we_will_read', (string) $fresh->failure_detail);
        $this->assertStringContainsString('stopped part way', (string) $fresh->failure_detail);
        $this->assertStringContainsString('floors and NOT a complete comparison', (string) $fresh->failure_detail);
        $this->assertGreaterThan(0, $fresh->rows_read, 'the rows it did see are real and must be admitted');
        $this->assertLessThan(6000, $fresh->rows_read);
    }

    public function test_a_transfer_that_ends_short_of_what_it_promised_is_never_a_finished_comparison(): void
    {
        // Rule 5, through the whole path. A truncated S3 transfer that closes cleanly reports no error of its own: without
        // the length check the run would be stored `read` / `listings_reconciled`, claiming a comparison of a catalogue
        // most of which never arrived. The catalogue holds SKU-1 only, so a cut document also under-reports what Amazon
        // knows -- the quiet way a reconciliation lies.
        $run = $this->requestedRun();
        $this->listing('SKU-1');
        $before = $this->everythingButTheRunRows();
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['SKU-' . $i, '5', 'Active'];
        }
        $whole = $this->report($rows);
        $this->fakeDocument(substr($whole, 0, (int) (strlen($whole) * 0.5)), 'REPORT-1', strlen($whole));

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame($before, $this->everythingButTheRunRows(), 'a cut document still wrote outside the run row');
        $fresh = $run->fresh();
        $this->assertSame(1, $stats['failed'], 'a cut transfer was counted as read');
        $this->assertSame(AmazonReportRun::FAILED, $fresh->state);
        $this->assertSame('listings_reconciliation_unconfirmed', $fresh->outcome, 'a cut transfer was recorded as a finished comparison');
        $this->assertSame('amazon_report_document_unreadable', $fresh->failure_code);
        $this->assertStringContainsString('stream_ended_early:', (string) $fresh->failure_detail, 'the run does not say the transfer ended early');
        $this->assertStringContainsString('floors and NOT a complete comparison', (string) $fresh->failure_detail);
        $this->assertNull($fresh->read_at, 'read_at on a document that never finished arriving');
    }

    public function test_a_header_in_another_locale_claims_nothing_at_all(): void
    {
        $run = $this->requestedRun();
        $this->listing('SKU-1');
        $before = $this->everythingButTheRunRows();
        $this->fakeDocument("sku-del-vendedor\tcantidad\testado\nSKU-1\t7\tActivo\n");

        app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame($before, $this->everythingButTheRunRows());
        $fresh = $run->fresh();
        $this->assertSame([AmazonReportRun::FAILED, 'listings_reconciliation_refused'], [$fresh->state, $fresh->outcome]);
        $this->assertSame('amazon_report_document_unreadable', $fresh->failure_code);
        $this->assertStringContainsString('amazon_report_columns_not_found', (string) $fresh->failure_detail);
        $this->assertStringContainsString('nothing is claimed', (string) $fresh->failure_detail);
        $this->assertSame(0, $fresh->rows_read);
    }

    public function test_rows_with_no_sku_are_counted_rather_than_passed_over(): void
    {
        $run = $this->requestedRun();
        $this->listing('SKU-1');
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active'], ['', '3', 'Active'], ['   ', '4', 'Active']]));

        app(AmazonReportRunner::class)->work($this->account);

        $fresh = $run->fresh();
        $this->assertSame([1, 2], [$fresh->rows_read, $fresh->rows_refused]);
        $this->assertStringContainsString('2 rows carried no SKU and were not compared', (string) $fresh->failure_detail);
        $this->assertSame('listings_reconciled', $fresh->outcome);
    }

    public function test_a_sku_amazon_repeats_is_reported_as_the_rows_it_is(): void
    {
        $run = $this->requestedRun();
        $this->listing('SKU-1');
        $this->fakeDocument($this->report([['SKU-1', '7', 'Active'], ['SKU-1', '7', 'Active'], ['SKU-NOPE', '1', 'Active'], ['SKU-NOPE', '1', 'Active']]));

        app(AmazonReportRunner::class)->work($this->account);

        $fresh = $run->fresh();
        $this->assertSame(4, $fresh->rows_read, 'a repeated SKU was collapsed, so the document looked shorter than it is');
        $this->assertStringContainsString('Compared 4 rows: 2 name a listing we hold, 2 name one we do not', (string) $fresh->failure_detail);
    }

    public function test_reading_the_same_report_twice_changes_nothing_and_counts_the_same(): void
    {
        $this->listing('SKU-1');
        $before = $this->everythingButTheRunRows();
        $details = [];
        foreach (['REPORT-1', 'REPORT-2'] as $reportId) {
            $run = $this->requestedRun($reportId);
            $this->fakeDocument($this->report([['SKU-1', '7', 'Active'], ['SKU-NOPE', '2', 'Active']]), $reportId);
            app(AmazonReportRunner::class)->work($this->account);
            $this->assertSame(AmazonReportRun::READ, $run->fresh()->state);
            $details[] = (string) $run->fresh()->failure_detail;
        }

        $this->assertSame($before, $this->everythingButTheRunRows());
        $this->assertSame($details[0], $details[1], 'the second read of the same catalogue said something different');
    }

    public function test_two_runs_do_not_share_the_readers_counters(): void
    {
        // In production ONE reader serves every run in the process: `SalesChannelsAmazonReportsCommand` injects a single
        // `AmazonReportRunner` and `work()` loops the due rows through the consumer list it was built with. The container
        // binding is not shared, so two `app()` resolutions would hand out two readers and this test would pass no matter
        // how the reader held its counters. The one instance is pinned here so the shared-state condition is the one
        // actually under test: the tally is keyed by run and released in `finish()`, and were it a plain property the
        // second run would inherit the first one's totals.
        $reader = new AmazonListingsReconciliation();
        $this->app->instance(AmazonListingsReconciliation::class, $reader);

        $this->listing('SKU-1');
        $first = $this->requestedRun('REPORT-1');
        $this->fakeDocument($this->report([['SKU-1', '1', 'Active'], ['SKU-NOPE', '1', 'Active']]), 'REPORT-1');
        $firstRunner = app(AmazonReportRunner::class);
        $firstRunner->work($this->account);

        $second = $this->requestedRun('REPORT-2');
        $this->fakeDocument($this->report([['SKU-1', '1', 'Active']]), 'REPORT-2');
        $secondRunner = app(AmazonReportRunner::class);
        $secondRunner->work($this->account);

        $this->assertSame($reader, app(AmazonListingsReconciliation::class), 'the pinned reader was not the one both runs used');
        $this->assertSame(2, $first->fresh()->rows_read);
        $this->assertSame(1, $second->fresh()->rows_read, 'the second run inherited the first run\'s counters');
        $this->assertStringContainsString('Compared 1 rows: 1 name a listing we hold, 0 name one we do not', (string) $second->fresh()->failure_detail);
    }

    public function test_one_reader_serving_two_due_runs_in_a_single_pass_counts_each_on_its_own(): void
    {
        // The real loop: both runs are due at once, so a single `work()` call carries them through the same reader one
        // after the other. This is the exact shape the daily command runs in, and the only one in which a leaked tally
        // would reach a stored row.
        $reader = new AmazonListingsReconciliation();
        $this->app->instance(AmazonListingsReconciliation::class, $reader);

        $this->listing('SKU-1');
        $first = $this->requestedRun('REPORT-1');
        $second = $this->requestedRun('REPORT-2');
        $this->fakeTwoDocuments(
            [$this->report([['SKU-1', '1', 'Active'], ['SKU-NOPE', '1', 'Active']]), 'REPORT-1'],
            [$this->report([['SKU-1', '1', 'Active']]), 'REPORT-2'],
        );

        app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(AmazonReportRun::READ, $first->fresh()->state);
        $this->assertSame(AmazonReportRun::READ, $second->fresh()->state);
        $this->assertSame(2, $first->fresh()->rows_read);
        $this->assertSame(1, $second->fresh()->rows_read, 'the second run of one pass inherited the first run\'s counters');
        $this->assertStringContainsString('Compared 2 rows', (string) $first->fresh()->failure_detail);
        $this->assertStringContainsString('Compared 1 rows', (string) $second->fresh()->failure_detail);
    }

    public function test_the_settlement_reader_is_not_streamed_and_the_comparison_is(): void
    {
        // The settlement reader finds its settlement by scanning the document and only the first row carries the period and
        // the deposit total, so a later batch would record a settlement with neither. The claim is a type, so no
        // configuration change can put it on the streaming path.
        $this->assertNotInstanceOf(ReadsAmazonReportsInBatches::class, app(AmazonSettlementReader::class));
        $this->assertInstanceOf(ReadsAmazonReportsInBatches::class, app(AmazonListingsReconciliation::class));
    }

    /**
     * Every row of every table except the report runs, as one string.
     *
     * Deliberately not a list of columns to check: a future edit that starts writing a NEW field would pass a narrower
     * assertion and fail this one.
     */
    private function everythingButTheRunRows(): string
    {
        $all = '';
        foreach (Schema::getTables(schema: Schema::getCurrentSchemaListing()) as $table) {
            if (in_array($table['name'], ['amazon_report_runs', 'migrations', 'sales_channel_api_logs'], true)) {
                continue;
            }
            $rows = array_map(static function (object $row): array {
                $fields = (array) $row;
                // `AmazonLwaAuth` keeps the one-hour access token in the account's own encrypted credentials, so ANY run
                // that signs in rewrites this column -- with a different IV each time, since encryption is randomised.
                // That is the sign-in doing its job, not the comparison writing: the value is normalised so the assertion
                // keeps its meaning instead of being relaxed to exclude the whole accounts table.
                if (array_key_exists('credentials', $fields)) {
                    $fields['credentials'] = $fields['credentials'] === null ? null : '<encrypted>';
                }

                return $fields;
            }, DB::table($table['name'])->get()->all());

            $all .= $table['name'] . json_encode($rows, JSON_UNESCAPED_UNICODE);
        }

        return $all;
    }

    /** @param array<string, mixed> $overrides */
    private function listing(string $sku, array $overrides = []): void
    {
        SalesChannelProductMapping::create($overrides + [
            'account_id' => $this->account->id,
            'listing_key' => $sku,
            'external_sku' => $sku,
            'status' => SalesChannelProductMapping::UNMAPPED,
        ]);
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

    /**
     * Two reports, each with its own document and its own body, so a single `work()` pass reads both.
     *
     * @param array{0: string, 1: string} $first contents, report id
     * @param array{0: string, 1: string} $second contents, report id
     */
    private function fakeTwoDocuments(array $first, array $second): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        $stubs = [self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600])];
        foreach ([[$first, 'DOC-1', 'one'], [$second, 'DOC-2', 'two']] as [[$contents, $reportId], $documentId, $slug]) {
            $url = 'https://amazon-reports.s3.example.test/' . $slug . '.tsv?sig=1';
            $stubs[self::REPORTS . '/' . $reportId] = Http::response(['payload' => [
                'reportId' => $reportId,
                'reportType' => AmazonReportTypes::ALL_LISTINGS,
                'processingStatus' => 'DONE',
                'createdTime' => '2026-11-17T08:00:00Z',
                'reportDocumentId' => $documentId,
            ]]);
            $stubs[self::SP . '/reports/2021-06-30/documents/' . $documentId] = Http::response(['payload' => ['reportDocumentId' => $documentId, 'url' => $url]]);
            // A closure per body, because a streamed body is read once and then detaches.
            $stubs['https://amazon-reports.s3.example.test/' . $slug . '.tsv*'] = static fn (): PromiseInterface => Http::response($contents);
        }
        Http::fake($stubs);
    }

    /** @param int|null $promisedBytes the `Content-Length` to advertise, for staging a transfer that ends short of it */
    private function fakeDocument(string $contents, string $reportId = 'REPORT-1', ?int $promisedBytes = null): void
    {
        // A fresh factory, because `Http::fake()` APPENDS stubs: faking the same URL again would leave the earlier stub
        // matching first, and a second report would silently be served the first one's body.
        Http::swap(new Factory());
        Http::preventStrayRequests();
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
            // A closure, not one response: a streamed body is read once and detaches, so a second report needs its own.
            self::DOWNLOAD => static fn (): PromiseInterface => Http::response(
                $contents,
                200,
                $promisedBytes === null ? [] : ['Content-Length' => (string) $promisedBytes],
            ),
        ]);
    }
}
