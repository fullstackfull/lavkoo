<?php

namespace Tests\Feature\SalesChannels;

use App\Models\AmazonReportRun;
use App\Models\OperationalCostInvoice;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Amazon\AmazonFlatFile;
use App\Services\SalesChannels\Amazon\AmazonReportRunner;
use App\Services\SalesChannels\Amazon\AmazonReports;
use App\Services\SalesChannels\Amazon\AmazonReportTypes;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * Reports 2021-06-30 — and the four things about it that would each be a silent wrong answer.
 *
 *  1. **A settlement report cannot be requested.** Amazon's own page: "Settlement reports cannot be requested or scheduled.
 *     They are automatically scheduled by Amazon. You can search for these reports using the getReports operation." So a reader
 *     that treated every report alike would send a call that can never work, once a minute, against an operation rated at
 *     0.0167 requests a second.
 *  2. **A lost reply to `createReport` means a report EXISTS under a number we never saw.** `requested_at` is written before the
 *     call, and from then on the row is found with `getReports` and never requested again — the same write-ahead marker the
 *     owner required of the noon shipments and the Odoo postings.
 *  3. **`DONE` does not mean there is a document**, and `UNKNOWN` is never "no report".
 *  4. **The documents fight the parser.** A UTF-8 BOM on the first header name, headers that may arrive in another locale, and
 *     amounts in local notation where `1.234,56` casts to `1.234` — all three are documented by Amazon, and all three are
 *     wrong figures or empty results rather than errors.
 *
 * Every fixture is shaped after the published Reports 2021-06-30 models and the two report pages. Stray requests are refused,
 * and nothing here proves a live seller account works.
 */
