<?php

namespace App\Console\Commands;

use App\Services\Notification\NotificationProfile;
use App\Services\Notification\NotificationProfiles;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Command;

/**
 * Read-only: which account each market's messages would leave through, and what is missing (I1-01, I1-02).
 *
 * A market whose mail account names a host but no password, or whose WhatsApp account names no inbox, does not fail when it is configured --
 * it fails the first time a customer of that market resets their password, which is after the market has opened. This is the check that is
 * meant to be run before it opens.
 *
 * It prints no secret. For each account it says which credentials are PRESENT, by name, never a value, which is the same answer the carrier
 * settings screen gives an operator.
 */
class NotificationReadinessCommand extends Command
{
    protected $signature = 'gcc:notification-readiness';

    protected $description = 'Read-only report before a market opens: the mail, SMS, WhatsApp and push account each configured storefront would send through, whether it is its own or the shared one, and which required values are missing. Exits 1 when an account a market would use is incomplete. Prints no credential values';

    /** What an account of each channel cannot send without. A value that is missing is reported; a value that is secret is reported by name only. */
    private const REQUIRED = [
        NotificationProfiles::MAIL => ['settings' => ['host', 'from_address'], 'credentials' => []],
        NotificationProfiles::SMS => ['settings' => ['gateways'], 'credentials' => []],
        NotificationProfiles::WHATSAPP => ['settings' => ['base_url', 'account_id', 'inbox_id'], 'credentials' => ['api_access_token']],
        NotificationProfiles::PUSH => ['settings' => [], 'credentials' => ['service_account']],
    ];

    public function handle(NotificationProfiles $profiles): int
    {
        $this->line('Notification accounts per market: ' . ($profiles->isEnabled() ? 'on' : 'off') . ' (GCC_NOTIFICATION_PROFILES, and the notification_channel_profiles migration must have run).');

        if (!$profiles->isEnabled()) {
            $this->line('  Every message leaves through the one account this installation has, exactly as it does today. Nothing below is read.');

            return self::SUCCESS;
        }

        $resolver = StorefrontResolver::fromConfig();
        $incomplete = false;

        foreach (self::REQUIRED as $channel => $required) {
            $shared = $profiles->hasSharedAccount($channel) ? 'a shared account' : 'no shared account (the platform settings stand)';
            $this->line(sprintf('  %-9s %s; markets with their own: %s', $channel, $shared, implode(', ', $profiles->marketsWithTheirOwnAccount($channel)) ?: 'none'));
        }

        foreach ($resolver->configuredCountries() as $country) {
            foreach (self::REQUIRED as $channel => $required) {
                $incomplete = $this->accountLine(profiles: $profiles, country: $country, channel: $channel, required: $required) || $incomplete;
            }
        }

        $this->line($incomplete
            ? 'INCOMPLETE: an account a market would send through is missing a value it cannot send without. A market may open with it missing, and the message fails at the moment a customer needs it.'
            : 'Every account a market would send through names what it needs. This says nothing about whether the credentials WORK: send a test message to find that out.');
        $this->line('Not covered by this report: whether a credential is accepted by its provider, whether a WhatsApp template is approved for the market\'s sender number, and whether a market\'s app build uses that market\'s Firebase project (a push credential reaches only the devices of its own project).');

        return $incomplete ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One market and one channel: whose account it would use and what that account is missing.
     *
     * @param array{settings: list<string>, credentials: list<string>} $required
     */
    private function accountLine(NotificationProfiles $profiles, string $country, string $channel, array $required): bool
    {
        $account = $profiles->for(channel: $channel, country: $country);

        if ($account === null) {
            $this->line(sprintf('  %-4s %-9s the platform settings, as today.', $country, $channel));

            return false;
        }

        $missing = $this->missingFrom(account: $account, required: $required);
        $whose = $account->isShared() ? 'the shared account' : 'its own account';
        $present = $account->visible()['credentials_present'];

        $this->line(sprintf(
            '  %-4s %-9s %s%s; credentials present: %s%s',
            $country,
            $channel,
            $whose,
            $account->label === null ? '' : ' (' . $account->label . ')',
            implode(', ', $present) ?: 'none',
            $missing === [] ? '' : '  MISSING: ' . implode(', ', $missing),
        ));

        return $missing !== [];
    }

    /**
     * @param array{settings: list<string>, credentials: list<string>} $required
     * @return list<string>
     */
    private function missingFrom(NotificationProfile $account, array $required): array
    {
        $missing = [];

        foreach ($required['settings'] as $key) {
            if ($this->isEmpty($account->setting($key))) {
                $missing[] = $key;
            }
        }

        foreach ($required['credentials'] as $key) {
            if ($this->isEmpty($account->credential($key))) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === 0;
    }
}
