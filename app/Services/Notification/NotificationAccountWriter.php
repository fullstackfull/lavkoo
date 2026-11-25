<?php

namespace App\Services\Notification;

use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Writes one market's mail account from the admin screen (I1-01).
 *
 * Until this existed the accounts were written straight into `notification_channel_profiles` by hand, which is fine for the engine and no use
 * to an operator. Three rules shape it, and each of them is the reason a screen for credentials is its own slice of work:
 *
 *  - **A secret is never echoed back into the form, so a blank field cannot mean "clear it".** The screen is shown the NAMES of the secrets
 *    an account holds and no value ({@see NotificationProfile::visible()}), so an operator who edits the host of an account and saves it
 *    sends an empty password field -- not because they want the password gone, but because they were never shown it. An empty field
 *    therefore KEEPS what is stored, and clearing a secret is its own explicit instruction ({@see $forget}). The alternative, echoing the
 *    password into a `value` attribute, puts the SMTP password of a company in the HTML of a page, in the browser's cache and in any
 *    screenshot of it.
 *  - **The employee's country scope decides which markets they may write.** It is asked here and not only on the screen, because a screen
 *    that offers five markets and a writer that accepts six is a writer that accepts six.
 *  - **The settings and the secrets go to different columns.** `settings` is plain JSON that a screen may show; `credentials` is encrypted
 *    with the application key, the same split `integration_settings` uses for the carriers.
 */
final class NotificationAccountWriter
{
    /** The settings of a mail account an operator fills in: what a screen may show. */
    public const MAIL_SETTINGS = ['driver', 'host', 'port', 'encryption', 'from_address'];

    /** The secrets of a mail account: what no screen, response or log may show. */
    public const MAIL_CREDENTIALS = ['username', 'password'];

    public function __construct(
        private readonly NotificationProfiles $profiles,
        private readonly NotificationAccountBoard $board,
    ) {
    }

    /**
     * Saves the mail account of one market, keeping every secret the form did not carry.
     *
     * @param array<string, mixed> $settings the fields of {@see MAIL_SETTINGS}; a blank one is not stored at all
     * @param array<string, string> $secrets the fields of {@see MAIL_CREDENTIALS} the operator actually typed; a blank one keeps what is stored
     * @param list<string> $forget the secrets the operator asked to clear, by name
     *
     * @throws RuntimeException when the account cannot be written at all, in the words the screen shows
     */
    public function saveMailAccount(string $country, array $settings, array $secrets, array $forget = [], bool $active = true, ?string $label = null): void
    {
        $market = $this->marketOf($country);
        $kept = $this->secretsOf(channel: NotificationProfiles::MAIL, market: $market);

        foreach (self::MAIL_CREDENTIALS as $name) {
            $typed = trim((string) ($secrets[$name] ?? ''));

            if ($typed !== '') {
                $kept[$name] = $typed;
            } elseif (in_array($name, $forget, true)) {
                unset($kept[$name]);
            }
        }

        $this->write(
            channel: NotificationProfiles::MAIL,
            market: $market,
            settings: $this->cleaned(fields: self::MAIL_SETTINGS, given: $settings),
            credentials: $kept,
            active: $active,
            label: $label,
        );
    }

    /**
     * The market a row is written for, refused in the words the screen shows when the employee may not configure it.
     *
     * The default market is the SHARED row, because that is the row `NotificationProfiles` reads for it: a platform whose default market had
     * a row of its own would have two accounts for one market and no rule for which wins.
     */
    private function marketOf(string $country): string
    {
        $market = strtoupper(trim($country));

        if (!$this->profiles->isEnabled()) {
            throw new RuntimeException(translate('accounts_per_market_are_switched_off'));
        }

        if (!$this->board->mayConfigure(country: $market)) {
            throw new RuntimeException(translate('you_may_not_configure_this_market'));
        }

        return $market === StorefrontResolver::fromConfig()->defaultCountry() ? NotificationProfiles::PLATFORM_WIDE : $market;
    }

    /**
     * The secrets already stored for this account, so that a field the form did not carry keeps its value.
     *
     * @return array<string, string>
     */
    private function secretsOf(string $channel, string $market): array
    {
        $stored = $this->profiles->stored(channel: $channel, country: $market);
        $kept = [];

        foreach ($stored === null ? [] : $stored->visible()['credentials_present'] as $name) {
            $value = $stored->credential($name);

            if (is_string($value) || is_int($value)) {
                $kept[$name] = (string) $value;
            }
        }

        return $kept;
    }

    /**
     * Only the fields the operator filled in. A blank host is not stored as an empty string, because an account whose host is `''` is an
     * account {@see MailAccount} would ignore while the screen showed it as configured.
     *
     * @param list<string> $fields
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     */
    private function cleaned(array $fields, array $given): array
    {
        $settings = [];

        foreach ($fields as $field) {
            $value = $given[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($value !== null && $value !== '') {
                $settings[$field] = $value;
            }
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, string> $credentials
     */
    private function write(string $channel, string $market, array $settings, array $credentials, bool $active, ?string $label): void
    {
        try {
            $settingsJson = json_encode($settings, JSON_THROW_ON_ERROR);
            $credentialsJson = $credentials === [] ? null : Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new RuntimeException(translate('this_account_could_not_be_saved'));
        }

        $row = DB::table(NotificationProfiles::TABLE)->where(['channel' => $channel, 'country' => $market]);
        $account = ['label' => $label, 'settings' => $settingsJson, 'credentials' => $credentialsJson, 'active' => $active, 'updated_at' => now()];

        // Written as an update or an insert rather than as `updateOrInsert`, which would put `created_at` back to now on every save and
        // lose the day the account was first configured -- the one date an operator asking "since when has this market had its own host"
        // has to read.
        $row->exists() ? $row->update($account) : $row->insert([...$account, 'channel' => $channel, 'country' => $market, 'created_at' => now()]);

        $this->profiles->flush();
    }
}
