<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use Carbon\CarbonInterface;

/**
 * Decides whether an incoming provider status may overwrite a fulfillment's current one.
 *
 * Pure: no database, no clock. That is deliberate — this is the rule that stops a stale or replayed
 * event from undoing a confirmed shipment state, and it must be testable exhaustively.
 *
 *  - A terminal shipment (delivered, cancelled, returned) is final, with one real-world exception: a
 *    delivered parcel can later be returned by the recipient.
 *  - When the event carries the provider's own timestamp, that timestamp is the authority: an event
 *    older than the newest one already applied is stale, whatever its status.
 *  - When it carries none, the only protection is forward progress: it may not move the shipment back.
 *  - A status that only OUR dispatcher produces (pending, held, dispatching…) never arrives from a provider.
 */
final class FulfillmentStateMachine
{
    public const APPLY = 'apply';
    public const SAME = 'ignored_duplicate';
    public const STALE = 'ignored_stale';
    public const TERMINAL = 'ignored_terminal';

    public function decide(
        FulfillmentStatus $current,
        FulfillmentStatus $incoming,
        ?CarbonInterface $lastEventAt,
        ?CarbonInterface $occurredAt,
    ): string {
        if ($incoming->isPreSubmission()) {
            return self::STALE;
        }

        if ($current === $incoming) {
            return self::SAME;
        }

        if ($current->isTerminal()) {
            return $current === FulfillmentStatus::Delivered && $incoming === FulfillmentStatus::Returned
                && !$this->isOlder($occurredAt, $lastEventAt)
                ? self::APPLY
                : self::TERMINAL;
        }

        if ($this->isOlder($occurredAt, $lastEventAt)) {
            return self::STALE;
        }

        $sameInstantOrUnknown = $occurredAt === null || $lastEventAt === null || $occurredAt->equalTo($lastEventAt);
        if ($sameInstantOrUnknown && $incoming->rank() < $current->rank() && !$current->isPreSubmission()) {
            return self::STALE;
        }

        return self::APPLY;
    }

    private function isOlder(?CarbonInterface $occurredAt, ?CarbonInterface $lastEventAt): bool
    {
        return $occurredAt !== null && $lastEventAt !== null && $occurredAt->lessThan($lastEventAt);
    }
}
