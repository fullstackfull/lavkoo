<?php

namespace App\Services\SalesChannels\Amazon;

use App\Services\SalesChannels\Data\ChannelResponse;

/**
 * What Amazon says became of one feed — in the answers that mean different things to us, not the five strings it sends.
 *
 * Amazon's `processingStatus` has exactly five values (`CANCELLED`, `DONE`, `FATAL`, `IN_PROGRESS`, `IN_QUEUE`, the enum of
 * the `Feed` schema in `models/feeds-api-model/feeds_2021-06-30.json`). Three of them are terminal and the guide names them:
 * "until the feed moves into one of the following terminal states: DONE, CANCELLED, or FATAL".
 *
 * ## The two that are NOT the same as each other, however alike they look
 *
 *  - **`CANCELLED` means nothing happened.** Amazon states it plainly: "the feed was cancelled before it started processing.
 *    If you want to submit the feed again, start again at Step 1." Nothing was applied, so this is the one terminal failure a
 *    machine may resubmit from.
 *  - **`FATAL` means nobody knows what happened.** Amazon's words: "Some, none, or all of the operations within the feed
 *    might have completed successfully. In some (but not all) cases Amazon generates a feed processing report. If Amazon
 *    generates a report, it could be in a different format." So a FATAL feed of price and quantity changes is an UNKNOWN
 *    outcome over up to 25,000 SKUs, and resubmitting it blind is a second write of unknown prices on top of unknown prices.
 *    It goes to a person. Amazon's own closing advice — "If you can find no errors in the feed to correct, try submitting the
 *    feed again" — is advice to a human reading a report, not a rule a worker may act on by itself.
 *
 * And a reply nobody could read is `UNKNOWN`, which is **never** read as cancelled, failed or finished. A 503, a timeout or a
 * body that would not decode all say we could not ask — a different fact from Amazon telling us anything at all.
 *
 * ## `resultFeedDocumentId` is read, never inferred
 *
 * It is optional on the `Feed` schema. A `DONE` feed may carry one and a `FATAL` feed "in some (but not all) cases" does too,
 * so the presence of a report is read from the field and nothing is concluded from the status alone.
 *
 * Nothing here retries, waits or decides. It answers what is known; `AmazonFeedSubmitter` holds what to do about it.
 */
final readonly class AmazonFeedState
{
    /** `IN_QUEUE`: Amazon has it and has not started. The only state `cancelFeed` is documented to work from. */
    public const QUEUED = 'queued';

    /** `IN_PROGRESS`. */
    public const RUNNING = 'running';

    /** `DONE`: processing finished. Whether there is a report to read is a separate question, answered by `documentId`. */
    public const DONE = 'done';

    /** `CANCELLED`: **nothing was applied**, by Amazon's own statement. The one failure a machine may resubmit from. */
    public const CANCELLED = 'cancelled';

    /** `FATAL`: **some, none or all of it happened.** An UNKNOWN outcome that belongs to a person, not to a retry. */
    public const FAILED = 'failed';

    /** We could not be told. **Never read as cancelled, failed or finished.** */
    public const UNKNOWN = 'unknown';

    /** Amazon's own terminal set, in its own words: "DONE, CANCELLED, or FATAL". */
    private const TERMINAL = [self::DONE, self::CANCELLED, self::FAILED];

    private function __construct(
        public string $state,
        public ?string $feedId = null,
        /** `resultFeedDocumentId`, read from the field. Null means Amazon named no report, not that none exists. */
        public ?string $documentId = null,
        /** Amazon's `processingStatus`, kept verbatim so a screen can show what it actually said. */
        public ?string $processingStatus = null,
        public ?string $problem = null,
        public ?string $feedType = null,
        public ?string $createdTime = null,
        public ?string $processingEndTime = null,
    ) {
    }

    /**
     * Reads one `Feed` object.
     *
     * @param array<string, mixed> $feed
     */
    public static function of(array $feed): self
    {
        $status = strtoupper(trim((string) ($feed['processingStatus'] ?? '')));
        $state = match ($status) {
            'IN_QUEUE' => self::QUEUED,
            'IN_PROGRESS' => self::RUNNING,
            'DONE' => self::DONE,
            'CANCELLED' => self::CANCELLED,
            'FATAL' => self::FAILED,
            // A sixth value would be a change to Amazon's enum. It is not guessed into one of the five: an unrecognised
            // status is something we were not told, and the row waits for a person rather than being called finished.
            default => self::UNKNOWN,
        };

        $document = trim((string) ($feed['resultFeedDocumentId'] ?? ''));

        return new self(
            state: $state,
            feedId: ($id = trim((string) ($feed['feedId'] ?? ''))) === '' ? null : $id,
            documentId: $document === '' ? null : $document,
            processingStatus: $status === '' ? null : $status,
            problem: $state === self::UNKNOWN ? 'amazon_feed_status_is_not_one_of_the_five_documented_values:' . ($status === '' ? 'missing' : $status) : null,
            feedType: ($type = trim((string) ($feed['feedType'] ?? ''))) === '' ? null : $type,
            createdTime: ($created = trim((string) ($feed['createdTime'] ?? ''))) === '' ? null : $created,
            processingEndTime: ($ended = trim((string) ($feed['processingEndTime'] ?? ''))) === '' ? null : $ended,
        );
    }

    /** A reply we could not read. The problem is recorded; nothing about the feed is claimed. */
    public static function unknown(string $problem, ?string $feedId = null): self
    {
        return new self(state: self::UNKNOWN, feedId: $feedId, problem: $problem);
    }

    /** Reads a `getFeed` response, or says we could not be told. */
    public static function fromResponse(ChannelResponse $response, ?string $feedId = null): self
    {
        if (!$response->ok()) {
            return self::unknown('amazon_feed_could_not_be_read:' . $response->kind, $feedId);
        }
        $body = $response->json;
        if (!is_array($body) || !isset($body['processingStatus'])) {
            // `processingStatus` is required on the schema, so a body without it is not a Feed object and nothing may be
            // read from it -- least of all "it is not finished", which would have a worker poll an unknown row for ever.
            return self::unknown('amazon_feed_reply_is_not_a_feed_object', $feedId);
        }

        return self::of($body);
    }

    /** Amazon has finished with it, one way or another. */
    public function terminal(): bool
    {
        return in_array($this->state, self::TERMINAL, true);
    }

    /** There is a report to fetch, because Amazon named one — not because the status looked like there should be. */
    public function hasReport(): bool
    {
        return $this->documentId !== null;
    }

    /**
     * Whether the work in this feed may be submitted again by a machine.
     *
     * True for `CANCELLED` alone, and that is not a conservatism: Amazon states that a cancelled feed was stopped "before it
     * started processing", so there is nothing to double. Everything else is either unfinished or of unknown effect.
     */
    public function safeToSubmitAgain(): bool
    {
        return $this->state === self::CANCELLED;
    }

    /** Still working: keep waiting rather than concluding anything. */
    public function stillWorking(): bool
    {
        return $this->state === self::QUEUED || $this->state === self::RUNNING;
    }
}
