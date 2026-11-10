<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\AmazonReportRun;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/**
 * Drives one Amazon report from "we need this" to "a consumer has read it", without ever asking for the same one twice.
 *
 * ## The request is fenced, for the same reason an Odoo entry is
 *
 * `createReport` returns the `reportId` in its own reply. So the dangerous instant is not between two writes of ours — it is
 * the reply itself: Amazon can accept the request, start making the report, and the reply can be lost to a timeout, a reset or
 * a 503. The report then EXISTS, it is costing the account its one-call-a-minute allowance, and we do not know its number.
 *
 * A row that asked again there would make a second report. So `requested_at` is written **before the call**, and from that
 * instant the row may never call `createReport` again. Its only way forward is `getReports`, which is precisely the operation
 * Amazon provides for finding a report you have — and which `find()` already uses for settlements. Recovery is therefore the
 * same code path as discovery, not a special case.
 *
 * This is the write-ahead marker the owner's review required of the noon shipments and of the Odoo postings, applied to the one
 * other place in this codebase where a lost reply means an external thing exists under a name we never learned.
 *
 * ## And a settlement report is never requested at all
 *
 * "Settlement reports cannot be requested or scheduled. They are automatically scheduled by Amazon." So `discover()` finds
 * them and records a row for each, with `requested` false: the row describes a document that already existed. `AmazonReports`
 * refuses the request as well, so neither layer depends on the other remembering.
 */
class AmazonReportRunner
{
    /** How long a run that could not be told anything waits before asking again. */
    private const RETRY_SECONDS = 900;

    /** @param iterable<ReadsAmazonReports> $consumers */
    public function __construct(
        private readonly AmazonReports $reports,
        private readonly iterable $consumers,
    ) {
    }

    /** The reader needs its own table; until the migration runs it says so instead of failing on a missing table. */
    public static function ready(): bool
    {
        return Schema::hasTable('amazon_report_runs');
    }

    /**
     * Records that we want one report, and asks Amazon for it if it has not been asked for.
     *
     * Calling this twice for the same account, type and window is one row and one request: the unique key decides, not a check
     * followed by an insert.
     *
     * @param array<string, scalar> $options
     * @throws ChannelPermissionDenied
     */
    public function ask(
        SalesChannelAccount $account,
        string $reportType,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $options = [],
    ): AmazonReportRun {
        $window = $from->toDateString() . '..' . $to->toDateString();
        $row = $this->rowFor($account, $reportType, $window, [
            'window_start' => $from,
            'window_end' => $to,
            'marketplace_id' => (string) $account->setting('marketplace_id', ''),
            'requested' => true,
        ]);

        return $row->state === AmazonReportRun::PENDING ? $this->sendTheRequest($account, $row, $from, $to, $options) : $row;
    }

    /**
     * Finds the reports Amazon made by itself and records one row for each.
     *
     * The ONLY route to a settlement report, and also how a row whose request reply was lost finds its report again — one
     * operation, one code path, because the question is identical: "which reports of this type does Amazon hold?"
     *
     * @param list<string> $reportTypes
     * @return array{found: int, recovered: int, problem: string|null}
     * @throws ChannelPermissionDenied
     */
    public function discover(SalesChannelAccount $account, array $reportTypes, CarbonImmutable $since, ?CarbonImmutable $until = null): array
    {
        $answer = $this->reports->find($account, $reportTypes, $since, $until);
        if ($answer['problem'] !== null) {
            return ['found' => 0, 'recovered' => 0, 'problem' => $answer['problem']];
        }

        $found = 0;
        $recovered = 0;
        foreach ($answer['reports'] as $state) {
            if ($state->reportId === null) {
                continue;
            }
            // Already ours, under this id or as a found document: nothing to do, and never counted twice.
            if (AmazonReportRun::where('account_id', $account->id)->where('amazon_report_id', $state->reportId)->exists()) {
                continue;
            }

            $orphan = $this->orphanFor($account, $state);
            if ($orphan !== null) {
                $orphan->forceFill([
                    'amazon_report_id' => $state->reportId,
                    'state' => AmazonReportRun::REQUESTED,
                    'processing_status' => $state->processingStatus,
                    'available_at' => null,
                    'failure_code' => null,
                    'failure_detail' => 'Found with getReports after the request reply was lost. It is polled, never requested again.',
                ])->save();
                $recovered++;

                continue;
            }

            // A report nobody here asked for -- a settlement, or one the seller requested in Seller Central. Amazon's own id is
            // its window, because there is no window we chose.
            $row = $this->rowFor($account, $this->typeOf($state, $reportTypes), 'amazon:' . $state->reportId, [
                'requested' => false,
                'amazon_report_id' => $state->reportId,
                'state' => AmazonReportRun::REQUESTED,
                'processing_status' => $state->processingStatus,
            ]);
            if ($row->wasRecentlyCreated) {
                $found++;
            }
        }

        return ['found' => $found, 'recovered' => $recovered, 'problem' => null];
    }

