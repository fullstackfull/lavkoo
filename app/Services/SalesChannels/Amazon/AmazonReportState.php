<?php

namespace App\Services\SalesChannels\Amazon;

use App\Services\SalesChannels\Data\ChannelResponse;

/**
 * What Amazon says about one report — in the five answers that mean different things to us, not the five strings it sends.
 *
 * The four `processingStatus` values map onto this almost one to one, and the exception is the whole reason for the class:
 *
 *  - **`DONE` does not mean there is a document.** The reference page calls `reportDocumentId` optional and the use-case guide
 *    says the response "includes a reportDocumentId value if there is report data available". `FATAL` "might" carry one too.
 *    So the presence of a document is READ, never inferred from the status, and `DONE` with nothing to fetch is its own answer.
 *  - **A reply nobody could read is `UNKNOWN`, and UNKNOWN IS NEVER "NO REPORT".** A 503, a timeout, a body that would not
 *    decode: each says we could not ask, which is a different fact from Amazon saying the report was cancelled. Read the other
 *    way, a request would be made again and Amazon's rate limit for `createReport` is one call a minute.
 *
 * Nothing here retries, waits or decides: it answers what is known. `AmazonReportRun` holds what to do about it.
 */
final readonly class AmazonReportState
{
    /** Amazon has it and has not started: `IN_QUEUE`. */
    public const QUEUED = 'queued';

    /** `IN_PROGRESS`. */
    public const RUNNING = 'running';

    /** Processing ended and there IS a document to fetch. The only state a document may be downloaded from. */
    public const READY = 'ready';

    /** Processing ended with no document: `DONE` or `CANCELLED` with nothing to fetch — including "no data to return". */
    public const EMPTY_RESULT = 'empty_result';

    /** `FATAL`. It may still carry a document, which is why `documentId` is read rather than assumed absent. */
    public const FAILED = 'failed';

    /** We could not be told. **Never read as "there is no report".** */
    public const UNKNOWN = 'unknown';

    private function __construct(
        public string $state,
        public ?string $reportId = null,
        public ?string $documentId = null,
        /** Amazon's own `processingStatus`, kept verbatim so a screen can show what it actually said. */
        public ?string $processingStatus = null,
        public ?string $problem = null,
        /** `reportType` is required on the `Report` schema, so a report Amazon lists always says what it is. */
        public ?string $reportType = null,
        /**
         * The window Amazon says the report covers. **Both are OPTIONAL on the schema**, and that matters: a report whose
         * window Amazon does not state cannot be matched to a request of ours, so it is not claimed as one.
         */
        public ?string $dataStartTime = null,
        public ?string $dataEndTime = null,
    ) {
    }

    /**
     * Reads one `Report` object.
     *
     * @param array<string, mixed> $report
     */
    public static function of(array $report): self
    {
        $status = trim((string) ($report['processingStatus'] ?? ''));
        $reportId = self::text($report, 'reportId');
        $documentId = self::text($report, 'reportDocumentId');

        // The document decides, not the status: DONE without one has nothing to fetch, and FATAL with one does.
        $state = match ($status) {
            'IN_QUEUE' => self::QUEUED,
            'IN_PROGRESS' => self::RUNNING,
            'DONE', 'CANCELLED' => $documentId === null ? self::EMPTY_RESULT : self::READY,
            'FATAL' => self::FAILED,
            default => self::UNKNOWN,
        };

        return new self(
            state: $state,
            reportId: $reportId,
            documentId: $documentId,
            processingStatus: $status === '' ? null : $status,
            problem: $state === self::UNKNOWN ? 'amazon_report_status_not_understood:' . ($status === '' ? 'absent' : $status) : null,
            reportType: self::text($report, 'reportType'),
            dataStartTime: self::text($report, 'dataStartTime'),
            dataEndTime: self::text($report, 'dataEndTime'),
        );
    }

    /** A reply that could not be read at all. The report may be anywhere; nothing is concluded about it. */
    public static function couldNotBeAsked(ChannelResponse $response, ?string $reportId = null): self
    {
        return new self(
            state: self::UNKNOWN,
            reportId: $reportId,
            problem: $response->isDryRun() ? 'amazon_rehearsal_asked_nothing' : 'amazon_report_could_not_be_asked_about: ' . $response->message(),
        );
    }

    public function isReady(): bool
    {
        return $this->state === self::READY && $this->documentId !== null;
    }

    /** Has processing ended, whatever it ended as? A queued or running report is simply not finished. */
    public function hasEnded(): bool
    {
        return in_array($this->state, [self::READY, self::EMPTY_RESULT, self::FAILED], true);
    }

    /** Is it worth asking again? Only while Amazon is still working on it, or while we could not be told. */
    public function worthAskingAgain(): bool
    {
        return in_array($this->state, [self::QUEUED, self::RUNNING, self::UNKNOWN], true);
    }

    /** @param array<string, mixed> $report */
    private static function text(array $report, string $key): ?string
    {
        $value = isset($report[$key]) ? trim((string) $report[$key]) : '';

        return $value === '' ? null : $value;
    }
}
