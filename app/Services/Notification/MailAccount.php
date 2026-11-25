<?php

namespace App\Services\Notification;

use Closure;
use Illuminate\Support\Facades\Mail;

/**
 * The mail account one message leaves through (I1-01).
 *
 * `MailConfigServiceProvider::boot()` replaces the mail configuration once, at boot, from the one `mail_config` setting. A queue worker boots
 * once and then sends one market's messages after another, so the account was never the message's -- it was the worker's. On a platform where
 * each market is a separate company (GCC-13) that means a Kuwaiti order's e-mail left through the Saudi company's host and from a Saudi
 * address, which a recipient's mail server is entitled to reject and a reader is entitled to distrust.
 *
 * The account of the market in force is put in force for the duration of one send and taken out again afterwards, failure included, for the
 * same reason the storefront and the language are: the next message in the same worker is a different market's.
 *
 * **It does not touch the sender's NAME.** `mail.from.name` already has an owner -- the company that sold the order, which
 * {@see \App\Services\Storefront\OrderNotificationContext::asTheCompanyThatSold()} sets from the order's frozen legal entity (A1-17) -- and
 * two writers of one field is how one of them silently loses. The account decides where a message leaves FROM: the host, the credentials and
 * the address. A marketing name per market is F-13 and has a record of its own to come.
 *
 * The legacy configuration shape is deliberate. `config/mail.php` of this installation declares `driver`, `host`, `port`, `username`,
 * `password` and `encryption` at the TOP level rather than under `mailers`, and Laravel's mail manager reads the whole `mail` array as the
 * mailer's configuration when `mail.driver` is set. Writing the same keys the boot provider writes is therefore what takes effect; writing
 * `mail.mailers.smtp.*` instead would be read by nothing.
 */
final class MailAccount
{
    /** The configuration keys an account owns. `from.name` is deliberately absent: see the class docblock. */
    private const KEYS = ['mail.driver', 'mail.host', 'mail.port', 'mail.username', 'mail.password', 'mail.encryption', 'mail.from.address'];

    public function __construct(private readonly NotificationProfiles $profiles)
    {
    }

    /**
     * Runs the callback with the mail account of the market in force, and puts back the account that was in force before.
     *
     * A market with no account of its own, a platform with the switch off and an account that names no host all run the callback untouched:
     * a half-written account is not a reason to send through the wrong host, and it is not a reason to fail to send either.
     */
    public function forThisMessage(Closure $callback): mixed
    {
        $account = $this->settingsOf($this->profiles->for(channel: NotificationProfiles::MAIL));

        if ($account === []) {
            return $callback();
        }

        $previous = [];
        foreach (self::KEYS as $key) {
            $previous[$key] = config($key);
        }

        config($account);
        // The manager keeps a built mailer per name, so one built from the previous account would go on being used for this message.
        Mail::purge(config('mail.driver'));

        try {
            return $callback();
        } finally {
            config($previous);
            Mail::purge(config('mail.driver'));
        }
    }

    /**
     * What this account changes, as configuration keys. Only the values the account names: an account that gives a host and credentials but
     * no from address keeps the platform's address, which is the address its domain is most likely to accept.
     *
     * @return array<string, mixed>
     */
    private function settingsOf(?NotificationProfile $profile): array
    {
        if ($profile === null || !is_string($profile->setting('host')) || trim((string) $profile->setting('host')) === '') {
            return [];
        }

        $account = [
            'mail.driver' => (string) ($profile->setting('driver') ?: 'smtp'),
            'mail.host' => trim((string) $profile->setting('host')),
            'mail.port' => $profile->setting('port') ?? config('mail.port'),
            'mail.encryption' => $profile->setting('encryption') ?? config('mail.encryption'),
            'mail.username' => $profile->credential('username') ?? config('mail.username'),
            'mail.password' => $profile->credential('password') ?? config('mail.password'),
        ];

        $from = $profile->setting('from_address');

        if (is_string($from) && trim($from) !== '') {
            $account['mail.from.address'] = trim($from);
        }

        return $account;
    }
}
