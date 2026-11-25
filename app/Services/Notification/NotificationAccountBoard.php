<?php

namespace App\Services\Notification;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontResolver;

/**
 * What the admin screen of the notification accounts is allowed to see (I1-01).
 *
 * One row per market the signed-in employee may configure, and for each row what every channel's account holds -- which is
 * {@see NotificationProfile::visible()} and never the account object itself: the settings, and the NAMES of the secrets, so an operator can
 * see that a password is set without being shown it. Nothing on this board can be dumped, logged or echoed into a form field to leak a
 * credential, because no credential ever reaches it.
 *
 * The markets are narrowed by {@see StaffCountryScope}: an employee restricted to Kuwait is shown Kuwait and offered Kuwait, so the screen
 * cannot be used to read or write another company's mail host. The default market is shown as the SHARED account rather than as a market of
 * its own, which is what it is -- `NotificationProfiles` maps the default country to the platform-wide row -- and saying so on the screen is
 * the only way an operator can tell that editing it changes every market that has no account.
 */
final class NotificationAccountBoard
{
    public function __construct(
        private readonly NotificationProfiles $profiles,
        private readonly StaffCountryScope $staff,
    ) {
    }

    /** Whether a market can have an account of its own at all: the switch is on and the migration has run. */
    public function accountsPerMarketAreOn(): bool
    {
        return $this->profiles->isEnabled();
    }

    /**
     * The markets this employee may configure, the default market first because it is the shared account every other market falls back to.
     *
     * The restriction is resolved with {@see StaffCountryScope::countriesOf()} rather than with `narrow()`, and the difference matters: `narrow()`
     * answers from what a middleware BOUND to the request, and nothing binds this route -- `narrowLayout()` binds it when the shared admin layout
     * renders, which is after this screen has already decided what to show. A screen that asked `narrow()` here would offer every market to a
     * restricted employee and look as though the scope were off.
     *
     * @return list<string>
     */
    public function markets(): array
    {
        $resolver = StorefrontResolver::fromConfig();
        $default = $resolver->defaultCountry();
        $configured = $resolver->isEnabled() ? $resolver->configuredCountries() : [$default];
        $ordered = [$default, ...array_values(array_filter($configured, static fn (string $country): bool => $country !== $default))];
        $allowed = $this->staff->countriesOf(admin: auth('admin')->user());

        return $allowed === null ? $ordered : array_values(array_intersect($ordered, $allowed));
    }

    /** Whether the employee may configure this market at all. The screen asks before it offers; the writer asks again before it writes. */
    public function mayConfigure(string $country): bool
    {
        return in_array(strtoupper(trim($country)), $this->markets(), true);
    }

    /**
     * One market's row: whether it is the shared account, and what each channel holds.
     *
     * @return array{country: string, is_shared: bool, channels: array<string, array{configured: bool, active: bool, settings: array<string, mixed>, credentials_present: list<string>}>}
     */
    public function row(string $country): array
    {
        $market = strtoupper(trim($country));
        $isShared = $market === StorefrontResolver::fromConfig()->defaultCountry();
        $channels = [];

        foreach (NotificationProfiles::CHANNELS as $channel) {
            $channels[$channel] = $this->channel(channel: $channel, market: $market, isShared: $isShared);
        }

        return ['country' => $market, 'is_shared' => $isShared, 'channels' => $channels];
    }

    /**
     * @return array{configured: bool, active: bool, settings: array<string, mixed>, credentials_present: list<string>}
     */
    private function channel(string $channel, string $market, bool $isShared): array
    {
        $stored = $this->profiles->stored(channel: $channel, country: $isShared ? NotificationProfiles::PLATFORM_WIDE : $market);

        if ($stored === null) {
            return ['configured' => false, 'active' => false, 'settings' => [], 'credentials_present' => []];
        }

        $visible = $stored->visible();

        return [
            'configured' => true,
            'active' => $visible['active'],
            'settings' => $visible['settings'],
            'credentials_present' => $visible['credentials_present'],
        ];
    }
}
