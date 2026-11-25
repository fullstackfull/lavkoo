<?php

namespace App\Services\Notification;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * Which account one market's messages leave through (I1-01, I1-02).
 *
 * One mail host, one SMS gateway chain, one WhatsApp inbox and one push credential served the whole installation. Each market may now have
 * its own, and a market that has none uses the shared account -- which is every market until an operator writes a row, so the platform of
 * today is the platform with no rows in this table.
 *
 * **The market is the one IN FORCE, not the one in the request.** A message about an order runs inside the market the order was sold in
 * ({@see \App\Services\Storefront\OrderNotificationContext}), and every other message inside the market of the request that raised it
 * (carried to the worker by {@see \App\Jobs\Middleware\ServeInItsStorefront}). So asking for the storefront in force is asking the right
 * question in both cases, and no sender has to be given a country it would otherwise have to guess at -- which is what reading `request()`
 * in a queue worker amounts to.
 *
 * **A market's own account is the WHOLE answer for its channel.** Where a market has an active profile, the senders of that channel read it
 * and nothing else: a Kuwaiti one-time code may not go out through a gateway the Saudi account happens to have enabled, which is exactly the
 * leak this requirement exists to close. Falling back is a decision about a MARKET, not about a gateway.
 *
 * Off unless `GCC_NOTIFICATION_PROFILES` is on, and inert until the migration has run: with either missing, every reader answers null and
 * every sender does what it does today, byte for byte.
 */
#[Singleton]
final class NotificationProfiles
{
    public const MAIL = 'mail';

    public const SMS = 'sms';

    public const WHATSAPP = 'whatsapp';

    public const PUSH = 'push';

    /** @var list<string> the channels a message can leave through, which is what the readiness report walks */
    public const CHANNELS = [self::MAIL, self::SMS, self::WHATSAPP, self::PUSH];

    public const TABLE = 'notification_channel_profiles';

    /** The row that belongs to no country in particular: the shared account every installation has. */
    public const PLATFORM_WIDE = '';

    private ?bool $tableExists = null;

    /** @var array<string, NotificationProfile|false> the row chosen per channel and market, for the life of this process */
    private array $chosen = [];

    /** Whether a market can have an account of its own at all: the switch is on and the migration has run. */
    public function isEnabled(): bool
    {
        return (bool) config('gcc.notifications.profiles') && $this->tableExists();
    }

    /**
     * The account this channel's messages leave through for one market: the market's own active row, else the shared active row, else null,
     * which means the account the platform has always used.
     *
     * @param string|null $country the market, or null for the market in force
     */
    public function for(string $channel, ?string $country = null): ?NotificationProfile
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $market = $this->marketKey($country);
        $key = $channel . ':' . $market;

        return ($this->chosen[$key] ??= $this->read(channel: $channel, market: $market) ?? false) ?: null;
    }

    /**
     * Every market that has an account of its own for this channel. The shared row is not one of them: it is what a market without an
     * account falls back to, and calling it a market would make a one-account platform look as though it had six.
     *
     * @return list<string>
     */
    public function marketsWithTheirOwnAccount(string $channel): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return DB::table(self::TABLE)
            ->where('channel', $channel)
            ->where('country', '<>', self::PLATFORM_WIDE)
            ->orderBy('country')
            ->pluck('country')
            ->map(static fn (mixed $country): string => (string) $country)
            ->all();
    }

    /** Whether this channel has a shared account at all, which is what a market without one of its own would use. */
    public function hasSharedAccount(string $channel): bool
    {
        return $this->tableExists()
            && DB::table(self::TABLE)->where(['channel' => $channel, 'country' => self::PLATFORM_WIDE, 'active' => true])->exists();
    }

    /**
     * The row stored for exactly this channel and this market, switched off rows included and WITHOUT the fallback to the shared account.
     *
     * It is what the admin screen reads, and only the admin screen: a screen has to show what this market itself has, so that an operator
     * can tell an account of its own from the shared account it would otherwise fall back to, and can see a row they have switched off
     * rather than being shown an empty form over the top of it. Every sender goes on asking {@see for()}, which answers the account a
     * message actually leaves through.
     *
     * @param string $country the market, or {@see PLATFORM_WIDE} for the shared account
     */
    public function stored(string $channel, string $country): ?NotificationProfile
    {
        if (!$this->tableExists()) {
            return null;
        }

        $row = DB::table(self::TABLE)->where(['channel' => $channel, 'country' => $country])->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /** Forgets what was chosen, for a test that writes a row after a sender has already asked. */
    public function flush(): void
    {
        $this->chosen = [];
        $this->tableExists = null;
    }

    /** The market's own active row, else the shared active row. Compared byte for byte, as the column is declared. */
    private function read(string $channel, string $market): ?NotificationProfile
    {
        $row = $market === self::PLATFORM_WIDE
            ? null
            : DB::table(self::TABLE)->where(['channel' => $channel, 'country' => $market, 'active' => true])->first();

        $row ??= DB::table(self::TABLE)->where(['channel' => $channel, 'country' => self::PLATFORM_WIDE, 'active' => true])->first();

        return $row === null ? null : $this->hydrate($row);
    }

    private function hydrate(object $row): NotificationProfile
    {
        $channel = (string) $row->channel;

        return new NotificationProfile(
            channel: $channel,
            country: (string) $row->country,
            label: $row->label === null ? null : (string) $row->label,
            settings: $this->decode($row->settings, encrypted: false, channel: $channel),
            credentials: $this->decode($row->credentials, encrypted: true, channel: $channel),
            active: (bool) $row->active,
        );
    }

    /**
     * The market an account is read for: the one asked for, or the one in force. Upper case without spaces, and the shared row for anything
     * that is not a country code -- including the default market, which is the market the shared account already belongs to.
     */
    private function marketKey(?string $country): string
    {
        $resolver = StorefrontResolver::fromConfig();
        $code = strtoupper(trim((string) ($country ?? ($resolver->isEnabled() ? StorefrontContext::current()->country : ''))));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== $resolver->defaultCountry() ? $code : self::PLATFORM_WIDE;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $stored, bool $encrypted, string $channel): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $decoded = json_decode($encrypted ? Crypt::decryptString($stored) : $stored, true, 16, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (DecryptException|JsonException) {
            // A credential that cannot be read is treated as unset rather than as an error: a message must not fail to send because an
            // account was written with another application key. What it is NOT is a reason to fall through to another market's account --
            // the profile still answers, with nothing in it, so the sender refuses rather than sending through the wrong one.
            Log::warning('A notification account could not be read; treating it as unset.', ['channel' => $channel, 'encrypted' => $encrypted]);

            return [];
        }
    }

    private function tableExists(): bool
    {
        return $this->tableExists ??= Schema::hasTable(self::TABLE);
    }
}
