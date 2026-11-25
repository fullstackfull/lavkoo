<?php

namespace App\Services\Identity;

use Carbon\Carbon;
use Illuminate\Container\Attributes\Singleton;

/**
 * How long a one-time code row is still blocked for after too many wrong codes (A2-05).
 *
 * The block itself is written by the verify paths, which count the wrong codes and set `is_temp_blocked` and `temp_block_time` once the
 * operator's `maximum_otp_hit` is reached. What was missing is that the RESEND paths never asked: `resend_otp_check_phone` and
 * `resend_otp_check_email` cleared `otp_hit_count`, `is_temp_blocked` and `temp_block_time` for anyone who knew the phone number or the
 * e-mail address, so whoever was guessing codes simply asked for a new one and the block was gone. The gate counted to five for ever and
 * never closed.
 *
 * The arithmetic here is EXACTLY the arithmetic the verify paths already do, on purpose, so the two cannot disagree about whether a block
 * is still live -- including the part that is wrong. `temporary_block_time` is labelled minutes in the admin settings and is compared
 * against a count of SECONDS, so a block the operator set to five minutes lasts five seconds. That is pinned as it stands in
 * OneTimeCodeBlockTest (`test_current_behaviour_...`) rather than quietly changed here: making every block sixty times longer is a decision
 * about the live Saudi login, not a detail of this fix, and it belongs in a change of its own where it can be seen.
 */
#[Singleton]
final class OneTimeCodeBlock
{
    private const DEFAULT_LENGTH = 5;

    /** The seconds a row is still blocked for, and 0 when it is not blocked or the block has run out. */
    public function remainingSeconds(?object $row): int
    {
        $blockedAt = $row->temp_block_time ?? null;

        if ($blockedAt === null) {
            return 0;
        }

        $length = $this->blockLength();
        $elapsed = (int) abs(Carbon::parse($blockedAt)->diffInSeconds());

        // `<=` and not `<`, as the verify paths compare it: a row at exactly the length is still blocked there, and must be here.
        return $elapsed <= $length ? max(0, $length - $elapsed) : 0;
    }

    /** Whether a row is blocked right now. */
    public function isBlocked(?object $row): bool
    {
        return $this->remainingSeconds($row) > 0;
    }

    /** The operator's `temporary_block_time`, which the settings label minutes and every comparison reads as seconds. */
    public function blockLength(): int
    {
        $configured = getWebConfig(name: 'temporary_block_time');

        return (int) ($configured === null || $configured === '' ? self::DEFAULT_LENGTH : $configured);
    }
}