    /**
     * Works the due rows: polls what Amazon is still making, fetches what is ready, and hands each document to its consumer.
     *
     * @return array{polled: int, read: int, empty: int, failed: int, unknown: int}
     * @throws ChannelPermissionDenied
     */
    public function work(SalesChannelAccount $account, int $limit = 10): array
    {
        $stats = ['polled' => 0, 'read' => 0, 'empty' => 0, 'failed' => 0, 'unknown' => 0];
        $rows = AmazonReportRun::due()->where('account_id', $account->id)->orderBy('id')->limit(max(1, $limit))->get();

        foreach ($rows as $row) {
            if (!$row->isKnownToAmazon()) {
                // Either nothing was asked yet, or the reply was lost. Neither is polled, and the second is never requested
                // again: `discover()` is what finds it.
                $stats['unknown']++;

                continue;
            }
            $stats['polled']++;
            $state = $this->reports->status($account, (string) $row->amazon_report_id);
            $this->settle($account, $row, $state, $stats);
        }

        return $stats;
    }

    /** @param array<string, int> $stats */
    private function settle(SalesChannelAccount $account, AmazonReportRun $row, AmazonReportState $state, array &$stats): void
    {
        $row->forceFill(['processing_status' => $state->processingStatus, 'attempts' => $row->attempts + 1])->save();

        if ($state->worthAskingAgain()) {
            $row->forceFill([
                'state' => $state->state === AmazonReportState::UNKNOWN ? AmazonReportRun::UNKNOWN : AmazonReportRun::REQUESTED,
                'available_at' => now()->addSeconds(self::RETRY_SECONDS),
                'failure_code' => $state->problem === null ? null : 'amazon_report_not_settled',
                'failure_detail' => $state->problem,
            ])->save();
            if ($state->state === AmazonReportState::UNKNOWN) {
                $stats['unknown']++;
            }

            return;
        }

        if ($state->state === AmazonReportState::EMPTY_RESULT) {
            // DONE or CANCELLED with nothing to fetch -- including Amazon's documented "automatic cancellation if there is no
            // data to return". Terminal, and NOT a failure: there was simply nothing in the window.
            $row->forceFill(['state' => AmazonReportRun::EMPTY_RESULT, 'available_at' => null, 'read_at' => now(), 'outcome' => 'no_document'])->save();
            $stats['empty']++;

            return;
        }

        if (!$state->isReady()) {
            // FATAL without a document. The page says FATAL "might" carry one, so this is the branch where it did not.
            $row->forceFill([
                'state' => AmazonReportRun::FAILED, 'available_at' => null,
                'failure_code' => 'amazon_report_processing_failed', 'failure_detail' => (string) $state->processingStatus,
            ])->save();
            $stats['failed']++;

            return;
        }

        $this->read($account, $row, (string) $state->documentId, $stats);
    }

    /** @param array<string, int> $stats */
    private function read(SalesChannelAccount $account, AmazonReportRun $row, string $documentId, array &$stats): void
    {
        $row->forceFill(['amazon_document_id' => $documentId])->save();
        $consumer = $this->consumerFor($row->report_type);
        if ($consumer instanceof ReadsAmazonReportsInBatches) {
            // The document is never held whole for a consumer that does not need it whole. Decided by the consumer's TYPE,
            // so a reader that scans the document (the settlement) cannot end up on this path.
            $this->stream($account, $row, $documentId, $consumer, $stats);

            return;
        }

        $document = $this->reports->contents($account, $documentId);
        if ($document['contents'] === null) {
            $row->forceFill([
                'state' => AmazonReportRun::FAILED, 'available_at' => null,
                'failure_code' => 'amazon_report_document_unreadable', 'failure_detail' => $document['problem'],
            ])->save();
            $stats['failed']++;

            return;
        }

        $contents = (string) $document['contents'];
        $file = AmazonFlatFile::read($contents, AmazonReportTypes::requiredColumns($row->report_type));
        if (!$file->isReadable()) {
            // The locale case among others: a header we cannot recognise is a refusal, because parsing on would hand back rows
            // of empty cells -- indistinguishable from a seller with nothing to report.
            $row->forceFill([
                'state' => AmazonReportRun::FAILED, 'available_at' => null,
                'document_hash' => hash('sha256', $contents),
                'failure_code' => (string) $file->refusal, 'failure_detail' => 'Header read: ' . implode(', ', array_slice($file->header, 0, 12)),
            ])->save();
            $stats['failed']++;

            return;
        }

        $consumer = $this->consumerFor($row->report_type);
        if ($consumer === null) {
            // A type we can fetch but nothing has been written to make sense of. Named rather than counted as read, so nobody
            // reads the row as "the settlement was imported".
            $row->forceFill([
                'state' => AmazonReportRun::FAILED, 'available_at' => null,
                'document_hash' => hash('sha256', $contents), 'rows_read' => count($file->rows),
                'failure_code' => 'amazon_report_has_no_reader:' . $row->report_type,
            ])->save();
            $stats['failed']++;

            return;
        }

        $outcome = $consumer->read($row, $file, $account);
        $row->forceFill([
            'state' => AmazonReportRun::READ,
            'available_at' => null,
            'read_at' => now(),
            'document_hash' => hash('sha256', $contents),
            'rows_read' => (int) ($outcome['rows_read'] ?? count($file->rows)),
            'rows_refused' => (int) ($outcome['rows_refused'] ?? 0),
            'outcome' => isset($outcome['outcome']) ? mb_substr((string) $outcome['outcome'], 0, 64) : null,
            'failure_code' => null,
            'failure_detail' => isset($outcome['detail']) ? (string) $outcome['detail'] : null,
        ])->save();
        $stats['read']++;
    }