class AmazonReportsTest extends TestCase
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
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = $this->amazonAccount();
        $this->account->forceFill(['settings' => array_merge((array) $this->account->settings, ['marketplace_id' => 'A17E79C6D8DWNP'])])->save();
        $this->account = $this->account->fresh();
    }

    // ---- 1. the two flows are not the same flow -------------------------------------------------------------------

    public function test_a_settlement_report_is_never_requested_because_amazon_does_not_allow_it(): void
    {
        $this->fakeLwa();

        $answer = app(AmazonReports::class)->request($this->account, AmazonReportTypes::SETTLEMENT, ['A17E79C6D8DWNP']);

        $this->assertNull($answer['report_id']);
        $this->assertSame('amazon_report_type_is_not_requestable:' . AmazonReportTypes::SETTLEMENT, $answer['problem']);
        Http::assertNothingSent();
    }

    public function test_a_type_nobody_declared_is_refused_rather_than_tried(): void
    {
        $this->fakeLwa();

        $answer = app(AmazonReports::class)->request($this->account, 'GET_SOMETHING_INVENTED', ['A17E79C6D8DWNP']);

        $this->assertSame('amazon_report_type_not_declared:GET_SOMETHING_INVENTED', $answer['problem']);
        Http::assertNothingSent();
    }

    public function test_a_requestable_report_is_asked_for_with_one_marketplace(): void
    {
        // "You can only specify one Amazon store per report. When requesting a report with multiple marketplaceIds, only the
        // first marketplaceId in the list will be accepted." So four would have three silently ignored.
        $this->fakeLwa();
        Http::fake([self::REPORTS => Http::response(['reportId' => 'REPORT-1'], 202)]);

        $run = app(AmazonReportRunner::class)->ask(
            $this->account,
            AmazonReportTypes::ALL_LISTINGS,
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-31'),
        );

        $this->assertSame(AmazonReportRun::REQUESTED, $run->state);
        $this->assertSame('REPORT-1', $run->amazon_report_id);
        $body = $this->bodyOf(self::REPORTS);
        $this->assertSame([AmazonReportTypes::ALL_LISTINGS], [$body['reportType']]);
        $this->assertSame(['A17E79C6D8DWNP'], $body['marketplaceIds']);
    }

    public function test_the_same_report_asked_for_twice_is_one_row_and_one_request(): void
    {
        $this->fakeLwa();
        Http::fake([self::REPORTS => Http::response(['reportId' => 'REPORT-1'], 202)]);
        $runner = app(AmazonReportRunner::class);
        $from = CarbonImmutable::parse('2026-10-01');
        $to = CarbonImmutable::parse('2026-10-31');

        $first = $runner->ask($this->account, AmazonReportTypes::ALL_LISTINGS, $from, $to);
        $again = $runner->ask($this->account, AmazonReportTypes::ALL_LISTINGS, $from, $to);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, AmazonReportRun::count());
        $this->assertCount(1, $this->sentTo(self::REPORTS), 'createReport allows one call a minute; a second ask must not spend another');
    }

    // ---- 2. a lost reply means a report exists under a number we never saw ----------------------------------------

    public function test_a_lost_reply_never_requests_a_second_report(): void
    {
        $this->fakeLwa();
        Http::fake([self::REPORTS => Http::response('', 503)]);

        $run = app(AmazonReportRunner::class)->ask(
            $this->account,
            AmazonReportTypes::ALL_LISTINGS,
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-31'),
        );

        $this->assertSame(AmazonReportRun::UNKNOWN, $run->state, 'and UNKNOWN is never "there is no report"');
        $this->assertNotNull($run->requested_at, 'the marker was written BEFORE the call');
        $this->assertNull($run->amazon_report_id);
        $this->assertStringContainsString('never requested again', (string) $run->failure_detail);

        // It waits before looking again rather than hammering an operation rated at one call a minute.
        $this->assertNotNull($run->available_at);
        $this->assertSame(0, app(AmazonReportRunner::class)->work($this->account)['unknown'], 'not due yet');

        // And once it IS due, it is still not requested: it has no id to poll and its request may have landed, so the only way
        // forward is getReports.
        $this->travel(20)->minutes();
        $stats = app(AmazonReportRunner::class)->work($this->account);
        $this->assertSame(1, $stats['unknown']);
        $this->assertSame(0, $stats['polled'], 'there is no report id to poll');
        $this->assertCount(1, $this->sentTo(self::REPORTS), 'and createReport was called exactly once, ever');
    }

    public function test_getreports_recovers_the_report_whose_reply_was_lost(): void
    {
        $this->fakeLwa();
        Http::fake([self::REPORTS . '*' => Http::sequence()
            ->push('', 503)
            ->push(['payload' => ['reports' => [[
                'reportId' => 'REPORT-LOST',
                'reportType' => AmazonReportTypes::ALL_LISTINGS,
                'processingStatus' => 'IN_PROGRESS',
                'createdTime' => '2026-11-17T08:00:00Z',
                'dataStartTime' => '2026-10-01T00:00:00Z',
                'dataEndTime' => '2026-10-31T00:00:00Z',
            ]]]])]);
        $runner = app(AmazonReportRunner::class);
        $runner->ask($this->account, AmazonReportTypes::ALL_LISTINGS, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $found = $runner->discover($this->account, [AmazonReportTypes::ALL_LISTINGS], CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(1, $found['recovered']);
        $this->assertSame(0, $found['found'], 'it is the row that already existed, not a new one');
        $row = AmazonReportRun::firstOrFail();
        $this->assertSame('REPORT-LOST', $row->amazon_report_id);
        $this->assertSame(AmazonReportRun::REQUESTED, $row->state);
    }

    public function test_a_found_report_of_another_window_is_not_claimed_by_a_waiting_row(): void
    {
        // Attaching the wrong report would file somebody else's period under our name and hand a consumer rows for a window we
        // never asked about. Three things must agree: the account, the type, and the window Amazon itself states.
        $this->fakeLwa();
        Http::fake([self::REPORTS . '*' => Http::sequence()
            ->push('', 503)
            ->push(['payload' => ['reports' => [[
                'reportId' => 'REPORT-OTHER',
                'reportType' => AmazonReportTypes::ALL_LISTINGS,
                'processingStatus' => 'DONE',
                'createdTime' => '2026-11-17T08:00:00Z',
                'dataStartTime' => '2026-09-01T00:00:00Z',
                'dataEndTime' => '2026-09-30T00:00:00Z',
            ]]]])]);
        $runner = app(AmazonReportRunner::class);
        $runner->ask($this->account, AmazonReportTypes::ALL_LISTINGS, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $found = $runner->discover($this->account, [AmazonReportTypes::ALL_LISTINGS], CarbonImmutable::parse('2026-09-01'));

        $this->assertSame(0, $found['recovered']);
        $this->assertSame(1, $found['found'], 'it is recorded as a report of its own');
        $this->assertNull(AmazonReportRun::where('request_key', 'like', '%2026-10-01..2026-10-31')->firstOrFail()->amazon_report_id);
    }

    public function test_a_report_whose_window_amazon_does_not_state_is_never_claimed(): void
    {
        // `dataStartTime` and `dataEndTime` are optional on the schema, so a report without them cannot be shown to be ours.
        $this->fakeLwa();
        Http::fake([self::REPORTS . '*' => Http::sequence()
            ->push('', 503)
            ->push(['payload' => ['reports' => [[
                'reportId' => 'REPORT-NO-WINDOW',
                'reportType' => AmazonReportTypes::ALL_LISTINGS,
                'processingStatus' => 'DONE',
                'createdTime' => '2026-11-17T08:00:00Z',
            ]]]])]);
        $runner = app(AmazonReportRunner::class);
        $runner->ask($this->account, AmazonReportTypes::ALL_LISTINGS, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertSame(0, $runner->discover($this->account, [AmazonReportTypes::ALL_LISTINGS], CarbonImmutable::parse('2026-11-01'))['recovered']);
    }

    // ---- 3. DONE is not a document, and UNKNOWN is not an absence -------------------------------------------------

    public function test_done_with_no_document_is_an_empty_result_and_not_a_failure(): void
    {
        // Amazon's documented "automatic cancellation if there is no data to return", and `reportDocumentId` is optional on a
        // DONE report. There was simply nothing in the window.
        $run = $this->requestedRun();
        $this->fakeLwa();
        Http::fake([self::REPORTS . '/*' => Http::response(['payload' => ['reportId' => 'REPORT-1', 'reportType' => AmazonReportTypes::ALL_LISTINGS, 'processingStatus' => 'DONE', 'createdTime' => '2026-11-17T08:00:00Z']])]);

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, $stats['empty']);
        $this->assertSame(AmazonReportRun::EMPTY_RESULT, $run->fresh()->state);
        $this->assertSame('no_document', $run->fresh()->outcome);
    }

    public function test_a_status_nobody_could_read_leaves_the_row_asking_rather_than_finished(): void
    {
        $run = $this->requestedRun();
        $this->fakeLwa();
        Http::fake([self::REPORTS . '/*' => Http::response('', 503)]);

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, $stats['unknown']);
        $this->assertSame(AmazonReportRun::UNKNOWN, $run->fresh()->state);
        $this->assertNotNull($run->fresh()->available_at, 'it will ask again');
    }

    public function test_an_unknown_compression_is_refused_rather_than_decoded(): void
    {
        // GZIP is the only value Amazon's enum carries. Feeding anything else to a decoder returns bytes nobody could tell
        // from a corrupt document.
        $run = $this->requestedRun();
        $this->fakeLwa();
        Http::fake([
            self::REPORTS . '/*' => Http::response($this->doneReport()),
            self::DOCUMENTS => Http::response(['payload' => ['reportDocumentId' => 'DOC-1', 'url' => 'https://amazon-reports.s3.example.test/doc.tsv?sig=1', 'compressionAlgorithm' => 'BROTLI']]),
        ]);

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame('amazon_report_document_unreadable', $run->fresh()->failure_code);
        $this->assertStringContainsString('BROTLI', (string) $run->fresh()->failure_detail);
    }

    // ---- 4. the documents fight the parser ------------------------------------------------------------------------

    public function test_the_byte_order_mark_does_not_hide_the_first_column(): void
    {
        // "This report includes a UTF-8 Byte Order Mark (BOM) as the first 3 bytes." It rides on the first header name, so
        // every lookup of that column would miss -- quietly, because a missing column reads as an empty cell.
        $file = AmazonFlatFile::read("\xEF\xBB\xBFseller-sku\tquantity\tstatus\nSKU-1\t4\tActive\n", ['seller-sku']);

        $this->assertTrue($file->isReadable(), (string) $file->refusal);
        $this->assertSame('seller-sku', $file->header[0]);
        $this->assertSame('SKU-1', $file->cell($file->rows[0], 'seller-sku'));
    }

    public function test_a_header_in_another_locale_is_refused_and_not_read_as_an_empty_report(): void
    {
        // "Reports are not cached by locale. Requesting the same report within the cache period ... may return headers in a
        // different locale than requested." Parsing on would give rows whose every interesting cell was empty, which reads as
        // "this seller has no listings" -- the same mistake as reading UNKNOWN as ABSENT.
        $file = AmazonFlatFile::read("sku-vendeur\tquantite\tetat\nSKU-1\t4\tActif\n", ['seller-sku', 'quantity']);

        $this->assertFalse($file->isReadable());
        $this->assertSame('amazon_report_columns_not_found:seller-sku,quantity', $file->refusal);
        $this->assertSame([], $file->rows, 'and no row is handed on');
    }

    #[DataProvider('amounts')]
    public function test_an_amount_is_read_by_its_notation_and_never_cast(string $cell, ?string $expected, ?string $problem): void
    {
        // "amounts are displayed in local currency formats (for example, `95,00` for EU instead of `95.00`)." A cast of
        // '1.234,56' is 1.234 in PHP: a figure wrong by three orders of magnitude, in money, with nothing raised.
        $read = AmazonFlatFile::amount($cell);

        $this->assertSame($expected, $read['amount']);
        $this->assertSame($problem, $read['problem']);
    }

    public static function amounts(): array
    {
        return [
            'a European decimal comma' => ['95,00', '95.00', null],
            'European thousands and a decimal comma' => ['1.234,56', '1234.56', null],
            'the same figure written the other way' => ['1,234.56', '1234.56', null],
            'one separator and three digits is a thousands group' => ['1,234', '1234', null],
            'a credit' => ['-49.50', '-49.50', null],
            'a credit in brackets' => ['(12.30)', '-12.30', null],
            'nothing at all is not a zero' => ['', null, null],
            'a label where a figure should be' => ['abc', null, 'amazon_amount_has_no_digits'],
            'more precision than money has' => ['12.3456789', null, 'amazon_amount_not_understood'],
        ];
    }

    // ---- the settlement becomes a statement, and a statement is not a cost ----------------------------------------

    public function test_a_settlement_report_becomes_an_unapproved_marketplace_statement(): void
    {
        $run = $this->foundSettlementRun();
        $this->fakeLwa();
        $this->fakeDocument($this->settlementDocument());

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, $stats['read'], (string) $run->fresh()->failure_code);
        $invoice = OperationalCostInvoice::firstOrFail();
        $this->assertSame('marketplace_statement', $invoice->kind);
        $this->assertSame('amazon', $invoice->provider_code);
        $this->assertSame('SETTLEMENT-98765', $invoice->invoice_number);
        $this->assertNull($invoice->approved_at, 'nothing here approves anything');
        // Two fee rows, written positive because the ledger holds a cost as a positive figure.
        $this->assertSame(2, $invoice->items()->count());
        $this->assertSame('13.500000', $invoice->total);
        $this->assertStringContainsString('net deposit of 1200.00', (string) $invoice->notes);
    }

    public function test_the_same_settlement_read_twice_is_one_statement(): void
    {
        // Amazon documents no idempotency anywhere in Reports, so the settlement number it gave is what makes this safe.
        $this->fakeLwa();
        $this->fakeDocument($this->settlementDocument());
        $this->foundSettlementRun();
        app(AmazonReportRunner::class)->work($this->account);

        $second = $this->foundSettlementRun('REPORT-SETTLE-2');
        app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, OperationalCostInvoice::count());
        $this->assertSame('statement_already_imported', $second->fresh()->outcome);
    }

    public function test_a_label_amazon_invented_is_named_rather_than_filed_as_a_fee(): void
    {
        // Amazon's amount types are an open set and its own page says new ones will appear. A label nobody matched is news.
        $run = $this->foundSettlementRun();
        $this->fakeLwa();
        // Deliberately a label with no fee word in it: `ItemFee` would contain "fee" and the finance map would rightly
        // classify it, so the label that proves the unclassified path has to be one Amazon has genuinely never used.
        $this->fakeDocument($this->settlementDocument(extraRow: "\t\t\t\t\t\t\tORDER-9\t\t\t\t\tLevy\tQuantumHandling\t-3.00\t\t2026-10-20\t\t\t\t\t\t\t"));

        app(AmazonReportRunner::class)->work($this->account);

        $invoice = OperationalCostInvoice::firstOrFail();
        $this->assertStringContainsString('QuantumHandling', (string) $invoice->notes);
        $this->assertSame(2, $invoice->items()->count(), 'and it is not an invoice line');
    }

    public function test_a_report_type_with_no_reader_is_named_rather_than_counted_as_read(): void
    {
        // Fetchable, and nothing understands it. A row that said "read" would read as "the settlement was imported".
        $run = $this->requestedRun(AmazonReportTypes::FBA_INVENTORY);
        $this->fakeLwa();
        $this->fakeDocument("sku\tquantity\nSKU-1\t4\n");

        $stats = app(AmazonReportRunner::class)->work($this->account);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame('amazon_report_has_no_reader:' . AmazonReportTypes::FBA_INVENTORY, $run->fresh()->failure_code);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function requestedRun(string $reportType = AmazonReportTypes::ALL_LISTINGS, string $reportId = 'REPORT-1'): AmazonReportRun
    {
        return AmazonReportRun::create([
            'seller_id' => 0,
            'account_id' => $this->account->id,
            'report_type' => $reportType,
            'request_key' => AmazonReportRun::keyFor((int) $this->account->id, $reportType, '2026-10-01..2026-10-31'),
            'requested' => true,
            'window_start' => CarbonImmutable::parse('2026-10-01'),
            'window_end' => CarbonImmutable::parse('2026-10-31'),
            'amazon_report_id' => $reportId,
            'state' => AmazonReportRun::REQUESTED,
            'requested_at' => now(),
        ]);
    }

    /** A settlement row, as `discover()` records one: never requested, numbered by Amazon. */
    private function foundSettlementRun(string $reportId = 'REPORT-SETTLE'): AmazonReportRun
    {
        return AmazonReportRun::create([
            'seller_id' => 0,
            'account_id' => $this->account->id,
            'report_type' => AmazonReportTypes::SETTLEMENT,
            'request_key' => AmazonReportRun::keyFor((int) $this->account->id, AmazonReportTypes::SETTLEMENT, 'amazon:' . $reportId),
            'requested' => false,
            'amazon_report_id' => $reportId,
            'state' => AmazonReportRun::REQUESTED,
        ]);
    }

    /** @return array<string, mixed> */
    private function doneReport(string $documentId = 'DOC-1'): array
    {
        return ['payload' => [
            'reportId' => 'REPORT-1',
            'reportType' => AmazonReportTypes::ALL_LISTINGS,
            'processingStatus' => 'DONE',
            'createdTime' => '2026-11-17T08:00:00Z',
            'reportDocumentId' => $documentId,
        ]];
    }

    private function fakeDocument(string $contents): void
    {
        Http::fake([
            self::REPORTS . '/*' => Http::response($this->doneReport()),
            self::DOCUMENTS => Http::response(['payload' => ['reportDocumentId' => 'DOC-1', 'url' => 'https://amazon-reports.s3.example.test/doc.tsv?sig=1']]),
            self::DOWNLOAD => Http::response($contents),
        ]);
    }

    /**
     * A settlement document in the shape Amazon's page describes: one header row carrying the period and the deposit with no
     * amount of its own, then the amount rows.
     */
    private function settlementDocument(string $extraRow = ''): string
    {
        $header = implode("\t", [
            'settlement-id', 'settlement-start-date', 'settlement-end-date', 'deposit-date', 'total-amount', 'currency',
            'transaction-type', 'order-id', 'merchant-order-id', 'adjustment-id', 'shipment-id', 'marketplace-name',
            'amount-type', 'amount-description', 'amount', 'fulfillment-id', 'posted-date', 'posted-date-time',
            'order-item-code', 'merchant-order-item-id', 'merchant-adjustment-item-id', 'sku', 'quantity-purchased', 'promotion-id',
        ]);
        $rows = [
            // The settlement's own row: period, deposit, currency, and no amount.
            "98765\t2026-10-01\t2026-10-31\t2026-11-02\t1.200,00\tSAR\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t",
            // A commission and an FBA fee, both negative as Amazon writes a fee, and the second in European notation.
            "98765\t\t\t\t\tSAR\tOrder\tORDER-1\t\t\t\t\tItemFee\tCommission\t-10,00\t\t2026-10-05\t\t\t\t\tSKU-1\t1\t",
            "98765\t\t\t\t\tSAR\tOrder\tORDER-2\t\t\t\t\tItemFee\tFBAPerUnitFulfillmentFee\t-3.50\t\t2026-10-06\t\t\t\t\tSKU-2\t1\t",
            // A sale: real, and not a cost, so it is counted and never an invoice line.
            "98765\t\t\t\t\tSAR\tOrder\tORDER-1\t\t\t\t\tItemPrice\tPrincipal\t120.00\t\t2026-10-05\t\t\t\t\tSKU-1\t1\t",
        ];
        if ($extraRow !== '') {
            $rows[] = '98765' . $extraRow;
        }

        return $header . "\n" . implode("\n", $rows) . "\n";
    }

    private function fakeLwa(): void
    {
        Http::fake([self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600])]);
    }

    /** @return array<string, mixed> */
    private function bodyOf(string $url): array
    {
        $sent = $this->sentTo($url);
        $this->assertNotSame([], $sent, 'nothing was sent to ' . $url);

        return json_decode($sent[array_key_last($sent)]->body(), true) ?? [];
    }

    /** @return list<Request> */
    private function sentTo(string $url): array
    {
        $prefix = rtrim(str_replace('*', '', $url), '/');

        return Http::recorded(static fn (Request $request): bool => str_starts_with($request->url(), $prefix) && $request->method() !== 'GET')
            ->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
