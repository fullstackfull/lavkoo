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
 * Between attempts it does NOT sleep by default. A notification is often sent inside a web request, and a backoff that holds a request open
 * is a worse failure than the one it is recovering from; `gcc.notifications.retry_delay_ms` is there for a worker that wants one.
 */
#[Singleton]
final class SendsWithinABudget
{
    public function __construct(private readonly NotificationAttemptLog $log)
    {
    }

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
     * @return TResult the result of the last attempt, which is what the caller would have got with no budget at all
     */
    public function send(
        string $channel,
        string $templateKey,
        ?string $recipientType,
        ?string $sentTo,
        Closure $send,
        Closure $succeeded,
    ): mixed {
        $budget = $this->budget();
        $result = null;

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

            $this->log->record(
                channel: $channel,
                templateKey: $templateKey,
                recipientType: $recipientType,
                sentTo: $sentTo,
                status: $isSuccess ? NotificationAttemptLog::STATUS_SENT : NotificationAttemptLog::STATUS_FAILED,
                attempt: $attempt,
                isFinal: $isFinal,
                error: $isSuccess ? null : ($error ?? self::whyItFailed($result)),
            );

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
