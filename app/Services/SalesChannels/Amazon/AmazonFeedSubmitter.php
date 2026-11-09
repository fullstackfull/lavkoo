<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\AmazonFeedSubmission;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The five calls that submit a `JSON_LISTINGS_FEED`, and the fence that stops one batch being queued at Amazon twice.
 *
 * `AmazonListingsFeed` builds the document and `AmazonFeedResult` reads the report. This class is the part between them: the
 * cycle Amazon publishes as seven steps, driven one step per call so that a process dying anywhere leaves a row that says
 * what may already have happened.
 *
 *     createFeedDocument  ->  PUT the bytes  ->  createFeed  ->  getFeed until terminal  ->  getFeedDocument  ->  download
 *
 * ## Every published fact this class depends on, and where it comes from
 *
 * From `models/feeds-api-model/feeds_2021-06-30.json` in Amazon's own `amzn/selling-partner-api-models`:
 *
 *  - `createFeedDocument` takes `contentType` (its only field, and REQUIRED) and answers `feedDocumentId` and `url`; the
 *    schema says of that URL "This URL expires after 5 minutes."
 *  - `createFeed` takes `feedType`, `marketplaceIds`, `inputFeedDocumentId` and an optional `feedOptions`, and answers
 *    `feedId`. **There is no idempotency key and no deduplication of any kind.** That absence is why this class has a fence.
 *  - `getFeed` answers a `Feed`: `processingStatus` is required and its enum is exactly `CANCELLED`, `DONE`, `FATAL`,
 *    `IN_PROGRESS`, `IN_QUEUE`; `resultFeedDocumentId` is optional.
 *  - `getFeedDocument` answers `url` and an optional `compressionAlgorithm` whose only enum value is `GZIP`.
 *  - `cancelFeed` exists, and "Only feeds with `processingStatus=IN_QUEUE` can be cancelled."
 *
 * From Amazon's "Submit a feed" guide:
 *
 *  - The upload is a PUT, and "The contentType must match the input provided to the createFeedDocument operation." Its JSON
 *    example uses `application/json; charset=UTF-8`, which is where `CONTENT_TYPE` below comes from.
 *  - "This feedDocumentId value expires after two days."
 *  - Poll "until the feed moves into one of the following terminal states: DONE, CANCELLED, or FATAL", and feeds "can take up
 *    to eight hours to process".
 *  - `getFeed` "only serves information for feed requests that were created within the last 28 days".
 *  - `CANCELLED` = "cancelled before it started processing"; `FATAL` = "Some, none, or all of the operations within the feed
 *    might have completed successfully."
 *
 * From the Building Listings Management Workflows guide: `JSON_LISTINGS_FEED` is rated at "five feeds submission per five
 * minutes" — which is NOT the generic `createFeed` rate of 0.0083 requests a second, and Amazon says so explicitly.
 *
 * ## What this class refuses to conclude
 *
 * That anything is live. Amazon's own `summary.messagesAccepted` is "Number of messages that were accepted, the associated
 * updates **are being applied**" — its wording. So a read report sets `accepted_count` and nothing else; what Amazon is
 * actually showing for a SKU is answered by reading the listing, and `includedData` on the feed header is how this class asks
 * for that reading to come back WITH the report instead of as a separate call per SKU.
 */
class AmazonFeedSubmitter
{
    /**
     * The content type for a JSON feed, as Amazon's own JSON sample uses it, and the value the upload must repeat.
     *
     * One constant because a wrong value is a clean documented failure before anything is queued, so this is the single line
     * to change if Amazon ever states another.
     */
    public const CONTENT_TYPE = 'application/json; charset=UTF-8';

    public const FEED_TYPE = 'JSON_LISTINGS_FEED';

    /** Amazon: "This feedDocumentId value expires after two days." */
    private const DOCUMENT_LIFE_HOURS = 48;

    /** Amazon: the presigned upload URL "expires after 5 minutes". Held back a little, so a slow upload is not started late. */
    private const UPLOAD_URL_LIFE_SECONDS = 270;

    /** The only `compressionAlgorithm` in Amazon's enum. */
    private const GZIP = 'GZIP';

    public function __construct(
        private readonly AmazonClient $client,
        private readonly ChannelHttp $http,
    ) {
    }

