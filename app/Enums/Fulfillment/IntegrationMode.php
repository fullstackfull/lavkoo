<?php

namespace App\Enums\Fulfillment;

/**
 * What the integration is allowed to do on THIS host, derived from two environment switches.
 *
 *  off        FULFILLMENT_INTEGRATIONS_ENABLED=false : no outbound call of any kind.
 *  read_only  enabled, FULFILLMENT_EXTERNAL_WRITES_ENABLED=false : reads and logins only. No order, product, stock,
 *             price or cancel request can leave the host, whatever an administrator switches on in the admin panel.
 *  live       enabled and writes enabled : the provider switches in the admin panel decide (Integration enabled,
 *             Send orders, Dry run).
 *
 * A staging copy of production runs `off` or `read_only`, never `live`.
 */
enum IntegrationMode: string
{
    case Off = 'off';
    case ReadOnly = 'read_only';
    case Live = 'live';

    public static function resolve(bool $enabled, bool $externalWritesEnabled): self
    {
        return match (true) {
            !$enabled => self::Off,
            $externalWritesEnabled => self::Live,
            default => self::ReadOnly,
        };
    }

    public function canWrite(): bool
    {
        return $this === self::Live;
    }

    public function canRead(): bool
    {
        return $this !== self::Off;
    }

    /** Translation key, defined in each language's new-messages.php file. */
    public function labelKey(): string
    {
        return 'integration_mode_' . $this->value;
    }
}
