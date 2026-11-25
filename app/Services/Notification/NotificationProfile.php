<?php

namespace App\Services\Notification;

/**
 * One market's account for one channel (I1-01, I1-02): what the sender needs to authenticate and where to send.
 *
 * Two bags, and the split is the one `integration_settings` already uses: `settings` holds what an admin screen may show -- a host, a port,
 * a from address, an inbox id, which gateways this market has -- and `credentials` holds what it may not. Nothing but the sender that
 * authenticates with it ever reads `credentials`, and {@see visible()} is what every screen, response and log line is given instead, so a
 * secret cannot reach one of them by being on an object somebody dumped.
 */
final readonly class NotificationProfile
{
    /**
     * @param string $country the market this account belongs to, or '' for the shared account every installation has
     * @param array<string, mixed> $settings values a screen may show
     * @param array<string, mixed> $credentials values no screen, response or log may show
     */
    public function __construct(
        public string $channel,
        public string $country,
        public ?string $label,
        private array $settings,
        private array $credentials,
    ) {
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    /** Whether this account is the shared one rather than a market's own. */
    public function isShared(): bool
    {
        return $this->country === '';
    }

    /**
     * One gateway of this account, as the gateway's own reader expects it: its settings with its secrets merged over them.
     *
     * The shape is the gateway's, not this class's -- `SMSModule` reads `status`, `sid`, `token` and the rest out of one array -- so a
     * market's profile answers in the shape the sender already reads and no sender has to learn a second one. Null when this account does
     * not have that gateway at all, which is NOT the same as falling back to the shared one: see {@see NotificationProfiles::for()}.
     *
     * @return array<string, mixed>|null
     */
    public function gateway(string $name): ?array
    {
        $settings = $this->setting("gateways.{$name}");

        if (!is_array($settings)) {
            return null;
        }

        $secrets = $this->credential("gateways.{$name}");

        return array_replace($settings, is_array($secrets) ? $secrets : []);
    }

    /** Whether this account names its gateways at all, so that a channel whose senders ask gateway by gateway knows to stop asking elsewhere. */
    public function namesGateways(): bool
    {
        return is_array($this->setting('gateways'));
    }

    /**
     * What a screen, a response or a log line may be shown: the settings, and the NAMES of the secrets this account holds, never a value.
     *
     * The names are given because an operator has to be able to see that a password is set without being shown it -- the same answer the
     * carrier settings screen gives.
     *
     * @return array{channel: string, country: string, label: ?string, settings: array<string, mixed>, credentials_present: list<string>}
     */
    public function visible(): array
    {
        return [
            'channel' => $this->channel,
            'country' => $this->country,
            'label' => $this->label,
            'settings' => $this->settings,
            'credentials_present' => array_keys($this->credentials),
        ];
    }
}