    /**
     * **The independent switch, and it ships off.**
     *
     * On top of `sales_channels.enabled` and `external_writes_enabled`, which every operation here already answers to. The
     * extra switch is not belt-and-braces: a single accepted `JSON_LISTINGS_FEED` carries up to 25,000 price and quantity
     * changes, and once it leaves `IN_QUEUE` Amazon documents no way to recall it -- `cancelFeed` works on queued feeds only.
     * That is a different kind of exposure from a per-SKU PATCH, so it is a different decision, taken once and on purpose.
     *
     * Per-SKU publishing remains the default path whatever this returns. Nothing selects the feed because it is available.
     */
    public static function enabled(): bool
    {
        return (bool) config('sales_channels.amazon.bulk_feeds_enabled', false);
    }

    /**
     * Records the intent to submit a feed, and nothing else. **No network call happens here.**
     *
     * The row comes first so that everything after it has somewhere to write what it is about to do. `submission_key` is
     * unique, so two workers that both decide the same batch needs sending end with one row and one of them is told the work
     * is already somebody's.
     *
     * @return array{ok: bool, submission: AmazonFeedSubmission|null, refusal: string|null}
     */
    public function intend(SalesChannelAccount $account, AmazonListingsFeed $feed, string $submissionKey, ?string $marketplaceId = null): array
    {
        if (!self::enabled()) {
            // Refused at the very first step, before a row exists: an installation that has not turned this on must not end
            // up with half-built submissions waiting for somebody to turn it on later.
            return ['ok' => false, 'submission' => null, 'refusal' => 'amazon_bulk_feeds_are_switched_off'];
        }
        if (!$feed->acceptable()) {
            return ['ok' => false, 'submission' => null, 'refusal' => $feed->refusal];
        }
        if ($feed->messageCount() < max(1, (int) config('sales_channels.amazon.feed_minimum_messages', 1500))) {
            // Amazon's own comparison: the per-SKU API "is faster with less than 1,500 items during a five-minute period".
            // Sending fewer spends one of five submissions per five minutes to be slower, so the caller is told to use the
            // path that is already built rather than handed a feed that is the wrong tool.
            return ['ok' => false, 'submission' => null, 'refusal' => 'amazon_feed_is_smaller_than_the_per_sku_path_handles_faster:' . $feed->messageCount()];
        }
        if (trim($submissionKey) === '') {
            // Without a key there is no fence, and without a fence the same batch can be queued twice. A caller that cannot
            // name what it is sending is refused rather than given an unprotected row.
            return ['ok' => false, 'submission' => null, 'refusal' => 'amazon_feed_submission_needs_a_key'];
        }

        $existing = AmazonFeedSubmission::where('submission_key', trim($submissionKey))->first();
        if ($existing !== null) {
            return ['ok' => false, 'submission' => $existing, 'refusal' => 'amazon_feed_for_this_batch_already_exists:' . $existing->state];
        }

        $submission = AmazonFeedSubmission::create([
            'account_id' => $account->id,
            'seller_id' => $feed->document['header']['sellerId'] ?? null,
            'feed_type' => self::FEED_TYPE,
            'marketplace_id' => $marketplaceId,
            'submission_key' => trim($submissionKey),
            'state' => AmazonFeedSubmission::PENDING,
            'message_map' => $feed->skuByMessage,
            'message_count' => $feed->messageCount(),
        ]);

        return ['ok' => true, 'submission' => $submission, 'refusal' => null];
    }

    /**
     * Takes a row for this worker, or says somebody else has it.
     *
     * A conditional UPDATE whose affected-row count is checked, so two workers cannot both own one submission. The condition
     * carries the state as well as the lease: a row that moved between the read and this write is not claimed, because the
     * work the caller was about to do was decided against a state that no longer exists.
     */
    public function claim(AmazonFeedSubmission $submission, int $leaseSeconds = 900): ?AmazonFeedSubmission
    {
        $token = AmazonFeedSubmission::newClaimToken();
        $affected = AmazonFeedSubmission::whereKey($submission->id)
            ->where('state', $submission->state)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->update([
                'claim_token' => $token,
                'claimed_at' => now(),
                'lease_until' => now()->addSeconds(max(60, $leaseSeconds)),
                'updated_at' => now(),
            ]);

        return $affected === 1 ? AmazonFeedSubmission::find($submission->id) : null;
    }