    /**
     * The streaming read, for a consumer that is correct on any slice of the document.
     *
     * Three things are kept honest rather than convenient. **A half-read document stays half-read:** the rows already handed
     * over have been counted, so the row records them AND the refusal, and `finish(documentComplete: false)` tells the
     * consumer its totals are floors — a truncated catalogue must never be written down as a finished comparison. **There is
     * no `document_hash`**, because hashing needs the whole document in hand, which is the one thing this path refuses to do,
     * so the column is left null rather than filled with the hash of a fragment. And **the consumer's own summary wins**:
     * `finish()` is asked once, after the last batch, instead of stitching together whatever each batch happened to say.
     *
     * @param array<string, int> $stats
     */
    private function stream(
        SalesChannelAccount $account,
        AmazonReportRun $row,
        string $documentId,
        ReadsAmazonReportsInBatches $consumer,
        array &$stats,
    ): void {
        $read = 0;
        $refused = 0;

        $result = $this->reports->readInBatches(
            account: $account,
            documentId: $documentId,
            onBatch: static function (AmazonFlatFile $batch) use ($consumer, $row, $account, &$read, &$refused): void {
                $outcome = $consumer->read($row, $batch, $account);
                $read += (int) ($outcome['rows_read'] ?? 0);
                $refused += (int) ($outcome['rows_refused'] ?? 0);
            },
            required: AmazonReportTypes::requiredColumns($row->report_type),
        );

        $complete = $result['refusal'] === null;
        $summary = $consumer->finish($row, $complete);
        $detail = isset($summary['detail']) ? mb_substr((string) $summary['detail'], 0, 2000) : null;
        $outcome = isset($summary['outcome']) ? mb_substr((string) $summary['outcome'], 0, 64) : null;

        if (!$complete) {
            // NOT `read`: a run whose document stopped part way is a failure with what it managed to see recorded, never a
            // finished one. The consumer has already been told, so its own summary says the figures are floors.
            $row->forceFill([
                'state' => AmazonReportRun::FAILED, 'available_at' => null,
                'rows_read' => $read, 'rows_refused' => $refused,
                'outcome' => $outcome,
                'failure_code' => mb_substr((string) $result['refusal'], 0, 120),
                'failure_detail' => $detail,
            ])->save();
            $stats['failed']++;

            return;
        }

        $row->forceFill([
            'state' => AmazonReportRun::READ,
            'available_at' => null,
            'read_at' => now(),
            'rows_read' => $read,
            'rows_refused' => $refused,
            'outcome' => $outcome,
            'failure_code' => null,
            'failure_detail' => $detail,
        ])->save();
        $stats['read']++;
    }

