<?php

namespace App\Services\Notification;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Every attempt to send one message, stored (I1-09).
 *
 * Nothing was stored. `PushNotificationTrait::sendNotificationToHttp()` catches `Exception` and returns false, and
 * `EmailTemplateTrait::writeAndSendMail()` wraps the send in `try { ... } catch (Exception) {}` with an EMPTY body -- so a market whose
 * mail host had the wrong password, or whose Firebase service account had been revoked, sent nothing and said nothing, to anybody, ever.
 * There was no screen, no log line and no counter: the only way to find out was for a customer to say they never got the message.
 *
 * What is stored is deliberately NOT the message:
 *
 *  - the channel, the market, the template key, the recipient's KIND, the status, which attempt it was and the provider's error;
 *  - a SHA-256 digest of the address instead of the address, so the same recipient's attempts can be counted and read together without
 *    the table becoming a second copy of the customer list. A notification log is the one table an operator reads most and the one least
 *    likely to be thought of as personal data, and a raw phone number in it is a phone number in every backup of it;
 *  - the provider's error with addresses taken out of it, because an SMTP or FCM error quotes the address it failed on, and storing the
 *    error verbatim would put back exactly what the digest is there to keep out.
 *
 * It writes nothing until its migration has run, and a failure to write never fails a send: a log that can break the thing it observes
 * is worse than no log.
 */
#[Singleton]
final class NotificationAttemptLog
{
    public const TABLE = 'notification_send_attempts';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_MAIL = 'mail';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $tableAnswer = null;

    /** Whether attempts are stored at all: the table is there. */
    public function isRecording(): bool
    {
        return $this->tableReady();
    }

    /**
     * One attempt. `$attempt` counts from 1, and `$isFinal` says this was the last one the budget allowed -- which is what an operator
     * filters by, because a first failure that the second attempt fixed is noise and a final one is an undelivered message.
     */
    public function record(
        string $channel,
        string $templateKey,
        ?string $recipientType,
        ?string $sentTo,
        string $status,
        int $attempt = 1,
        bool $isFinal = true,
        ?string $error = null,
    ): void {
        if (!$this->tableReady()) {
            return;
        }

        try {
            DB::table(self::TABLE)->insert([
                'channel' => substr($channel, 0, 20),
                'storefront_country' => $this->market(),
                'template_key' => substr($templateKey, 0, 191),
                'recipient_type' => $recipientType === null ? null : substr($recipientType, 0, 40),
                'recipient_digest' => self::digestOf($sentTo),
                'status' => $status === self::STATUS_SENT ? self::STATUS_SENT : self::STATUS_FAILED,
                'attempt' => max(1, $attempt),
                'is_final' => $isFinal,
                'provider_error' => $error === null ? null : self::withoutAddresses($error),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // A log that can break the send it observes is worse than no log.
        }
    }

    /**
     * The final failures of a market, newest first, for that market's admin to read (I1-09).
     *
     * @return list<array<string, mixed>>
     */
    public function finalFailuresOf(?string $country, int $limit = 50): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $market = $country === null ? null : strtoupper(trim($country));

        return DB::table(self::TABLE)
            ->where('status', self::STATUS_FAILED)
            ->where('is_final', true)
            ->when($market !== null, fn ($query) => $query->where('storefront_country', $market))
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** How many messages a market failed to deliver, by channel, for the figure beside the list. @return array<string, int> */
    public function finalFailureCountsByChannel(?string $country): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $market = $country === null ? null : strtoupper(trim($country));

        return DB::table(self::TABLE)
            ->where('status', self::STATUS_FAILED)
            ->where('is_final', true)
            ->when($market !== null, fn ($query) => $query->where('storefront_country', $market))
            ->selectRaw('channel, COUNT(*) AS total')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /** A recipient as the log holds them: a digest, never the address. Null for a send with no single recipient, such as a topic push. */
    public static function digestOf(?string $sentTo): ?string
    {
        $address = is_string($sentTo) ? strtolower(trim($sentTo)) : '';

        return $address === '' ? null : hash('sha256', $address);
    }

    /**
     * The provider's error with the things the digest exists to keep out taken out of it: e-mail addresses, and runs of digits long
     * enough to be a telephone number or a device token.
     */
    public static function withoutAddresses(string $error): string
    {
        $scrubbed = (string) preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[address]', $error);
        $scrubbed = (string) preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[number]', $scrubbed);

        return substr(trim($scrubbed), 0, 2000);
    }

    /** The market a message belongs to, which is the one in force: the order's own for a message about an order, the request's otherwise. */
    private function market(): ?string
    {
        $resolver = StorefrontResolver::fromConfig();

        if (!$resolver->isEnabled()) {
            return null;
        }

        $country = strtoupper(trim(StorefrontContext::current()->country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    private function tableReady(): bool
    {
        $answer = $this->tableAnswer;
        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasTable(self::TABLE);
        $this->tableAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }

    /** Forgets what was learnt about the table, for a test that builds it. */
    public function forgetTheSchema(): void
    {
        $this->tableAnswer = null;
    }
}
