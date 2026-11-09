<?php

namespace Tests\Feature\SalesChannels;

use App\Models\AmazonFeedSubmission;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Amazon\AmazonFeedState;
use App\Services\SalesChannels\Amazon\AmazonFeedSubmitter;
use App\Services\SalesChannels\Amazon\AmazonListingsFeed;
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
 * The five calls that submit a `JSON_LISTINGS_FEED`, and the four ways they can go wrong without anybody noticing.
 *
 * `AmazonListingsFeed` and `AmazonFeedResult` were already built and tested. What was missing was the cycle between them, and
 * the thing that makes the cycle dangerous is this: **Amazon documents no idempotency for `createFeed`.** Its whole published
 * request body is `feedType`, `marketplaceIds`, `inputFeedDocumentId` and an optional `feedOptions`. Nothing refuses a second
 * submission of the same document, so one lost reply means a second feed of up to 25,000 price and quantity changes queued
 * behind the first.
 *
 * So these tests are mostly about the moments nobody is watching:
 *
 *  1. **A lost reply is not a call that was never made.** The row is fenced before the request. A connection that dies leaves
 *     `may_have_submitted`, and nothing in this system submits that row again.
 *  2. **Two workers, one batch.** `submission_key` is unique, and the claim is a conditional update whose affected-row count
 *     is checked, so the second worker is told the work is somebody's rather than sending a second feed.
 *  3. **Partial success is the normal answer.** The report names failures by `messageId` and names no successes, so the
 *     accepted set is a subtraction — refused outright when the arithmetic does not agree.
 *  4. **`FATAL` is not a failure to retry.** Amazon states that with it "some, none, or all" of the feed may have applied.
 *     That is an unknown outcome over every SKU the feed carried, and it goes to a person.
 *
 * Every fixture is shaped after Amazon's own published `feeds_2021-06-30` model and the schemas in its
 * `amzn/selling-partner-api-models` repository. Stray requests are refused. **Nothing here sends a real feed**, and the path
 * ships switched off — which is itself asserted below.
 */
class AmazonFeedSubmissionTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const LWA = 'https://api.amazon.com/auth/o2/token';

    private const SP = 'https://sellingpartnerapi-eu.amazon.com';

    private const DOCUMENTS = self::SP . '/feeds/2021-06-30/documents';

    private const FEEDS = self::SP . '/feeds/2021-06-30/feeds';

    private const UPLOAD = 'https://amazon-feeds.s3.example.test/upload*';

    private const DOWNLOAD = 'https://amazon-feeds.s3.example.test/report*';

    private SalesChannelAccount $account;

    /** What the next `getFeed` answers. A test reassigns it; see `fakeTheWholeCycle`. */
    private \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface $pollResponse;

    /** What the next `getFeedDocument` answers. */
    private \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface $documentResponse;

    /** The bytes the presigned download hands back. */
    private string $report = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-22 09:00:00 UTC'));
        config([
            'sales_channels.enabled' => true,
            'sales_channels.external_writes_enabled' => true,
            // The path's own switch. It ships OFF -- `test_the_bulk_feed_path_ships_switched_off` reads the file to prove it.
            'sales_channels.amazon.bulk_feeds_enabled' => true,
            // The real minimum is 1,500, which would make every fixture here 1,500 messages long.
            // `test_a_feed_smaller_than_amazons_own_minimum_is_refused` asserts the shipped value.
            'sales_channels.amazon.feed_minimum_messages' => 1,
        ]);
        $this->account = $this->amazonAccount();
        $this->pollResponse = Http::response(['feedId' => 'feed-1', 'feedType' => 'JSON_LISTINGS_FEED', 'processingStatus' => 'DONE', 'createdTime' => now()->toIso8601ZuluString(), 'resultFeedDocumentId' => 'report-1']);
        $this->documentResponse = Http::response(['feedDocumentId' => 'report-1', 'url' => 'https://amazon-feeds.s3.example.test/report.json']);
    }

    // ---- what ships ----------------------------------------------------------------------------------------------

    public function test_the_bulk_feed_path_ships_switched_off(): void
    {
        // Read from the file, not the live config, because this suite turns it on for its own tests. One accepted feed carries
        // up to 25,000 price and quantity changes and `cancelFeed` works on queued feeds only, so it is its own decision.
        $shipped = require base_path('config/sales_channels.php');

        $this->assertFalse((bool) $shipped['amazon']['bulk_feeds_enabled'], 'AMAZON_BULK_FEEDS_ENABLED ships on');
        $this->assertSame(1500, $shipped['amazon']['feed_minimum_messages'], "Amazon's own suggested minimum is 1,500");
    }

    public function test_nothing_is_recorded_while_the_switch_is_off(): void
    {
        config(['sales_channels.amazon.bulk_feeds_enabled' => false]);

        $result = app(AmazonFeedSubmitter::class)->intend($this->account, $this->aFeed(), 'batch-1');

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_bulk_feeds_are_switched_off', $result['refusal']);
        // Refused before a row exists: an installation that has not turned this on must not accumulate half-built
        // submissions waiting for somebody to turn it on later.
        $this->assertSame(0, AmazonFeedSubmission::count());
        Http::assertNothingSent();
    }

    public function test_a_feed_smaller_than_amazons_own_minimum_is_refused(): void
    {
        // Amazon's own comparison: the per-SKU API "is faster with less than 1,500 items during a five-minute period". A
        // smaller feed spends one of five submissions per five minutes to be slower, so the caller is pointed at the path
        // that is already built rather than handed the wrong tool.
        config(['sales_channels.amazon.feed_minimum_messages' => 1500]);

        $result = app(AmazonFeedSubmitter::class)->intend($this->account, $this->aFeed(['SKU-1', 'SKU-2']), 'batch-small');

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_feed_is_smaller_than_the_per_sku_path_handles_faster:2', $result['refusal']);
        $this->assertSame(0, AmazonFeedSubmission::count());
        Http::assertNothingSent();
    }

    // ---- 1. the whole cycle, once ---------------------------------------------------------------------------------

    public function test_the_five_calls_run_in_amazons_published_order_and_record_each_step(): void
    {
        $this->fakeTheWholeCycle();
        $submitter = app(AmazonFeedSubmitter::class);
        $feed = $this->aFeed(['SKU-1', 'SKU-2', 'SKU-3']);

        $intended = $submitter->intend($this->account, $feed, 'batch-1', marketplaceId: 'A17E79C6D8DWNP');
        $this->assertTrue($intended['ok'], (string) $intended['refusal']);
        $submission = $submitter->claim($intended['submission']);
        $this->assertNotNull($submission);

        // Step 1: the document. Both deadlines Amazon states are written before anything is uploaded.
        $created = $submitter->createDocument($this->account, $submission);
        $this->assertTrue($created['ok'], (string) $created['refusal']);
        $submission = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::DOCUMENT_CREATED, $submission->state);
        $this->assertSame('doc-1', $submission->feed_document_id);
        $this->assertTrue($submission->document_expires_at->equalTo(now()->addHours(48)), 'the two days Amazon gives a feedDocumentId were not recorded');
        $this->assertTrue($submission->upload_url_expires_at->lessThanOrEqualTo(now()->addMinutes(5)), 'the five minutes Amazon gives the URL were not recorded');

        // Step 3: the upload. Still nothing is queued at Amazon.
        $uploaded = $submitter->upload($this->account, $submission, $feed, $created['url']);
        $this->assertTrue($uploaded['ok'], (string) $uploaded['refusal']);
        $this->assertSame(AmazonFeedSubmission::UPLOADED, $submission->fresh()->state);

        // Step 4: the submission.
        $submitted = $submitter->submit($this->account, $submission->fresh(), ['A17E79C6D8DWNP']);
        $this->assertTrue($submitted['ok'], (string) $submitted['refusal']);
        $submission = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::SUBMITTED, $submission->state);
        $this->assertSame('feed-1', $submission->amazon_feed_id);

        // Step 5: the poll.
        $this->travel(2)->minutes();
        $state = $submitter->poll($this->account, $submission);
        $this->assertSame(AmazonFeedState::DONE, $state->state);
        $this->assertSame('report-1', $submission->fresh()->result_document_id);

        // Steps 6 and 7: the report.
        $report = $submitter->report($this->account, $submission->fresh());
        $this->assertNull($report['problem']);
        $recorded = $submitter->recordReport($submission->fresh(), (string) $report['contents']);
        $this->assertTrue($recorded['ok'], (string) $recorded['refusal']);
        $this->assertSame(AmazonFeedSubmission::READ, $submission->fresh()->state);

        // The upload carried the content type the document was created for -- Amazon: "The contentType must match the input
        // provided to the createFeedDocument operation."
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://amazon-feeds.s3.example.test/upload')
                && $request->method() === 'PUT'
                && $request->header('Content-Type')[0] === AmazonFeedSubmitter::CONTENT_TYPE;
        });
        // And `createFeedDocument` asked for that same type: it is the only field on its request body, and required.
        Http::assertSent(fn (Request $request): bool => $request->url() === self::DOCUMENTS && $request->data() === ['contentType' => AmazonFeedSubmitter::CONTENT_TYPE]);
    }

    public function test_acceptance_is_recorded_as_acceptance_and_never_as_a_live_price(): void
    {
        // Amazon's own description of the count this reads: "Number of messages that were accepted, the associated updates
        // **are being applied**". Present tense, its words. So a read report records what Amazon accepted and this table says
        // nothing whatever about what a listing now shows.
        $this->fakeTheWholeCycle();
        [$submitter, $submission] = $this->runUpToTheReport(['SKU-1', 'SKU-2', 'SKU-3']);

        $report = $submitter->report($this->account, $submission->fresh());
        $recorded = $submitter->recordReport($submission->fresh(), (string) $report['contents']);

        $this->assertSame(['SKU-1', 'SKU-3'], $recorded['accepted']);
        $this->assertSame(['SKU-2' => "'[item_package_quantity]' is required but not supplied."], $recorded['failed']);
        $row = $submission->fresh();
        $this->assertSame(2, $row->accepted_count);
        $this->assertSame(1, $row->failed_count);
        // There is no column on this table that claims a price is live, and nothing above set one.
        $this->assertArrayNotHasKey('applied_count', $row->getAttributes());
        $this->assertSame(3, $row->message_count);
    }

    // ---- 2. the fence: a lost reply is not a call that was never made ---------------------------------------------

    public function test_a_connection_that_dies_during_create_feed_leaves_a_row_nothing_will_submit_again(): void
    {
        // **THE DEFECT THIS WHOLE CLASS EXISTS FOR.** Without the fence, a reply we never received looks exactly like a call
        // that was never made, and the next run queues a second feed of the same 25,000 changes behind the first.
        $this->fakeUpToUpload();
        Http::fake([self::FEEDS => fn () => throw new \Illuminate\Http\Client\ConnectionException('connection reset by peer')]);
        [$submitter, $submission, $feed] = $this->runUpToUpload();

        $result = $submitter->submit($this->account, $submission->fresh(), ['A17E79C6D8DWNP']);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_never_told_us_whether_the_feed_was_queued', $result['refusal']);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::MAY_HAVE_SUBMITTED, $row->state, 'a lost reply was read as a call never made');
        $this->assertNotNull($row->submitted_at, 'the fence was not written before the request');
        $this->assertNotNull($row->needs_review_since);

        // And the ordinary cycle will not pick it up again. Every path a worker could take from here is a second submission.
        $this->assertSame(0, AmazonFeedSubmission::query()->due()->count(), 'a fenced row is still offered to the ordinary cycle');
        $this->assertNotContains(AmazonFeedSubmission::MAY_HAVE_SUBMITTED, AmazonFeedSubmission::UNFINISHED);
    }

    public function test_a_refusal_amazon_actually_stated_may_be_submitted_again(): void
    {
        // The other side of the fence, and the distinction it exists to draw. A status line is Amazon telling us it refused:
        // nothing was queued, so the row goes back. Reading this as unknown too would strand every ordinary rejection.
        $this->fakeUpToUpload();
        Http::fake([self::FEEDS => Http::response(['errors' => [['code' => 'InvalidInput', 'message' => 'inputFeedDocumentId is not valid']]], 400)]);
        [$submitter, $submission, $feed] = $this->runUpToUpload();

        $result = $submitter->submit($this->account, $submission->fresh(), ['A17E79C6D8DWNP']);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_feed_was_refused:rejected', $result['refusal']);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::UPLOADED, $row->state, 'a stated refusal left the row behind the fence');
        $this->assertNull($row->submitted_at);
    }

    public function test_a_200_that_does_not_name_the_feed_stays_behind_the_fence(): void
    {
        // `feedId` is REQUIRED on `CreateFeedResponse`. A 200 without it means the feed may well be queued and we cannot name
        // it -- which is the fence's case exactly, not a failure to retry.
        $this->fakeUpToUpload();
        Http::fake([self::FEEDS => Http::response([], 200)]);
        [$submitter, $submission, $feed] = $this->runUpToUpload();

        $result = $submitter->submit($this->account, $submission->fresh(), ['A17E79C6D8DWNP']);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_accepted_the_feed_and_did_not_name_it', $result['refusal']);
        $this->assertSame(AmazonFeedSubmission::MAY_HAVE_SUBMITTED, $submission->fresh()->state);
    }

    public function test_a_host_whose_writes_are_off_lifts_the_fence_rather_than_stranding_the_row(): void
    {
        // The transport refuses this BEFORE the request, so nothing was queued and the row must go back. Letting the refusal
        // escape would leave a row fenced for ever over a switch — and a switch is the one thing we can be certain about.
        $this->fakeUpToUpload();
        [$submitter, $submission] = $this->runUpToUpload();
        config(['sales_channels.external_writes_enabled' => false]);

        $result = $submitter->submit($this->account, $submission->fresh(), ['A17E79C6D8DWNP']);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_feed_was_not_allowed_to_be_sent', $result['refusal']);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::UPLOADED, $row->state, 'a switch left the row behind the fence');
        $this->assertNull($row->submitted_at);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::FEEDS && $request->method() === 'POST');
    }

    public function test_a_rehearsal_lifts_the_fence_because_it_is_the_other_case_we_know_nothing_was_sent(): void
    {
        // An account in rehearsal logs the exact request it WOULD have made and sends nothing, so the fence comes off.
        $this->fakeUpToUpload();
        [$submitter, $submission] = $this->runUpToUpload();
        $this->account->forceFill(['dry_run' => true])->save();

        $result = $submitter->submit($this->account->fresh(), $submission->fresh(), ['A17E79C6D8DWNP']);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_rehearsal_submitted_nothing', $result['refusal']);
        $this->assertSame(AmazonFeedSubmission::UPLOADED, $submission->fresh()->state);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::FEEDS && $request->method() === 'POST');
    }

    // ---- the only way back from the fence, and it is a READ -------------------------------------------------------

    public function test_exactly_one_matching_feed_at_amazon_is_adopted_rather_than_resubmitted(): void
    {
        $submission = $this->aFencedRow();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600]),
            self::FEEDS . '?*' => Http::response(['feeds' => [['feedId' => 'feed-found', 'feedType' => 'JSON_LISTINGS_FEED', 'processingStatus' => 'IN_PROGRESS', 'createdTime' => now()->toIso8601ZuluString()]]]),
        ]);

        $found = app(AmazonFeedSubmitter::class)->identify($this->account, $submission);

        $this->assertSame('matched', $found['outcome']);
        $this->assertSame('feed-found', $found['feed_id']);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::SUBMITTED, $row->state, 'an identified feed did not rejoin the cycle');
        $this->assertSame('feed-found', $row->amazon_feed_id);
        // Identification is a read. Nothing was submitted to get here.
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_starts_with($request->url(), self::FEEDS));
    }

    public function test_several_candidate_feeds_go_to_a_person_rather_than_a_guess(): void
    {
        // A `Feed` object carries no `inputFeedDocumentId`, so which of two feeds is ours is not knowable from what Amazon
        // returns. Adopting either would attach this row's report to somebody else's submission.
        $submission = $this->aFencedRow();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600]),
            self::FEEDS . '?*' => Http::response(['feeds' => [
                ['feedId' => 'feed-a', 'feedType' => 'JSON_LISTINGS_FEED', 'processingStatus' => 'IN_QUEUE', 'createdTime' => now()->toIso8601ZuluString()],
                ['feedId' => 'feed-b', 'feedType' => 'JSON_LISTINGS_FEED', 'processingStatus' => 'IN_QUEUE', 'createdTime' => now()->toIso8601ZuluString()],
            ]]),
        ]);

        $found = app(AmazonFeedSubmitter::class)->identify($this->account, $submission);

        $this->assertSame('several', $found['outcome']);
        $this->assertSame(2, $found['candidates']);
        $this->assertSame(AmazonFeedSubmission::NEEDS_REVIEW, $submission->fresh()->state);
    }

    public function test_an_empty_list_is_not_read_as_nothing_having_been_queued(): void
    {
        // **UNKNOWN IS NEVER ABSENT.** Amazon documents no guarantee about how soon a feed appears in `getFeeds`, so an empty
        // answer is not evidence that nothing was queued. Read the other way, this is where the double submission comes back.
        $submission = $this->aFencedRow();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600]),
            self::FEEDS . '?*' => Http::response(['feeds' => []]),
        ]);

        $found = app(AmazonFeedSubmitter::class)->identify($this->account, $submission);

        $this->assertSame('none', $found['outcome']);
        $this->assertSame(AmazonFeedSubmission::MAY_HAVE_SUBMITTED, $submission->fresh()->state, 'an empty list cleared the fence');
    }

    public function test_a_list_we_could_not_read_is_unknown_and_changes_nothing(): void
    {
        $submission = $this->aFencedRow();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600]),
            self::FEEDS . '?*' => Http::response('gateway timeout', 504),
        ]);

        $found = app(AmazonFeedSubmitter::class)->identify($this->account, $submission);

        $this->assertSame('unknown', $found['outcome']);
        $this->assertSame(AmazonFeedSubmission::MAY_HAVE_SUBMITTED, $submission->fresh()->state);
    }

    public function test_a_feed_another_row_already_claims_is_not_adopted(): void
    {
        // Two fenced rows and one feed at Amazon: the feed belongs to whichever row already named it, and the other must not
        // adopt it and start reading somebody else's report as its own.
        $submission = $this->aFencedRow();
        AmazonFeedSubmission::create([
            'account_id' => $this->account->id, 'submission_key' => 'batch-other', 'state' => AmazonFeedSubmission::SUBMITTED,
            'amazon_feed_id' => 'feed-taken', 'message_map' => [1 => 'SKU-9'], 'message_count' => 1,
        ]);
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600]),
            self::FEEDS . '?*' => Http::response(['feeds' => [['feedId' => 'feed-taken', 'feedType' => 'JSON_LISTINGS_FEED', 'processingStatus' => 'DONE', 'createdTime' => now()->toIso8601ZuluString()]]]),
        ]);

        $found = app(AmazonFeedSubmitter::class)->identify($this->account, $submission);

        $this->assertSame('none', $found['outcome']);
        $this->assertNull($submission->fresh()->amazon_feed_id);
    }

    // ---- 3. two workers, one batch -------------------------------------------------------------------------------

    public function test_two_runs_that_both_decide_one_batch_needs_sending_end_with_one_submission(): void
    {
        $this->fakeTheWholeCycle();
        $submitter = app(AmazonFeedSubmitter::class);

        $first = $submitter->intend($this->account, $this->aFeed(), 'batch-1');
        $second = $submitter->intend($this->account, $this->aFeed(), 'batch-1');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertStringStartsWith('amazon_feed_for_this_batch_already_exists:', (string) $second['refusal']);
        $this->assertSame(1, AmazonFeedSubmission::count(), 'one batch produced two submissions');
    }

    public function test_only_one_worker_can_claim_a_submission(): void
    {
        $this->fakeTheWholeCycle();
        $submitter = app(AmazonFeedSubmitter::class);
        $row = $submitter->intend($this->account, $this->aFeed(), 'batch-1')['submission'];

        $mine = $submitter->claim($row);
        // The second worker read the row before the first claimed it -- the ordinary race.
        $theirs = $submitter->claim($row);

        $this->assertNotNull($mine);
        $this->assertNull($theirs, 'two workers both own one submission');
    }

    public function test_a_worker_whose_lease_lapsed_stops_calling(): void
    {
        // The lease is what lets a dead worker's row be picked up again. The other half of that bargain is that a worker whose
        // lease has run out must stop: another worker may already be driving the row, and two drivers is two feeds.
        $this->fakeTheWholeCycle();
        $submitter = app(AmazonFeedSubmitter::class);
        $row = $submitter->claim($submitter->intend($this->account, $this->aFeed(), 'batch-1')['submission'], leaseSeconds: 60);

        $this->travel(5)->minutes();
        $result = $submitter->createDocument($this->account, $row);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_feed_claim_has_lapsed', $result['refusal']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::DOCUMENTS);
    }

    public function test_the_fence_is_refused_when_the_row_moved_under_the_worker(): void
    {
        // The claim is not enough by itself: the state is in the fence's own condition. A row another worker moved between
        // this worker's read and this write is not fenced, because the decision to submit was made against a state that is
        // gone -- and submitting anyway is the double feed.
        $this->fakeUpToUpload();
        [$submitter, $submission, $feed] = $this->runUpToUpload();
        $stale = $submission->fresh();
        AmazonFeedSubmission::whereKey($stale->id)->update(['state' => AmazonFeedSubmission::SUBMITTED, 'amazon_feed_id' => 'feed-elsewhere']);

        $result = $submitter->submit($this->account, $stale, ['A17E79C6D8DWNP']);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_feed_row_moved_before_the_fence_was_set', $result['refusal']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::FEEDS);
    }

    // ---- 4. what Amazon said, and what may be concluded from it ---------------------------------------------------

    public function test_a_cancelled_feed_is_the_one_failure_that_may_be_sent_again(): void
    {
        // Amazon's own words: "the feed was cancelled before it started processing. If you want to submit the feed again,
        // start again at Step 1." Nothing was applied, so there is nothing to double.
        $state = AmazonFeedState::of(['feedId' => 'feed-1', 'processingStatus' => 'CANCELLED', 'feedType' => 'JSON_LISTINGS_FEED']);

        $this->assertSame(AmazonFeedState::CANCELLED, $state->state);
        $this->assertTrue($state->terminal());
        $this->assertTrue($state->safeToSubmitAgain());
        $this->assertFalse($state->hasReport());
    }

    public function test_a_fatal_feed_is_unknown_and_goes_to_a_person(): void
    {
        // Amazon: "Some, none, or all of the operations within the feed might have completed successfully." Over a feed of
        // price and quantity changes that is an unknown outcome on every SKU it carried. A machine resolves none of it.
        $state = AmazonFeedState::of(['feedId' => 'feed-1', 'processingStatus' => 'FATAL', 'resultFeedDocumentId' => 'report-1']);

        $this->assertSame(AmazonFeedState::FAILED, $state->state);
        $this->assertTrue($state->terminal());
        $this->assertFalse($state->safeToSubmitAgain(), 'a FATAL feed was offered for resubmission');
        // And it may still carry a report, which is why the document is READ and not inferred from the status.
        $this->assertTrue($state->hasReport());
    }

    public function test_a_fatal_poll_moves_the_row_to_a_person_and_keeps_the_report_it_named(): void
    {
        $this->fakeTheWholeCycle();
        $this->pollResponse = Http::response(['feedId' => 'feed-1', 'feedType' => 'JSON_LISTINGS_FEED', 'processingStatus' => 'FATAL', 'createdTime' => now()->toIso8601ZuluString(), 'resultFeedDocumentId' => 'report-1']);
        [$submitter, $submission] = $this->runUpToSubmitted();

        $state = $submitter->poll($this->account, $submission->fresh());

        $this->assertSame(AmazonFeedState::FAILED, $state->state);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::NEEDS_REVIEW, $row->state);
        $this->assertSame('amazon_stopped_the_feed_and_some_none_or_all_of_it_may_have_applied', $row->failure_code);
        $this->assertSame('report-1', $row->result_document_id, 'the report FATAL named was discarded');
        $this->assertNotNull($row->needs_review_since);
    }

    public function test_a_status_amazon_does_not_publish_is_unknown_rather_than_guessed_into_one_of_the_five(): void
    {
        $state = AmazonFeedState::of(['feedId' => 'feed-1', 'processingStatus' => 'PROBABLY_FINE']);

        $this->assertSame(AmazonFeedState::UNKNOWN, $state->state);
        $this->assertFalse($state->terminal());
        $this->assertFalse($state->stillWorking(), 'an unknown status was read as still working, which polls for ever');
        $this->assertSame('amazon_feed_status_is_not_one_of_the_five_documented_values:PROBABLY_FINE', $state->problem);
    }

    public function test_a_poll_we_could_not_read_leaves_the_row_exactly_where_it_was(): void
    {
        $this->fakeTheWholeCycle();
        $this->pollResponse = Http::response('service unavailable', 503);
        [$submitter, $submission] = $this->runUpToSubmitted();

        $state = $submitter->poll($this->account, $submission->fresh());

        $this->assertSame(AmazonFeedState::UNKNOWN, $state->state);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::SUBMITTED, $row->state, 'a 503 was read as an answer about the feed');
        $this->assertNotNull($row->available_at, 'the row was not scheduled to ask again');
    }

    public function test_a_body_that_is_not_a_feed_object_is_unknown(): void
    {
        // `processingStatus` is required on the schema, so a body without it is not a Feed. Reading it as "not finished"
        // would poll an unknown row for ever.
        $this->fakeTheWholeCycle();
        $this->pollResponse = Http::response(['somethingElse' => true]);
        [$submitter, $submission] = $this->runUpToSubmitted();

        $state = $submitter->poll($this->account, $submission->fresh());

        $this->assertSame(AmazonFeedState::UNKNOWN, $state->state);
        $this->assertSame('amazon_feed_reply_is_not_a_feed_object', $state->problem);
    }

    // ---- the report: partial success, and reports that cannot be accounted for ------------------------------------

    public function test_a_report_about_a_different_number_of_messages_marks_nothing_as_accepted(): void
    {
        // The arithmetic is the whole safety of the subtraction. A report whose `messagesProcessed` is not what was sent is
        // not a report about this submission, and subtracting from it would mark SKUs as published that it never mentioned.
        $this->fakeTheWholeCycle();
        [$submitter, $submission] = $this->runUpToTheReport(['SKU-1', 'SKU-2', 'SKU-3']);

        $recorded = $submitter->recordReport($submission->fresh(), (string) json_encode([
            'header' => ['sellerId' => 'A1SELLER', 'version' => '2.0', 'feedId' => 'feed-1'],
            'issues' => [],
            'summary' => ['errors' => 0, 'warnings' => 0, 'messagesProcessed' => 2, 'messagesAccepted' => 2, 'messagesInvalid' => 0],
        ]));

        $this->assertFalse($recorded['ok']);
        $this->assertSame('amazon_feed_report_counts_do_not_match_what_was_sent:2_of_3', $recorded['refusal']);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::NEEDS_REVIEW, $row->state);
        $this->assertSame(0, $row->accepted_count, 'SKUs were marked accepted from a report that never mentioned them');
    }

    public function test_a_report_nobody_could_read_goes_to_a_person_and_claims_nothing(): void
    {
        // Amazon warns of exactly this for FATAL: a report "could be in a different format from a feed processing report for
        // a successfully completed feed". An unreadable report is not an empty one.
        $this->fakeTheWholeCycle();
        [$submitter, $submission] = $this->runUpToTheReport();

        $recorded = $submitter->recordReport($submission->fresh(), 'this is not the report you are looking for');

        $this->assertFalse($recorded['ok']);
        $this->assertSame('amazon_feed_report_is_not_json', $recorded['refusal']);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::NEEDS_REVIEW, $row->state);
        $this->assertSame(0, $row->accepted_count);
    }

    public function test_a_report_blaming_a_message_we_never_sent_is_not_attributed_to_one_of_our_skus(): void
    {
        $this->fakeTheWholeCycle();
        [$submitter, $submission] = $this->runUpToTheReport(['SKU-1']);

        $recorded = $submitter->recordReport($submission->fresh(), (string) json_encode([
            'header' => ['sellerId' => 'A1SELLER', 'version' => '2.0'],
            'issues' => [['messageId' => 99, 'severity' => 'ERROR', 'message' => 'about a message we never sent']],
            'summary' => ['errors' => 1, 'warnings' => 0, 'messagesProcessed' => 1, 'messagesAccepted' => 0, 'messagesInvalid' => 1],
        ]));

        // The failure is kept under a name that says it belongs to no SKU of ours, rather than being pinned on one.
        $this->assertArrayHasKey('unknown_message_99', $recorded['failed']);
        $this->assertSame([], $recorded['accepted']);
    }

    public function test_a_feed_amazon_refused_whole_accepts_none_of_its_messages(): void
    {
        // An issue with no `messageId` is about the submission -- Amazon documents that case: "Not provided if the error is
        // not related to a message (e.g. feed formatting issue)". Nothing in the feed took effect.
        $this->fakeTheWholeCycle();
        [$submitter, $submission] = $this->runUpToTheReport(['SKU-1', 'SKU-2', 'SKU-3']);

        $recorded = $submitter->recordReport($submission->fresh(), (string) json_encode([
            'header' => ['sellerId' => 'A1SELLER', 'version' => '2.0'],
            'issues' => [['severity' => 'ERROR', 'message' => 'The feed could not be parsed.']],
            'summary' => ['errors' => 1, 'warnings' => 0, 'messagesProcessed' => 3, 'messagesAccepted' => 0, 'messagesInvalid' => 0],
        ]));

        $this->assertFalse($recorded['ok']);
        $this->assertSame('amazon_refused_the_whole_feed', $recorded['refusal']);
        $this->assertSame(0, $submission->fresh()->accepted_count);
    }

    public function test_a_gzipped_report_is_decompressed_and_an_unknown_algorithm_is_refused(): void
    {
        $this->fakeTheWholeCycle();
        [$submitter, $submission] = $this->runUpToSubmitted();
        $submitter->poll($this->account, $submission->fresh());

        // GZIP is the only value in Amazon's enum, and it is handled.
        $this->documentResponse = Http::response(['feedDocumentId' => 'report-1', 'url' => 'https://amazon-feeds.s3.example.test/report.gz', 'compressionAlgorithm' => 'GZIP']);
        $this->report = (string) gzencode($this->aReportFor(['SKU-1', 'SKU-2', 'SKU-3']));
        $report = $submitter->report($this->account, $submission->fresh());
        $this->assertNull($report['problem']);
        $this->assertStringContainsString('messagesProcessed', (string) $report['contents']);

        // Anything else is named rather than fed to a decoder that would hand back bytes nobody could tell from a corrupt file.
        $this->documentResponse = Http::response(['feedDocumentId' => 'report-1', 'url' => 'https://amazon-feeds.s3.example.test/report.br', 'compressionAlgorithm' => 'BROTLI']);
        $second = $submitter->report($this->account, $submission->fresh());
        $this->assertSame('amazon_feed_report_compression_not_understood:BROTLI', $second['problem']);
    }

    public function test_a_feed_that_named_no_report_is_not_treated_as_an_empty_report(): void
    {
        $this->fakeTheWholeCycle();
        [$submitter, $submission] = $this->runUpToSubmitted();

        $report = $submitter->report($this->account, $submission->fresh());

        $this->assertNull($report['contents']);
        $this->assertSame('amazon_feed_named_no_report_document', $report['problem']);
    }

    // ---- the deadlines Amazon states -----------------------------------------------------------------------------

    public function test_an_expired_upload_url_is_not_uploaded_to(): void
    {
        // Amazon gives the presigned URL five minutes. A PUT to a dead URL fails in a way that looks like a rejected feed, so
        // the row goes back for a new document instead.
        $this->fakeTheWholeCycle();
        $submitter = app(AmazonFeedSubmitter::class);
        $feed = $this->aFeed();
        $submission = $submitter->claim($submitter->intend($this->account, $feed, 'batch-1')['submission']);
        $created = $submitter->createDocument($this->account, $submission);

        $this->travel(6)->minutes();
        $result = $submitter->upload($this->account, $submission->fresh(), $feed, $created['url']);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_feed_upload_url_expired_before_use', $result['refusal']);
        $row = $submission->fresh();
        $this->assertSame(AmazonFeedSubmission::PENDING, $row->state, 'the row did not go back for a fresh document');
        $this->assertNull($row->feed_document_id);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_an_expired_document_id_is_not_submitted(): void
    {
        // Amazon: "This feedDocumentId value expires after two days. If you pass in an expired feedDocumentId value to the
        // createFeed operation, the call will fail." Spending one of five submissions per five minutes on a certainty is
        // worse than starting again.
        $this->fakeUpToUpload();
        [$submitter, $submission] = $this->runUpToUpload();
        AmazonFeedSubmission::whereKey($submission->id)->update(['document_expires_at' => now()->subHour()]);

        $result = $submitter->submit($this->account, $submission->fresh(), ['A17E79C6D8DWNP']);

        $this->assertFalse($result['ok']);
        $this->assertSame('amazon_feed_document_expired_before_submission', $result['refusal']);
        $this->assertSame(AmazonFeedSubmission::PENDING, $submission->fresh()->state);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::FEEDS);
    }

    public function test_the_feed_body_never_reaches_the_api_log(): void
    {
        // A feed body is a seller's whole catalogue delta, up to 25,000 messages. Logged verbatim it would dwarf every other
        // line in the table and put catalogue data in a place nobody expects to find it.
        $this->fakeTheWholeCycle();
        $this->runUpToUpload();

        $logged = \App\Models\SalesChannelApiLog::where('operation', 'amazon.feeds_upload')->firstOrFail();
        $body = (string) json_encode($logged->getAttributes());
        $this->assertStringNotContainsString('SKU-1', $body, 'the feed body was written to the log');
        $this->assertStringContainsString('_messages', $body, 'the log says nothing about what was uploaded');
    }

    // ---- includedData: the documented way a report says anything about successes ----------------------------------

    public function test_asking_for_included_data_sends_both_fields_amazons_schema_requires(): void
    {
        // The schema's `header.report` object requires `includedData` AND `apiVersion` together. Sending one without the
        // other is a document Amazon's own validator rejects -- which loses the whole feed rather than one message.
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', $this->changesFor(['SKU-1']), includedData: ['issues']);

        $this->assertTrue($feed->acceptable());
        $this->assertSame(['includedData' => ['issues'], 'apiVersion' => '2021-08-01'], $feed->document['header']['report']);
    }

    public function test_a_feed_that_asks_for_data_amazon_does_not_publish_is_refused(): void
    {
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', $this->changesFor(['SKU-1']), includedData: ['issues', 'everything']);

        $this->assertFalse($feed->acceptable());
        $this->assertSame('amazon_feed_asks_for_data_amazon_does_not_publish:everything', $feed->refusal);
    }

    public function test_a_feed_that_asks_for_nothing_extra_sends_no_report_object_at_all(): void
    {
        $feed = AmazonListingsFeed::ofPatches('A1SELLER', $this->changesFor(['SKU-1']));

        $this->assertArrayNotHasKey('report', $feed->document['header']);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /** @param list<string> $skus */
    private function aFeed(array $skus = ['SKU-1', 'SKU-2', 'SKU-3']): AmazonListingsFeed
    {
        return AmazonListingsFeed::ofPatches('A1SELLER', $this->changesFor($skus));
    }

    /**
     * @param list<string> $skus
     * @return array<string, array{product_type: string, patches: list<array<string, mixed>>}>
     */
    private function changesFor(array $skus): array
    {
        $changes = [];
        foreach ($skus as $sku) {
            $changes[$sku] = ['product_type' => 'HEALTH_PERSONAL_CARE', 'patches' => [['op' => 'replace', 'path' => '/attributes/fulfillment_availability', 'value' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => 7]]]]];
        }

        return $changes;
    }

    /** A report shaped after `listings-feed-processing-report-schema-v2.json`: the second SKU fails, the rest are silent. */
    private function aReportFor(array $skus = ['SKU-1', 'SKU-2', 'SKU-3']): string
    {
        return (string) json_encode([
            'header' => ['sellerId' => 'A1SELLER', 'version' => '2.0', 'feedId' => 'feed-1'],
            'issues' => count($skus) > 1 ? [['messageId' => 2, 'sku' => $skus[1], 'code' => '4000001', 'severity' => 'ERROR', 'message' => "'[item_package_quantity]' is required but not supplied.", 'attributeName' => 'item_package_quantity']] : [],
            'summary' => [
                'errors' => count($skus) > 1 ? 1 : 0,
                'warnings' => 0,
                'messagesProcessed' => count($skus),
                'messagesAccepted' => count($skus) > 1 ? count($skus) - 1 : count($skus),
                'messagesInvalid' => count($skus) > 1 ? 1 : 0,
            ],
        ]);
    }

    private function fakeUpToUpload(): void
    {
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600]),
            self::DOCUMENTS => Http::response(['feedDocumentId' => 'doc-1', 'url' => 'https://amazon-feeds.s3.example.test/upload?sig=abc']),
            self::UPLOAD => Http::response('', 200),
        ]);
    }

    /**
     * Every stub this suite needs, registered ONCE and answered from mutable fixture state.
     *
     * `Http::fake()` appends and the FIRST matching stub wins, so a second `Http::fake` naming a URL an earlier call already
     * stubbed is silently ignored — a test that "overrode" the poll response would quietly keep asserting the happy path.
     * The closures read these properties instead, and a test changes the property.
     */
    private function fakeTheWholeCycle(array $skus = ['SKU-1', 'SKU-2', 'SKU-3']): void
    {
        $this->fakeUpToUpload();
        $this->report = $this->aReportFor($skus);
        Http::fake([
            self::FEEDS => Http::response(['feedId' => 'feed-1']),
            self::FEEDS . '/feed-1' => fn () => $this->pollResponse,
            self::SP . '/feeds/2021-06-30/documents/report-1' => fn () => $this->documentResponse,
            self::DOWNLOAD => fn () => Http::response($this->report),
        ]);
    }

    /** @return array{0: AmazonFeedSubmitter, 1: AmazonFeedSubmission, 2: AmazonListingsFeed} */
    private function runUpToUpload(array $skus = ['SKU-1', 'SKU-2', 'SKU-3']): array
    {
        $submitter = app(AmazonFeedSubmitter::class);
        $feed = $this->aFeed($skus);
        $submission = $submitter->claim($submitter->intend($this->account, $feed, 'batch-1')['submission']);
        $created = $submitter->createDocument($this->account, $submission);
        $submitter->upload($this->account, $submission->fresh(), $feed, $created['url']);

        return [$submitter, $submission->fresh(), $feed];
    }

    /** @return array{0: AmazonFeedSubmitter, 1: AmazonFeedSubmission} */
    private function runUpToSubmitted(array $skus = ['SKU-1', 'SKU-2', 'SKU-3']): array
    {
        [$submitter, $submission] = $this->runUpToUpload($skus);
        $submitter->submit($this->account, $submission, ['A17E79C6D8DWNP']);

        return [$submitter, $submission->fresh()];
    }

    /** @return array{0: AmazonFeedSubmitter, 1: AmazonFeedSubmission} */
    private function runUpToTheReport(array $skus = ['SKU-1', 'SKU-2', 'SKU-3']): array
    {
        [$submitter, $submission] = $this->runUpToSubmitted($skus);
        $submitter->poll($this->account, $submission);

        return [$submitter, $submission->fresh()];
    }

    /** A row stuck behind the fence: a feed that may be queued at Amazon under a number we never saw. */
    private function aFencedRow(): AmazonFeedSubmission
    {
        return AmazonFeedSubmission::create([
            'account_id' => $this->account->id,
            'submission_key' => 'batch-fenced',
            'state' => AmazonFeedSubmission::MAY_HAVE_SUBMITTED,
            'feed_document_id' => 'doc-1',
            'submitted_at' => now()->subMinutes(3),
            'message_map' => [1 => 'SKU-1', 2 => 'SKU-2'],
            'message_count' => 2,
            'failure_code' => 'amazon_never_told_us_whether_the_feed_was_queued',
            'needs_review_since' => now()->subMinutes(3),
        ]);
    }
}
