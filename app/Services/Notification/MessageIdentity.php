<?php

namespace App\Services\Notification;

/**
 * What makes one notification the same message as another, so a replay of one business event sends nothing twice (I1-09).
 *
 * The identity is derived from three things, and a message missing the first of them has NO identity at all:
 *
 *  - **the subject**: the business event the message is about -- the order and which status change, the refund, the auction. A message
 *    that names no subject (a marketing blast, a one-time code, a newsletter) is not a replay of anything and is never suppressed;
 *  - **the recipient**: the channel, the kind of recipient and the address, as a digest -- so one status change tells the buyer, the
 *    shop and the rider, each exactly once, rather than one of them;
 *  - **the message**: the template key, which is the operator's own name for the text being sent.
 *
 * **The rendered text is deliberately NOT part of it, and that is the opposite of the obvious design.** Hashing the body would be more
 * precise, and it would also switch the whole mechanism off without saying so: `PushNotificationTrait::textVariableDataFormat()` renders
 * `{time}` as `now()->diffForHumans()`, so a template holding that placeholder produces a different body on every send and every replay
 * would be a new message. A key that is defeated by one placeholder in one operator-editable string is worse than no key, because it
 * reads as working.
 *
 * **What that costs, stated rather than left to be discovered.** The identity of a status notification is (order, recipient, status), so
 * an order put BACK into a status it has already been notified about does not notify again -- an admin correcting a mistake by setting
 * `pending` a second time sends the customer nothing. That is pinned by a test rather than argued about, and it is why the switch ships
 * off: an operator turning it on is choosing at-most-once per status over a possible duplicate.
 */
final class MessageIdentity
{
    /** The payload fields that name what a push is ABOUT. Order matters only in that it is stable. */
    private const SUBJECT_FIELDS = ['order_id', 'refund_id', 'auction_id', 'order_details_id'];

    /**
     * The identity of one message, or null when it names no subject and therefore has none.
     *
     * @param string|null $about the business event, as {@see self::subjectOf()} spells it
     */
    public static function of(
        string $channel,
        string $templateKey,
        ?string $recipientType,
        ?string $sentTo,
        ?string $about,
    ): ?string {
        $subject = trim((string) $about);

        if ($subject === '') {
            return null;
        }

        return hash('sha256', implode('|', [
            $channel,
            $templateKey,
            (string) $recipientType,
            (string) NotificationAttemptLog::digestOf($sentTo),
            $subject,
        ]));
    }

    /**
     * The subject a push payload names, or null when it names none.
     *
     * `message_key` is part of it because it is WHICH change the message is about: an order's `pending` and its `confirmed` are two
     * events about one order, and a key that left it out would send the second and suppress the third.
     *
     * @param array<string, mixed> $payload the `message.data` of an FCM request
     */
    public static function subjectOf(array $payload): ?string
    {
        $named = [];

        foreach (self::SUBJECT_FIELDS as $field) {
            $value = trim((string) ($payload[$field] ?? ''));

            if ($value !== '') {
                $named[] = $field . ':' . $value;
            }
        }

        if ($named === []) {
            return null;
        }

        $named[] = 'key:' . trim((string) ($payload['message_key'] ?? ''));
        $named[] = 'type:' . trim((string) ($payload['type'] ?? ''));

        return implode('|', $named);
    }

    /** The subject of a message about an order, for a channel whose payload is not a push. */
    public static function aboutTheOrder(mixed $order, ?string $orderId, string $templateKey): ?string
    {
        $id = trim((string) ($orderId ?? ''));

        if ($id === '' && (is_array($order) || $order instanceof \ArrayAccess || is_object($order))) {
            $id = trim((string) (is_object($order) && !$order instanceof \ArrayAccess ? ($order->id ?? '') : ($order['id'] ?? '')));
        }

        return $id === '' ? null : 'order_id:' . $id . '|key:' . $templateKey;
    }
}
