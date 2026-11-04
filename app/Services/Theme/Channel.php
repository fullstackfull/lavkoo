<?php

namespace App\Services\Theme;

/**
 * Which surface is being served.
 *
 * Until now this question was answered in two unrelated places — an app-safe component list, and a
 * `platforms` targeting column holding `web` or `app` — which was workable while there were two
 * surfaces and becomes a fork per surface as soon as there are three. A channel is one value with
 * one vocabulary, so a vendor app is a new entry here rather than a new branch everywhere.
 *
 * A channel is NOT a platform. `customer_app` is the shopping app whether it runs on Android or
 * iOS; the platform (and the device) stay separate dimensions in {@see ViewerContext}, because a
 * merchant genuinely wants "phones only" and "the customer app only" to be different rules.
 *
 * Deliberately a plain class of constants rather than a table: a channel is only real if code can
 * draw it. A row in a database cannot ship a renderer.
 */
final class Channel
{
    /** The storefront. Draws every section type the registry knows. */
    public const WEB = 'web';

    /** The shopping app. Draws what its build declares through the capability handshake. */
    public const CUSTOMER_APP = 'customer_app';

    /** The seller app. Registered so pages and sections can target it before it can draw anything. */
    public const VENDOR_APP = 'vendor_app';

    public const ALL = [self::WEB, self::CUSTOMER_APP, self::VENDOR_APP];

    /** The channels a merchant can currently publish to — the ones with a renderer behind them. */
    public const RENDERABLE = [self::WEB, self::CUSTOMER_APP];

    /**
     * The channel a viewer belongs to.
     *
     * The mapping is deliberately narrow: `platform: app` means the customer app today, because
     * that is the only app that speaks this API. When the vendor app arrives it will say so in its
     * own request, and the default stays put.
     */
    public static function forViewer(ViewerContext $viewer): string
    {
        if ($viewer->channel !== null && in_array($viewer->channel, self::ALL, true)) {
            return $viewer->channel;
        }

        return $viewer->platform === ViewerContext::PLATFORM_APP
            ? self::CUSTOMER_APP
            : self::WEB;
    }

    /** A declared channel value, or null when it is not one this engine knows. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, self::ALL, true) ? $value : null;
    }

    /**
     * Read a section's `channels` restriction.
     *
     * Empty or missing means every channel, exactly like `platforms` — the common case is a section
     * the merchant never restricted, and it must cost nothing.
     *
     * @return array<int, string>
     */
    public static function tokens(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $tokens = [];

        foreach ($value as $entry) {
            $channel = self::normalize($entry);

            if ($channel !== null && !in_array($channel, $tokens, true)) {
                $tokens[] = $channel;
            }
        }

        return $tokens;
    }

    /**
     * Whether a section restricted to these channels may be drawn by this one.
     *
     * The same union rule the platform targeting uses: no restriction means everywhere, and a
     * restriction that names this channel means here.
     *
     * @param  array<int, string>  $allowed
     */
    public static function permits(array $allowed, string $channel): bool
    {
        return $allowed === [] || in_array($channel, $allowed, true);
    }

    public static function label(string $channel): string
    {
        return match ($channel) {
            self::WEB          => 'web_storefront',
            self::CUSTOMER_APP => 'customer_app',
            self::VENDOR_APP   => 'vendor_app',
            default            => $channel,
        };
    }
}
