<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One Amazon report we asked for, or one Amazon made by itself that we found — and the row that stops a second request.
 *
 * `request_key` is unique, which is the whole defence: **Amazon's Reports API documents no idempotency of any kind.** A
 * duplicate request costs an allowance of one call a minute that the account cannot get back, and hands a consumer the same
 * rows twice.
 *
 * `amazon_report_id` is written the instant Amazon gives one, before anything is polled, so a run that dies in between still
 * knows which report to ask about rather than making another.
 *
 * @property int $id
 * @property string $report_type
 * @property string $request_key
 * @property string|null $amazon_report_id
 * @property string|null $amazon_document_id
 * @property string $state
 * @property int $attempts
 */
class AmazonReportRun extends Model
{
    /** Nothing has been asked yet. */
    public const PENDING = 'pending';

    /** Amazon has it — asked for, or found among the ones it schedules itself — and its id is known. */
    public const REQUESTED = 'requested';

    /** The document was fetched and handed to a consumer. Terminal. */
    public const READ = 'read';

    /** Processing ended with no document, which includes Amazon cancelling a report because there was no data. Terminal. */
    public const EMPTY_RESULT = 'empty';

    /** FATAL, refused, or a document nobody could read. The reason is recorded. */
    public const FAILED = 'failed';

    /**
     * **We could not be told, and this is NEVER read as "there is no report".**
     *
     * The same discipline as every other answer in this module: a 503, a timeout or a body that would not decode says we could
     * not ask, which is a different fact from Amazon saying the report does not exist. Read the other way, the row would be
     * requested again — and `createReport` allows one call a minute.
     */
    public const UNKNOWN = 'unknown';

    protected $table = 'amazon_report_runs';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'account_id' => 'integer',
        'requested' => 'boolean',
        'attempts' => 'integer',
        'rows_read' => 'integer',
        'rows_refused' => 'integer',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'available_at' => 'datetime',
        'requested_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * Rows a run may work: due, and in a state that still has something to do.
     *
     * `unknown` IS among them, because a row we could not be told about still has a question to put to Amazon — and the state
     * itself is what stops a second `createReport`, since a row carrying a report id is polled and never requested again.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('state', [self::PENDING, self::REQUESTED, self::UNKNOWN])
            ->where(fn (Builder $inner): Builder => $inner->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    /** True once Amazon has named the report: from here it is polled, never requested again. */
    public function isKnownToAmazon(): bool
    {
        return $this->amazon_report_id !== null && trim($this->amazon_report_id) !== '';
    }

    public function isFinished(): bool
    {
        return in_array($this->state, [self::READ, self::EMPTY_RESULT], true);
    }

    /**
     * The key that makes a second ask a collision.
     *
     * The window is part of it because the same report type for a different month is a different fact; the account is part of it
     * because two sellers' reports are not interchangeable. A settlement report keys on the settlement Amazon itself numbered,
     * which is why `found()` passes it: there is no window we chose.
     */
    public static function keyFor(int $accountId, string $reportType, string $window): string
    {
        return $accountId . ':' . $reportType . ':' . $window;
    }
}
