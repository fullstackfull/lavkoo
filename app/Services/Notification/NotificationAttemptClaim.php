<?php

namespace App\Services\Notification;

/**
 * The answer to "may I send this message?" -- the three states a claim on one notification's identity can be in (I1-09).
 *
 * There are three and not two, and collapsing them is how such a gate ends up refusing a send it should have made:
 *
 *  - **granted**: this process wrote the claim row and nothing else has sent this message. The row is already in
 *    `notification_send_attempts` as `pending`, carrying the attempt number it took, and the sender settles it with what the provider
 *    answered;
 *  - **alreadySent**: a row with this identity is there, delivered or in flight, so this send is a replay and is dropped;
 *  - **notRecorded**: nothing is being claimed -- the switch is off, the table or the column is not there, or the message names no
 *    subject. The send proceeds exactly as it does today and the attempt is stored the way it has always been. **A log that cannot
 *    write must never stop a send**, so every failure that is not a genuine duplicate lands here.
 */
final class NotificationAttemptClaim
{
    private function __construct(
        public readonly ?int $id,
        public readonly ?int $attempt,
        public readonly bool $alreadySent,
    ) {
    }

    /** This process holds the claim: `$id` is the pending row to settle and `$attempt` the number it took. */
    public static function granted(int $id, int $attempt): self
    {
        return new self(id: $id, attempt: $attempt, alreadySent: false);
    }

    /** Somebody has already sent this message, or is sending it now. */
    public static function alreadySent(): self
    {
        return new self(id: null, attempt: null, alreadySent: true);
    }

    /** Nothing is claimed, and the send goes ahead as it always has. */
    public static function notRecorded(): self
    {
        return new self(id: null, attempt: null, alreadySent: false);
    }
}
