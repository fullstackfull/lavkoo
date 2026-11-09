<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Amazon\AmazonFinanceGroups;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * Amazon's settlement periods by their own identity, and the four published facts that each turn into a wrong answer.
 *
 * Every parameter, field and limit below is from `models/finances-api-model/financesV0.json` in Amazon's own
 * `amzn/selling-partner-api-models`, read 2026-11-22.
 *
 *  1. **A window wider than 180 days returns NOTHING and no error.** Amazon: "If `FinancialEventGroupStartedAfter` and
 *     `FinancialEventGroupStartedBefore` are more than 180 days apart, no financial event groups are returned." A clean 200
 *     with an empty list is indistinguishable from a seller who has never been paid.
 *  2. **Both bounds must be more than two minutes old**, which a nightly job asking up to "now" will trip every time.
 *  3. **`FundTransferDate` is "Only present for closed settlements"**, so its absence means still open and not unpaid.
 *  4. **`FundTransferStatus` has no documented values at all**, so it is carried and never interpreted; `ProcessingStatus`
 *     has exactly two and a third is UNKNOWN.
 */
class AmazonFinanceGroupsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const GROUPS = 'https://sellingpartnerapi-eu.amazon.com/finances/v0/financialEventGroups*';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00 UTC'));
        config(['sales_channels.enabled' => true]);
        $this->account = $this->amazonAccount();
        Http::fake(['https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600])]);
    }

    public function test_a_closed_period_is_read_with_its_identity_and_its_transfer(): void
    {
        Http::fake([self::GROUPS => Http::response(['payload' => ['FinancialEventGroupList' => [[
            'FinancialEventGroupId' => 'GROUP-1',
            'ProcessingStatus' => 'Closed',
            'FundTransferStatus' => 'Successful',
            'OriginalTotal' => ['CurrencyAmount' => 1234.56, 'CurrencyCode' => 'SAR'],
            'ConvertedTotal' => ['CurrencyAmount' => 1234.56, 'CurrencyCode' => 'SAR'],
            'BeginningBalance' => ['CurrencyAmount' => 0.0, 'CurrencyCode' => 'SAR'],
            'FundTransferDate' => '2026-11-10T00:00:00Z',
            'FinancialEventGroupStart' => '2026-10-27T00:00:00Z',
            'FinancialEventGroupEnd' => '2026-11-10T00:00:00Z',
            'TraceId' => '1234567890',
            'AccountTail' => '1234',
        ]]]])]);

        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now()->subHour());

        $this->assertNull($result['problem']);
        $group = $result['groups'][0];
        $this->assertSame('GROUP-1', $group['id']);
        $this->assertSame('Closed', $group['status']);
        $this->assertSame('Successful', $group['transfer_status']);
        $this->assertSame(1234.56, $group['original_total']['amount']);
        $this->assertSame('SAR', $group['original_total']['currency']);
        $this->assertSame('2026-11-10T00:00:00Z', $group['transferred_at']);
        $this->assertTrue(AmazonFinanceGroups::settled($group));
        // The seller's own bank-account tail is read and dropped: nothing here needs it, and the worst place for it is a
        // column nobody remembers is there.
        $this->assertArrayNotHasKey('account_tail', $group);
    }

    public function test_a_window_wider_than_amazon_answers_is_refused_before_the_call(): void
    {
        // **The silent-empty trap.** Amazon returns a clean 200 with no groups for a window over 180 days, which reads as a
        // seller who has never been paid. Nobody may ever see that answer, so the window is refused locally.
        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(200), now()->subHour());

        $this->assertNull($result['groups']);
        $this->assertStringStartsWith('amazon_finance_group_window_is_wider_than_amazon_answers', (string) $result['problem']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'financialEventGroups'));
    }

    public function test_a_window_that_runs_up_to_now_is_refused(): void
    {
        // Amazon requires both bounds to be "more than two minutes before the time of request". A nightly job asking up to
        // the present moment is the ordinary way to trip it, and the answer is an InvalidInput rather than data.
        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now());

        $this->assertNull($result['groups']);
        $this->assertSame('amazon_finance_group_window_ends_too_close_to_now', $result['problem']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'financialEventGroups'));
    }

    public function test_a_backwards_window_is_refused(): void
    {
        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subHour(), now()->subDays(30));

        $this->assertSame('amazon_finance_group_window_ends_before_it_starts', $result['problem']);
    }

    public function test_an_open_period_with_no_transfer_date_is_not_read_as_unpaid(): void
    {
        // Amazon: `FundTransferDate` is "Only present for closed settlements". Absence means the period is still open.
        Http::fake([self::GROUPS => Http::response(['payload' => ['FinancialEventGroupList' => [[
            'FinancialEventGroupId' => 'GROUP-OPEN',
            'ProcessingStatus' => 'Open',
            'FinancialEventGroupStart' => '2026-11-10T00:00:00Z',
            'BeginningBalance' => ['CurrencyAmount' => 10.0, 'CurrencyCode' => 'SAR'],
        ]]]])]);

        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now()->subHour());

        $group = $result['groups'][0];
        $this->assertSame('Open', $group['status']);
        $this->assertNull($group['transferred_at']);
        $this->assertNull($group['closed_at']);
        $this->assertFalse(AmazonFinanceGroups::settled($group), 'an open period was read as settled');
        // And a total Amazon did not send is null, not zero: a missing total is not a total of nothing.
        $this->assertNull($group['original_total']['amount']);
    }

    public function test_a_processing_status_amazon_does_not_publish_is_unknown_rather_than_forced(): void
    {
        // The documented enum is exactly `Open` and `Closed`. A third value is something we were not told.
        Http::fake([self::GROUPS => Http::response(['payload' => ['FinancialEventGroupList' => [[
            'FinancialEventGroupId' => 'GROUP-X', 'ProcessingStatus' => 'Reconciling', 'FundTransferDate' => '2026-11-10T00:00:00Z',
        ]]]])]);

        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now()->subHour());

        $this->assertNull($result['groups'][0]['status']);
        $this->assertFalse(AmazonFinanceGroups::settled($result['groups'][0]), 'an unknown status with a transfer date was read as settled');
    }

    public function test_the_window_and_the_page_size_go_as_amazon_documents_them(): void
    {
        Http::fake([self::GROUPS => Http::response(['payload' => ['FinancialEventGroupList' => []]])]);

        app(AmazonFinanceGroups::class)->opened($this->account, CarbonImmutable::parse('2026-10-01 00:00:00 UTC'), CarbonImmutable::parse('2026-11-01 00:00:00 UTC'), perPage: 9999);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return ($data['FinancialEventGroupStartedAfter'] ?? null) === '2026-10-01T00:00:00Z'
                && ($data['FinancialEventGroupStartedBefore'] ?? null) === '2026-11-01T00:00:00Z'
                // Amazon's cap is 100; the caller asked for far more.
                && (int) ($data['MaxResultsPerPage'] ?? 0) === 100;
        });
    }

    public function test_a_next_token_is_carried_with_the_same_arguments(): void
    {
        // Amazon: "call the operation with this token and include the same arguments as the call that produced the token".
        Http::fake([self::GROUPS => Http::response(['payload' => ['FinancialEventGroupList' => [], 'NextToken' => 'TOKEN-2']])]);

        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now()->subHour(), nextToken: 'TOKEN-1');

        $this->assertSame('TOKEN-2', $result['next_token']);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return ($data['NextToken'] ?? null) === 'TOKEN-1' && isset($data['FinancialEventGroupStartedAfter'], $data['FinancialEventGroupStartedBefore']);
        });
    }

    public function test_an_empty_period_list_is_an_answer_in_its_own_right(): void
    {
        Http::fake([self::GROUPS => Http::response(['payload' => ['FinancialEventGroupList' => []]])]);

        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now()->subHour());

        $this->assertSame([], $result['groups'], 'an empty period list was not reported as one');
        $this->assertNull($result['problem']);
    }

    public function test_a_failed_read_is_never_an_empty_period_list(): void
    {
        // Its own test rather than a second half of the one above: `Http::fake()` APPENDS and the first matching stub wins,
        // so a 503 registered after an empty-list stub for the same URL would never be served -- and the test would pass by
        // asserting nothing.
        Http::fake([self::GROUPS => Http::response('service unavailable', 503)]);

        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now()->subHour());

        $this->assertNull($result['groups'], 'a 503 was read as "this seller has no settlement periods"');
        $this->assertStringStartsWith('amazon_finance_groups_could_not_be_read', (string) $result['problem']);
    }

    public function test_a_body_without_the_documented_list_is_refused(): void
    {
        Http::fake([self::GROUPS => Http::response(['payload' => ['somethingElse' => []]])]);

        $result = app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now()->subHour());

        $this->assertNull($result['groups']);
        $this->assertSame('amazon_finance_groups_reply_is_not_the_documented_shape', $result['problem']);
    }

    public function test_the_bank_account_tail_never_reaches_the_api_log(): void
    {
        // It is the only field in this response nobody should keep. The log is the place it would end up by accident.
        Http::fake([self::GROUPS => Http::response(['payload' => ['FinancialEventGroupList' => [[
            'FinancialEventGroupId' => 'GROUP-1', 'ProcessingStatus' => 'Closed', 'AccountTail' => '987654',
        ]]]])]);

        app(AmazonFinanceGroups::class)->opened($this->account, now()->subDays(30), now()->subHour());

        $logged = \App\Models\SalesChannelApiLog::where('operation', 'amazon.finances_groups')->firstOrFail();
        $this->assertStringNotContainsString('987654', (string) json_encode($logged->getAttributes()), "the seller's bank-account tail was written to the log");
        // The line still exists, and still says what was asked: a call nobody can reconstruct is its own problem.
        $this->assertStringContainsString('FinancialEventGroupId', (string) $logged->response_summary);
    }

    public function test_this_is_a_read(): void
    {
        $this->assertSame(ChannelOperations::READ, ChannelOperations::kindOf('amazon.finances_groups', 'GET'));
        $this->assertFalse(ChannelOperations::methodAllowed('amazon.finances_groups', 'POST'));
    }
}
