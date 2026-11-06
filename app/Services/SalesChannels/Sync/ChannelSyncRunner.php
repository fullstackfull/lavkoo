<?php

namespace App\Services\SalesChannels\Sync;

use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one resource of one account's sync under a lease and settles the outcome honestly.
 *
 *  - a switch or capability that is off is a SKIP (nothing failed, nothing was sent), not an error;
 *  - a rate limit is a PAUSE with a back-off, not a failure;
 *  - an authorisation the channel refused marks the account so a person re-authorises it, and everything waits;
 *  - anything else is a FAILURE with the reason, and the resource backs off longer after each one.
 * A failure is never reported as success: the result says what happened.
 */
class ChannelSyncRunner
{
    public const OK = 'ok';
    public const SKIPPED = 'skipped';
    public const BUSY = 'busy';
    public const RATE_LIMITED = 'rate_limited';
    public const FAILED = 'failed';

    public function __construct(
        private readonly SyncStateStore $states,
        private readonly ChannelAccountService $accounts,
    ) {
    }

    /**
     * @param Closure(SyncLease): array{watermark?: \Carbon\CarbonInterface|null, cursor?: array<string, mixed>|null, stats?: array<string, mixed>} $work
     * @return array{result: string, detail: string|null, stats: array<string, mixed>}
     */
    public function run(SalesChannelAccount $account, string $resource, Closure $work): array
    {
        $lease = $this->states->acquire((int) $account->id, $resource);
        if ($lease === null) {
            return ['result' => self::BUSY, 'detail' => null, 'stats' => []];
        }

        try {
            $outcome = $work($lease);
        } catch (ChannelUnavailableException $exception) {
            if ($exception->reason === ChannelUnavailableException::AUTH_NEEDED) {
                $this->accounts->markAuthorisationLost($account, $exception->getMessage());
            }
            $this->states->skip($lease, $exception->getMessage());

            return ['result' => self::SKIPPED, 'detail' => $exception->reason, 'stats' => []];
        } catch (ChannelRateLimitedException $exception) {
            $this->states->backOff($lease, $exception->retryAfterSeconds, $exception->getMessage());

            return ['result' => self::RATE_LIMITED, 'detail' => (string) $exception->retryAfterSeconds, 'stats' => []];
        } catch (Throwable $exception) {
            $message = PiiRedactor::scrub($exception::class . ': ' . $exception->getMessage(), 1000);
            Log::warning('Sales channel sync failed.', ['account_id' => $account->id, 'resource' => $resource, 'error' => $message]);
            $this->states->fail($lease, $message);

            return ['result' => self::FAILED, 'detail' => $message, 'stats' => []];
        }

        $stats = $outcome['stats'] ?? [];
        if (($outcome['cursor'] ?? null) !== null) {
            $this->states->pause($lease, $outcome['cursor'], $stats);
        } else {
            $this->states->succeed($lease, $outcome['watermark'] ?? $lease->watermark, $stats);
            $account->forceFill(['last_synced_at' => now()])->save();
        }

        return ['result' => self::OK, 'detail' => null, 'stats' => $stats];
    }
}