    /**
     * **The fence: `requested_at` is written BEFORE the call.**
     *
     * From the moment it commits, this row may never call `createReport` again. A lost reply leaves a report at Amazon under a
     * number we never saw, and the only safe way forward is to find it with `getReports` — which `discover()` does.
     *
     * @param array<string, scalar> $options
     * @throws ChannelPermissionDenied
     */
    private function sendTheRequest(
        SalesChannelAccount $account,
        AmazonReportRun $row,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $options,
    ): AmazonReportRun {
        $row->forceFill(['requested_at' => now(), 'attempts' => $row->attempts + 1, 'state' => AmazonReportRun::UNKNOWN])->save();

        $marketplace = trim((string) $account->setting('marketplace_id', ''));
        $answer = $this->reports->request(
            $account,
            $row->report_type,
            // One marketplace: the all-listings page says "only the first marketplaceId in the list will be accepted", so
            // sending several would have three of them silently ignored.
            $marketplace === '' ? [] : [$marketplace],
            $from,
            $to,
            $options,
        );

        if ($answer['report_id'] !== null) {
            $row->forceFill([
                'amazon_report_id' => $answer['report_id'],
                'state' => AmazonReportRun::REQUESTED,
                'available_at' => null,
                'failure_code' => null,
                'failure_detail' => null,
            ])->save();

            return $row->fresh() ?? $row;
        }

        // No id. Two very different reasons, and only one of them means nothing was created -- a type we refused to request, or
        // a marketplace nobody set, neither of which left this host. Everything else may have made a report.
        $nothingWasSent = str_starts_with((string) $answer['problem'], 'amazon_report_type_is_not_requestable')
            || str_starts_with((string) $answer['problem'], 'amazon_report_type_not_declared')
            || (string) $answer['problem'] === 'amazon_report_needs_a_marketplace';

        $row->forceFill([
            'state' => $nothingWasSent ? AmazonReportRun::FAILED : AmazonReportRun::UNKNOWN,
            'requested_at' => $nothingWasSent ? null : $row->requested_at,
            'available_at' => $nothingWasSent ? null : now()->addSeconds(self::RETRY_SECONDS),
            'failure_code' => mb_substr((string) $answer['problem'], 0, 120),
            'failure_detail' => $nothingWasSent ? null : 'A report may exist at Amazon under a number we never saw; it is found with getReports, never requested again.',
        ])->save();

        return $row->fresh() ?? $row;
    }

    /**
     * The row for one key, created if it is new.
     *
     * The insert is tried and the collision caught rather than checked for first: a check followed by an insert is two
     * statements with a gap, and two runs deciding the same report is needed would both pass the check.
     *
     * @param array<string, mixed> $attributes
     */
    private function rowFor(SalesChannelAccount $account, string $reportType, string $window, array $attributes): AmazonReportRun
    {
        $key = AmazonReportRun::keyFor((int) $account->id, $reportType, $window);

        try {
            return AmazonReportRun::create($attributes + [
                'seller_id' => (int) ($account->seller_id ?? 0),
                'account_id' => (int) $account->id,
                'report_type' => $reportType,
                'request_key' => $key,
                'state' => $attributes['state'] ?? AmazonReportRun::PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            return AmazonReportRun::where('request_key', $key)->firstOrFail();
        }
    }

    /**
     * Which declared type a found report is.
     *
     * `getReports` answers with the type it holds, so this reads it rather than assuming the first type asked for; a report of
     * a type we did not ask about is kept under its own name so nothing is filed as a settlement that is not one.
     *
     * @param list<string> $asked
     */
    private function typeOf(AmazonReportState $state, array $asked): string
    {
        $reported = trim((string) ($state->reportType ?? ''));

        return $reported !== '' ? $reported : (string) ($asked[0] ?? 'unknown');
    }

    /**
     * The row this found report belongs to, when it demonstrably does — and null whenever that cannot be shown.
     *
     * A row that asked and lost its reply is waiting for exactly one report, and attaching the WRONG one would file somebody
     * else's window under our name and hand a consumer rows for a period we never asked about. So three things must agree: the
     * account, the report type, and **the window Amazon itself states**. `dataStartTime` and `dataEndTime` are optional on the
     * schema, so a report whose window Amazon does not state is never claimed — it becomes a found report in its own right,
     * which costs a row and claims nothing.
     */
    private function orphanFor(SalesChannelAccount $account, AmazonReportState $state): ?AmazonReportRun
    {
        if ($state->reportType === null || $state->dataStartTime === null || $state->dataEndTime === null) {
            return null;
        }

        return AmazonReportRun::where('account_id', $account->id)
            ->where('report_type', $state->reportType)
            ->whereNull('amazon_report_id')
            ->whereNotNull('requested_at')
            ->where('state', AmazonReportRun::UNKNOWN)
            ->whereDate('window_start', CarbonImmutable::parse($state->dataStartTime)->toDateString())
            ->whereDate('window_end', CarbonImmutable::parse($state->dataEndTime)->toDateString())
            ->orderBy('id')
            ->first();
    }

    private function consumerFor(string $reportType): ?ReadsAmazonReports
    {
        foreach ($this->consumers as $consumer) {
            if ($consumer->handles($reportType)) {
                return $consumer;
            }
        }

        return null;
    }
}