    /**
     * Step 1: `createFeedDocument`. Saves the identifier and both deadlines before anything is uploaded.
     *
     * @return array{ok: bool, refusal: string|null}
     */
    public function createDocument(SalesChannelAccount $account, AmazonFeedSubmission $submission): array
    {
        $guard = $this->ours($submission, [AmazonFeedSubmission::PENDING, AmazonFeedSubmission::DOCUMENT_CREATED]);
        if ($guard !== null) {
            return $guard;
        }

        $response = $this->client->call(
            account: $account,
            operation: 'amazon.feeds_create_document',
            method: 'POST',
            path: '/feeds/2021-06-30/documents',
            json: ['contentType' => self::CONTENT_TYPE],
        );
        if (!$response->ok()) {
            return $this->fail($submission, 'amazon_feed_document_was_not_created', $response, keepWorking: true);
        }

        $body = $this->payloadOf($response);
        $documentId = trim((string) ($body['feedDocumentId'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        if ($documentId === '' || $url === '') {
            // Both are REQUIRED on `CreateFeedDocumentResponse`. A reply missing either is not that object, and guessing
            // would leave us uploading to nowhere or submitting an identifier Amazon never issued.
            return $this->fail($submission, 'amazon_feed_document_reply_is_incomplete', $response, keepWorking: true);
        }

        $submission->update([
            'feed_document_id' => $documentId,
            'document_created_at' => now(),
            'document_expires_at' => now()->addHours(self::DOCUMENT_LIFE_HOURS),
            'upload_url_expires_at' => now()->addSeconds(self::UPLOAD_URL_LIFE_SECONDS),
            'state' => AmazonFeedSubmission::DOCUMENT_CREATED,
            'failure_code' => null,
            'failure_detail' => null,
        ]);

        // The URL is NOT stored. It is good for five minutes and is a presigned write to Amazon's bucket; it is handed back
        // to the caller for immediate use and a worker that comes back later creates a new document instead.
        return ['ok' => true, 'refusal' => null, 'url' => $url];
    }

    /**
     * Step 3: the upload. A PUT of the document's bytes with the content type the document was created for.
     *
     * Still nothing is queued at Amazon after this: an uploaded document that is never submitted expires in two days and
     * changes nothing. That is why the fence is at `createFeed` and not here.
     *
     * @return array{ok: bool, refusal: string|null}
     */
    public function upload(SalesChannelAccount $account, AmazonFeedSubmission $submission, AmazonListingsFeed $feed, string $url): array
    {
        $guard = $this->ours($submission, [AmazonFeedSubmission::DOCUMENT_CREATED]);
        if ($guard !== null) {
            return $guard;
        }
        if (!$feed->acceptable()) {
            return $this->refuse($submission, $feed->refusal ?? 'amazon_feed_document_is_not_acceptable');
        }
        if ($submission->uploadUrlHasExpired()) {
            // Amazon gives the URL five minutes. Uploading to a dead URL fails in a way that looks like a rejected feed, so
            // it is not attempted: the row goes back for a new document.
            $submission->update(['state' => AmazonFeedSubmission::PENDING, 'feed_document_id' => null, 'upload_url_expires_at' => null, 'failure_code' => 'amazon_feed_upload_url_expired_before_use']);

            return ['ok' => false, 'refusal' => 'amazon_feed_upload_url_expired_before_use'];
        }

        $body = $feed->json();
        $response = $this->http->send(
            account: $account,
            operation: 'amazon.feeds_upload',
            method: 'PUT',
            url: $url,
            headers: ['Content-Type' => self::CONTENT_TYPE],
            rawBody: $body,
            // The feed body is a seller's own catalogue data and can be 25,000 messages long. The log keeps its size and
            // nothing else: a log is not the place for the payload, and this one would dwarf every other line in the table.
            rawBodyForLog: 'json_listings_feed:' . strlen($body) . '_bytes:' . $submission->message_count . '_messages',
        );
        if ($response->isDryRun()) {
            return ['ok' => false, 'refusal' => 'amazon_rehearsal_uploaded_nothing'];
        }
        if (!$response->ok()) {
            return $this->fail($submission, 'amazon_feed_upload_failed', $response, keepWorking: true);
        }

        $submission->update(['state' => AmazonFeedSubmission::UPLOADED, 'uploaded_at' => now(), 'failure_code' => null, 'failure_detail' => null]);

        return ['ok' => true, 'refusal' => null];
    }

    /**
     * Step 4: `createFeed` — **and the fence that makes this whole class worth having.**
     *
     * The row is moved to `may_have_submitted` and COMMITTED before the request leaves. So the three outcomes are:
     *
     *  - a reply: the `feedId` is written and the row becomes `submitted`;
     *  - a refusal Amazon stated: nothing was queued, and the row goes back to `uploaded` to be tried again;
     *  - **no reply at all** — a timeout, a reset, a worker killed mid-flight: the row stays `may_have_submitted` and nothing
     *    in this system will ever submit it again. It is identified by reading Amazon's own list of feeds, and if that cannot
     *    settle it, a person does.
     *
     * Written the other way round, a lost reply looks exactly like a call that was never made, and the next run queues a
     * second feed of up to 25,000 price and quantity changes behind the first.
     *
     * @return array{ok: bool, refusal: string|null}
     */
    public function submit(SalesChannelAccount $account, AmazonFeedSubmission $submission, array $marketplaceIds): array
    {
        $guard = $this->ours($submission, [AmazonFeedSubmission::UPLOADED]);
        if ($guard !== null) {
            return $guard;
        }
        $marketplaceIds = array_values(array_filter(array_map('trim', $marketplaceIds)));
        if ($marketplaceIds === []) {
            return $this->refuse($submission, 'amazon_feed_has_no_marketplace');
        }
        if ($submission->feed_document_id === null) {
            return $this->refuse($submission, 'amazon_feed_has_no_document_to_submit');
        }
        if ($submission->documentHasExpired()) {
            // Amazon states the call "will fail" with an expired identifier. Better to start again than to spend a submission
            // on a certainty.
            $submission->update(['state' => AmazonFeedSubmission::PENDING, 'feed_document_id' => null, 'failure_code' => 'amazon_feed_document_expired_before_submission']);

            return ['ok' => false, 'refusal' => 'amazon_feed_document_expired_before_submission'];
        }

        // ---- THE FENCE. Committed before the request, on its own, conditional on the state we read. -------------------
        $fenced = AmazonFeedSubmission::whereKey($submission->id)
            ->where('state', AmazonFeedSubmission::UPLOADED)
            ->where('claim_token', $submission->claim_token)
            ->where('lease_until', '>', now())
            ->update([
                'state' => AmazonFeedSubmission::MAY_HAVE_SUBMITTED,
                'submitted_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        if ($fenced !== 1) {
            // The row moved, or this worker's lease ran out while it was preparing. Either way the state it was about to act
            // on is gone, and acting anyway is what submits a feed twice.
            return ['ok' => false, 'refusal' => 'amazon_feed_row_moved_before_the_fence_was_set'];
        }

        // **The in-memory model must be brought back in line with the row.** The fence was a raw conditional UPDATE, so this
        // object still believes it is `uploaded`; an `update(['state' => UPLOADED])` on it would then be a no-op, because
        // Eloquent writes only what it sees as dirty -- and the row would stay fenced for ever on a refusal Amazon stated
        // plainly. The bug is invisible in isolation and strands real work, so the refresh is not tidiness.
        $submission->refresh();

        try {
            $response = $this->client->call(
                account: $account,
                operation: 'amazon.feeds_create',
                method: 'POST',
                path: '/feeds/2021-06-30/feeds',
                json: [
                    'feedType' => self::FEED_TYPE,
                    'marketplaceIds' => $marketplaceIds,
                    'inputFeedDocumentId' => $submission->feed_document_id,
                ],
            );
        } catch (ChannelUnavailableException $refused) {
            // **Thrown before anything is sent**, by the transport's own gate: the host's writes are off, the account's are,
            // or nobody has approved this scope. It is checked ahead of the request, so no feed was queued and the fence is
            // lifted. Letting this escape instead would leave a row fenced for ever over a switch, and a switch is the one
            // thing we can be sure about.
            $submission->update(['state' => AmazonFeedSubmission::UPLOADED, 'submitted_at' => null, 'failure_code' => 'amazon_feed_was_not_allowed_to_be_sent:' . $refused->reason]);

            return ['ok' => false, 'refusal' => 'amazon_feed_was_not_allowed_to_be_sent'];
        }

        if ($response->isDryRun()) {
            // A rehearsal sent nothing, so the fence is lifted: this is the other case where we KNOW no feed was queued.
            $submission->update(['state' => AmazonFeedSubmission::UPLOADED, 'submitted_at' => null, 'failure_code' => null]);

            return ['ok' => false, 'refusal' => 'amazon_rehearsal_submitted_nothing'];
        }

        if (!$response->ok()) {
            // **The distinction the fence exists for**, drawn by what the reply PROVES rather than by its number. The
            // transport already classifies that: `rejected` is "a definitive no", and `certainlyNotProcessed()` covers the
            // replies that never reached Amazon at all. Either way nothing was queued, so the row may go back and be
            // submitted again. `server_error` and `ambiguous` are the other case -- Amazon may have acted and never told us
            // -- and those stay behind the fence.
            if ($response->kind === ChannelResponse::REJECTED || $response->certainlyNotProcessed()) {
                $submission->update([
                    'state' => AmazonFeedSubmission::UPLOADED,
                    'submitted_at' => null,
                    'failure_code' => 'amazon_feed_was_refused:' . $response->kind,
                    'failure_detail' => mb_substr($response->message(), 0, 2000),
                ]);

                return ['ok' => false, 'refusal' => 'amazon_feed_was_refused:' . $response->kind];
            }

            $submission->update([
                'failure_code' => 'amazon_never_told_us_whether_the_feed_was_queued',
                'failure_detail' => mb_substr($response->message(), 0, 2000),
                'needs_review_since' => $submission->needs_review_since ?? now(),
            ]);

            return ['ok' => false, 'refusal' => 'amazon_never_told_us_whether_the_feed_was_queued'];
        }

        $feedId = trim((string) ($this->payloadOf($response)['feedId'] ?? ''));
        if ($feedId === '') {
            // `feedId` is REQUIRED on `CreateFeedResponse`. A 200 without it means the feed may well be queued and we cannot
            // name it, which is the fence's case exactly -- not a failure to retry.
            $submission->update([
                'failure_code' => 'amazon_accepted_the_feed_and_did_not_name_it',
                'needs_review_since' => $submission->needs_review_since ?? now(),
            ]);

            return ['ok' => false, 'refusal' => 'amazon_accepted_the_feed_and_did_not_name_it'];
        }

        $submission->update([
            'amazon_feed_id' => $feedId,
            'state' => AmazonFeedSubmission::SUBMITTED,
            'failure_code' => null,
            'failure_detail' => null,
            // Amazon says feeds can take up to eight hours; the first poll is not due immediately.
            'available_at' => now()->addSeconds(max(30, (int) config('sales_channels.amazon.feed_first_poll_seconds', 60))),
        ]);

        return ['ok' => true, 'refusal' => null, 'feed_id' => $feedId];
    }

    /** Step 5: one `getFeed`. Records what Amazon said and nothing more. */
    public function poll(SalesChannelAccount $account, AmazonFeedSubmission $submission): AmazonFeedState
    {
        if ($submission->amazon_feed_id === null) {
            return AmazonFeedState::unknown('amazon_feed_has_no_id_to_poll');
        }

        $response = $this->client->call(
            account: $account,
            operation: 'amazon.feeds_get',
            method: 'GET',
            path: '/feeds/2021-06-30/feeds/' . rawurlencode($submission->amazon_feed_id),
        );
        $state = AmazonFeedState::fromResponse($response, $submission->amazon_feed_id);

        $update = [
            'processing_status' => $state->processingStatus,
            'result_document_id' => $state->documentId ?? $submission->result_document_id,
        ];

        if ($state->stillWorking()) {
            $update['available_at'] = now()->addSeconds(max(30, (int) config('sales_channels.amazon.feed_poll_seconds', 300)));
        } elseif ($state->state === AmazonFeedState::CANCELLED) {
            // Amazon's own statement: cancelled before processing started. Nothing was applied.
            $update['state'] = AmazonFeedSubmission::CANCELLED;
            $update['failure_code'] = 'amazon_cancelled_the_feed_before_processing';
        } elseif ($state->state === AmazonFeedState::FAILED) {
            // FATAL. Some, none or all of it happened. A person's, and it may still carry a report worth reading.
            $update['state'] = AmazonFeedSubmission::NEEDS_REVIEW;
            $update['failure_code'] = 'amazon_stopped_the_feed_and_some_none_or_all_of_it_may_have_applied';
            $update['needs_review_since'] = $submission->needs_review_since ?? now();
        } elseif ($state->state === AmazonFeedState::UNKNOWN) {
            // We could not be told. The row keeps its state and is asked again -- never concluded.
            $update['failure_code'] = $state->problem;
            $update['available_at'] = now()->addSeconds(max(60, (int) config('sales_channels.amazon.feed_poll_seconds', 300)));
        }

        $submission->update($update);

        return $state;
    }

    /**
     * Steps 6 and 7: `getFeedDocument` and the download.
     *
     * @return array{contents: string|null, problem: string|null}
     */
    public function report(SalesChannelAccount $account, AmazonFeedSubmission $submission): array
    {
        if ($submission->result_document_id === null) {
            // Not an empty report: Amazon named no document, so there is nothing to fetch and nothing may be concluded about
            // the messages a report would have described.
            return ['contents' => null, 'problem' => 'amazon_feed_named_no_report_document'];
        }

        $response = $this->client->call(
            account: $account,
            operation: 'amazon.feeds_document',
            method: 'GET',
            path: '/feeds/2021-06-30/documents/' . rawurlencode($submission->result_document_id),
        );
        if (!$response->ok()) {
            return ['contents' => null, 'problem' => $this->problemOf($response, 'amazon_feed_report_document_could_not_be_read')];
        }

        $body = $this->payloadOf($response);
        $url = trim((string) ($body['url'] ?? ''));
        if ($url === '') {
            return ['contents' => null, 'problem' => 'amazon_feed_report_document_has_no_url'];
        }
        $compression = strtoupper(trim((string) ($body['compressionAlgorithm'] ?? '')));
        if ($compression !== '' && $compression !== self::GZIP) {
            // GZIP is the only value in Amazon's enum. An unknown algorithm is named rather than fed to a decoder that would
            // hand back bytes nobody could tell from a corrupt document.
            return ['contents' => null, 'problem' => 'amazon_feed_report_compression_not_understood:' . $compression];
        }

        return $this->download($account, $url, $compression === self::GZIP);
    }

    /**
     * Reads a fetched report against what this submission actually sent, and records the counts.
     *
     * The accepted set comes from `AmazonFeedResult`, which refuses to subtract unless the report's own `messagesProcessed`
     * equals what was sent. An unreadable report, or one about a different number of messages, leaves the row for a person:
     * **nothing is marked as having taken effect on the strength of a document we could not account for.**
     *
     * @return array{ok: bool, refusal: string|null, accepted: list<string>, failed: array<string, string>}
     */
    public function recordReport(AmazonFeedSubmission $submission, string $json): array
    {
        $result = AmazonFeedResult::parse($json);
        if (!$result->readable()) {
            $submission->update([
                'state' => AmazonFeedSubmission::NEEDS_REVIEW,
                'failure_code' => $result->refusal,
                'needs_review_since' => $submission->needs_review_since ?? now(),
            ]);

            return ['ok' => false, 'refusal' => $result->refusal, 'accepted' => [], 'failed' => []];
        }

        $sent = $submission->sentMessageIds();
        $accepted = $result->accepted($sent);

        $failedSkus = [];
        foreach ($result->failures() as $messageId => $problems) {
            $sku = $submission->skuOf((int) $messageId) ?? ('unknown_message_' . $messageId);
            $failedSkus[$sku] = (string) ($problems[0]['message'] ?? 'amazon_named_no_message');
        }

        $summary = $result->summary;
        $submission->update([
            'summary' => $summary,
            'failed_count' => count($result->failures()),
            'warning_count' => count($result->warnings()),
            // Accepted, which is Amazon's word. Its own description is "accepted, the associated updates are being applied".
            'accepted_count' => count($accepted['accepted']),
            'state' => $accepted['refusal'] === null ? AmazonFeedSubmission::READ : AmazonFeedSubmission::NEEDS_REVIEW,
            'failure_code' => $accepted['refusal'],
            'needs_review_since' => $accepted['refusal'] === null ? null : ($submission->needs_review_since ?? now()),
        ]);

        if ($accepted['refusal'] !== null) {
            return ['ok' => false, 'refusal' => $accepted['refusal'], 'accepted' => [], 'failed' => $failedSkus];
        }

        $acceptedSkus = [];
        foreach ($accepted['accepted'] as $messageId) {
            $sku = $submission->skuOf((int) $messageId);
            if ($sku !== null) {
                $acceptedSkus[] = $sku;
            }
        }

        return ['ok' => true, 'refusal' => null, 'accepted' => $acceptedSkus, 'failed' => $failedSkus];
    }

    /**
     * Tries to identify a feed that may have been queued while we were not listening.
     *
     * The only honest way back from the fence, and it is a READ. `getFeeds` is asked for this feed type in a window around
     * the attempt, and the answer is one of four:
     *
     *  - **MATCHED** — exactly one feed of this type in the window that no other row of ours claims. Its id is adopted and
     *    the row rejoins the ordinary cycle.
     *  - **SEVERAL** — more than one candidate. Which is ours is not knowable from what Amazon returns (a `Feed` carries no
     *    `inputFeedDocumentId`), so a person decides rather than a coin toss.
     *  - **NONE** — Amazon lists no such feed. **This is not read as "nothing was queued".** Amazon documents no guarantee
     *    about how soon a feed appears in this list, so an empty answer leaves the row where it is.
     *  - **UNKNOWN** — we could not ask. Never read as an absence.
     *
     * No branch of this ever submits anything.
     *
     * @return array{outcome: string, feed_id: string|null, candidates: int}
     */
    public function identify(SalesChannelAccount $account, AmazonFeedSubmission $submission, int $windowMinutes = 30): array
    {
        if ($submission->state !== AmazonFeedSubmission::MAY_HAVE_SUBMITTED) {
            return ['outcome' => 'not_behind_the_fence', 'feed_id' => null, 'candidates' => 0];
        }
        $since = ($submission->submitted_at ?? $submission->updated_at ?? now())->copy()->subMinutes(max(1, $windowMinutes));

        $response = $this->client->call(
            account: $account,
            operation: 'amazon.feeds_list',
            method: 'GET',
            path: '/feeds/2021-06-30/feeds',
            query: ['feedTypes' => self::FEED_TYPE, 'processingStatuses' => 'IN_QUEUE,IN_PROGRESS,DONE,FATAL,CANCELLED', 'createdSince' => $since->toIso8601ZuluString()],
        );
        if (!$response->ok()) {
            return ['outcome' => 'unknown', 'feed_id' => null, 'candidates' => 0];
        }

        $feeds = $this->payloadOf($response)['feeds'] ?? null;
        if (!is_array($feeds)) {
            return ['outcome' => 'unknown', 'feed_id' => null, 'candidates' => 0];
        }

        $claimed = AmazonFeedSubmission::whereNotNull('amazon_feed_id')->pluck('amazon_feed_id')->all();
        $candidates = [];
        foreach ($feeds as $feed) {
            if (!is_array($feed)) {
                continue;
            }
            $id = trim((string) ($feed['feedId'] ?? ''));
            if ($id !== '' && !in_array($id, $claimed, true)) {
                $candidates[] = $id;
            }
        }
        $candidates = array_values(array_unique($candidates));

        if (count($candidates) === 1) {
            $submission->update([
                'amazon_feed_id' => $candidates[0],
                'state' => AmazonFeedSubmission::SUBMITTED,
                'failure_code' => null,
                'available_at' => now(),
            ]);

            return ['outcome' => 'matched', 'feed_id' => $candidates[0], 'candidates' => 1];
        }

        if (count($candidates) > 1) {
            $submission->update([
                'state' => AmazonFeedSubmission::NEEDS_REVIEW,
                'failure_code' => 'amazon_lists_several_feeds_and_none_of_them_names_our_document:' . count($candidates),
                'needs_review_since' => $submission->needs_review_since ?? now(),
            ]);

            return ['outcome' => 'several', 'feed_id' => null, 'candidates' => count($candidates)];
        }

        // NONE. The row is left exactly where it is.
        return ['outcome' => 'none', 'feed_id' => null, 'candidates' => 0];
    }

    // ---- the small shared parts ----------------------------------------------------------------------------------

    /**
     * That this worker still owns the row, and that the row is in a state this step belongs to.
     *
     * @param list<string> $states
     * @return array{ok: bool, refusal: string|null}|null null when the caller may proceed
     */
    private function ours(AmazonFeedSubmission $submission, array $states): ?array
    {
        if (!in_array($submission->state, $states, true)) {
            return ['ok' => false, 'refusal' => 'amazon_feed_is_not_at_this_step:' . $submission->state];
        }
        if ($submission->claim_token === null || $submission->lease_until === null || $submission->lease_until->isPast()) {
            // A worker whose lease has run out must not keep calling: another worker may already have the row.
            return ['ok' => false, 'refusal' => 'amazon_feed_claim_has_lapsed'];
        }

        return null;
    }

    /** @return array{ok: bool, refusal: string|null} */
    private function fail(AmazonFeedSubmission $submission, string $code, ChannelResponse $response, bool $keepWorking): array
    {
        $submission->update([
            'failure_code' => $code,
            'failure_detail' => mb_substr($response->message(), 0, 2000),
            'state' => $keepWorking ? $submission->state : AmazonFeedSubmission::FAILED,
            'available_at' => now()->addSeconds(max(60, (int) config('sales_channels.amazon.feed_retry_seconds', 300))),
        ]);

        return ['ok' => false, 'refusal' => $code];
    }

    /** @return array{ok: bool, refusal: string|null} */
    private function refuse(AmazonFeedSubmission $submission, string $code): array
    {
        $submission->update(['state' => AmazonFeedSubmission::FAILED, 'failure_code' => $code]);

        return ['ok' => false, 'refusal' => $code];
    }

    /** @return array<string, mixed> */
    private function payloadOf(ChannelResponse $response): array
    {
        $json = $response->json;
        if (!is_array($json)) {
            return [];
        }

        // Older SP-API replies wrapped everything in `payload`. Both shapes are read so a wrapper does not look like an
        // incomplete reply.
        return is_array($json['payload'] ?? null) ? $json['payload'] : $json;
    }

    /** @return array{contents: string|null, problem: string|null} */
    private function download(SalesChannelAccount $account, string $url, bool $gzipped): array
    {
        $response = $this->http->send(account: $account, operation: 'amazon.feed_report_download', method: 'GET', url: $url);
        if ($response->isDryRun()) {
            return ['contents' => null, 'problem' => 'amazon_rehearsal_downloaded_nothing'];
        }
        if (!$response->ok()) {
            return ['contents' => null, 'problem' => $this->problemOf($response, 'amazon_feed_report_download_failed')];
        }

        $ceiling = max(1, (int) config('sales_channels.amazon.report_max_bytes', 33554432));
        if (strlen($response->body) > $ceiling) {
            return ['contents' => null, 'problem' => 'amazon_feed_report_is_larger_than_we_will_read:' . strlen($response->body)];
        }
        if (!$gzipped) {
            return ['contents' => $response->body, 'problem' => null];
        }

        $plain = @gzdecode($response->body);
        if ($plain === false) {
            return ['contents' => null, 'problem' => 'amazon_feed_report_gzip_could_not_be_read'];
        }
        if (strlen($plain) > $ceiling) {
            return ['contents' => null, 'problem' => 'amazon_feed_report_is_larger_than_we_will_read:' . strlen($plain)];
        }

        return ['contents' => $plain, 'problem' => null];
    }

    private function problemOf(ChannelResponse $response, string $code): string
    {
        return $response->isDryRun() ? 'amazon_rehearsal_asked_nothing' : $code . ': ' . $response->message();
    }

    /** Amazon's own window: `getFeed` "only serves information for feed requests that were created within the last 28 days". */
    public static function pollableUntil(Carbon $submittedAt): Carbon
    {
        return $submittedAt->copy()->addDays(28);
    }
}
