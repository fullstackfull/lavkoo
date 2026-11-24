<?php

namespace App\Services\Storefront;

use App\Services\Theme\LocalisedSettings;

/**
 * The language a market writes in to somebody who has not chosen one (A1-17).
 *
 * A notification is written in the recipient's own `app_language` when they have set one. When they have not -- a customer created at a
 * till, an order placed before the app asked, a seller who never opened the settings -- the platform writes in whatever language the
 * sending process happens to be in, which for a queue worker is the language of whichever job it ran last. Saying "the platform's default"
 * is the right answer for a single-market shop and the wrong one for six: a Kuwaiti customer who has chosen no language should be written
 * to in the language Kuwait sells in, not in the language of the market whose order was queued before theirs.
 *
 * The map ships EMPTY, so every market answers null and nothing changes: a recipient with no language of their own is written to exactly as
 * today. A market is added to `gcc.storefronts.language.by_country` only once somebody has decided what that market writes in.
 *
 * A locale the platform has not installed is never returned. `translate()` writes the keys it cannot find into
 * `resources/lang/<locale>/new-messages.php`, so binding a language the shop does not have would have the platform writing language files
 * for a locale nobody can read.
 */
final class MarketLanguage
{
    /** The language this market writes in to a recipient who has chosen none, or null when the market names none the platform has. */
    public static function of(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));
        if ($code === '') {
            return null;
        }

        $named = config('gcc.storefronts.language.by_country.' . $code);
        if (!is_string($named) || trim($named) === '') {
            return null;
        }

        $named = trim($named);

        return in_array($named, LocalisedSettings::activeLocales(), true) ? $named : null;
    }
}
