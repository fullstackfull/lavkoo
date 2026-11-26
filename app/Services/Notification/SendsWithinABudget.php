<?php

namespace App\Services\Notification;

use Closure;
use Illuminate\Container\Attributes\Singleton;
use Throwable;

/**
 * Sends one message within a stated budget of attempts, and stores every one of them (I1-09).
 *
 * Both senders of this platform swallowed their failure where it happened: `sendNotificationToHttp()` catches and returns false, and
 * `writeAndSendMail()` has an EMPTY catch block. So a transient failure -- an SMTP connection reset, an FCM 503, a token minted a second
 * before it expired -- lost the message, and the platform's one retry mechanism (`$tries` on the mail listeners) could not help, because
 * the exception never left the trait for the queue to see.
 *
 * The budget is a number of attempts, read from `gcc.notifications.attempts`, and it SHIPS AT 1 -- one attempt, no retry, which is exactly
 * what the platform does today. Raising it is the operator's decision, and the first test asserts that at 1 nothing changed: one attempt,
 * one row, and the same answer handed back to the same caller.
 *
 * What counts as a failure is the CALLER's judgement, not an exception: `sendNotificationToHttp()` returns a response object that may carry
 * an HTTP 401 from FCM, which is a failed send with no exception thrown. So the caller hands over a predicate, and anything it calls a
 * failure is retried and logged as one.
 *
 * **And one message is sent once (I1-09).** Before the first attempt the message's identity ({@see MessageIdentity}) is CLAIMED in
 * `notification_send_attempts` through its unique key, so a replay of the same business event -- a queue worker running the job twice, a
 * webhook arriving twice, an operator pressing the button again -- finds the claim taken and sends nothing. The claim is an insert and
 * not a read, because two workers pass a read together; the engine is the arbiter. It ships OFF
 * (`gcc.notifications.idempotency`), and off it does not even ask: a message with no identity, an installation whose migration has not
 * run and a platform with the switch off all take exactly the path they took before.
 *
 * Between attempts it does NOT sleep by default. A notification is often sent inside a web request, and a backoff that holds a request open
 * is a worse failure than the one it is recovering from; `gcc.notifications.retry_delay_ms` is there for a worker that wants one.
 */
#[Singleton]
final class SendsWithinABudget
{
    public function __construct(private readonly NotificationAttemptLog $log)
    {
    }

    /**
     * What a caller is handed for a send that was dropped as a replay.
     *
     * It is `true` and not `null`, and the difference is a TypeError: `sendPushNotificationToDevice()` is declared `string|bool` and
     * `sendPushNotificationToTopic()` `bool|string`, so a null travelling up through them is a fatal error on a notification path -- the
     * seventh time in this programme that a new call site inside an old one has met a type it did not expect. `true` is also the honest
     * answer: the message HAS been delivered, by the send this one is a replay of, and a caller that checks for falsity is asking
     * whether the customer was told.
     */
    private const ALREADY_DELIVERED = true;

    /** How many attempts one message gets. One is today's platform: send once, and whatever happens happens. */
    public function budget(): int
    {
        $attempts = (int) config('gcc.notifications.attempts', 1);

        return max(1, min(5, $attempts));
    }

    /**
     * Runs the send up to the budget, stopping at the first attempt the predicate calls a success, and stores every attempt.
     *
     * @template TResult
     * @param Closure():TResult $send
     * @param Closure(mixed):bool $succeeded what this channel counts as a delivered message
     * @param string|null $about the business event this message is about ({@see MessageIdentity}), or null for a message that is a
     *                           replay of nothing and is always sent
     * @return TResult the result of the last attempt, which is what the caller would have got with no budget at all -- or
     *                 {@see self::ALREADY_DELIVERED} for a send that was dropped as a replay
     */
    public function send(
        string $channel,
        string $templateKey,
        ?string $recipientType,
        ?string $sentTo,
        Closure $send,
        Closure $succeeded,
        ?string $about = null,
    ): mixed {
        // The identity is not even DERIVED while the mechanism is dormant, and that is not a saving. A key handed to `record()` with the
        // switch off would be WRITTEN, and the second attempt of a replay would then be refused by the unique index and lost -- the push
        // would still leave the server and the row recording it would not. The first test of
        // `OneBusinessEventSendsOneMessageTest` found exactly that.
        $key = $this->log->isIdentifying()
            ? MessageIdentity::of(
                channel: $channel,
                templateKey: $templateKey,
                recipientType: $recipientType,
                sentTo: $sentTo,
                about: $about,
            )
            : null;

        $claim = $key === null
            ? NotificationAttemptClaim::notRecorded()
            : $this->log->claim(
                key: $key,
                channel: $channel,
                templateKey: $templateKey,
                recipientType: $recipientType,
                sentTo: $sentTo,
            );

        if ($claim->alreadySent) {
            return self::ALREADY_DELIVERED;
        }

        $budget = $this->budget();
        $result = null;
        $claimed = $claim->id;

        for ($attempt = 1; $attempt <= $budget; $attempt++) {
            $error = null;

            try {
                $result = $send();
                $isSuccess = $succeeded($result);
            } catch (Throwable $exception) {
                $result = null;
                $isSuccess = false;
                $error = $exception->getMessage();
            }

            $isFinal = $isSuccess || $attempt === $budget;
            $status = $isSuccess ? NotificationAttemptLog::STATUS_SENT : NotificationAttemptLog::STATUS_FAILED;
            $why = $isSuccess ? null : ($error ?? self::whyItFailed($result));

            if ($claimed !== null) {
                // The claim row is this attempt: it was written before the send, as `pending`, and this is the provider's answer to it.
                $this->log->settle(id: $claimed, status: $status, isFinal: $isFinal, error: $why);
                $claimed = null;
            } else {
                $this->log->record(
                    channel: $channel,
                    templateKey: $templateKey,
                    recipientType: $recipientType,
                    sentTo: $sentTo,
                    status: $status,
                    // A claim may have taken an attempt number above 1 -- a replay of a message whose every earlier attempt failed
                    // continues its series rather than starting again -- and the retries of this budget run follow on from it.
                    attempt: $claim->attempt === null ? $attempt : $claim->attempt + $attempt - 1,
                    isFinal: $isFinal,
                    error: $why,
                    idempotencyKey: $key,
                );
            }

            if ($isSuccess) {
                return $result;
            }

            $this->waitBeforeTheNextAttempt($attempt, $budget);
        }

        return $result;
    }

    /** What a response that is not an exception says about why it failed, where it can say anything at all. */
    private static function whyItFailed(mixed $result): ?string
    {
        if (is_object($result) && method_exists($result, 'status') && method_exists($result, 'body')) {
            return 'HTTP ' . $result->status() . ': ' . substr((string) $result->body(), 0, 500);
        }

        return $result === false ? 'the send returned false' : null;
    }

    private function waitBeforeTheNextAttempt(int $attempt, int $budget): void
    {
        $delay = (int) config('gcc.notifications.retry_delay_ms', 0);

        if ($attempt < $budget && $delay > 0) {
            usleep(min(2000, $delay) * 1000 * $attempt);
        }
    }
}
