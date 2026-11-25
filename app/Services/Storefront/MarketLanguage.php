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
    /**
     * The language this market writes in to a recipient who has chosen none, or null when the market names none the platform has.
     *
     * A market that names a LIST but no default writes in the first language of its list: a list with no default is an operator saying which
     * languages a market presents and not which of them comes first, and the first is the only answer that needs no second decision.
     */
    public static function of(?string $country): ?string
    {
        $code = self::marketKey($country);

        if ($code === null) {
            return null;
        }

        $named = config('gcc.storefronts.language.by_country.' . $code);
        $named = is_string($named) ? trim($named) : '';

        if ($named !== '' && in_array($named, LocalisedSettings::activeLocales(), true)) {
            return $named;
        }

        return self::listOf($code)[0] ?? null;
    }

    /**
     * The languages this market presents, in the order an operator listed them, or an EMPTY list when the market names none (I1-16).
     *
     * Empty means "the platform's own language list", which is every market today and every market while storefronts are off: a market is
     * listed in `gcc.storefronts.language.lists_by_country` only once somebody has decided which languages it presents. A locale the platform
     * has not installed is dropped rather than offered, for the same reason a default is: `translate()` would write language files for a locale
     * nobody can read.
     *
     * @return list<string>
     */
    public static function listOf(?string $country): array
    {
        $code = self::marketKey($country);

        if ($code === null) {
            return [];
        }

        $named = config('gcc.storefronts.language.lists_by_country.' . $code);

        if (!is_array($named)) {
            return [];
        }

        $installed = LocalisedSettings::activeLocales();
        $list = [];

        foreach ($named as $locale) {
            $locale = is_string($locale) ? trim($locale) : '';

            if ($locale !== '' && in_array($locale, $installed, true) && !in_array($locale, $list, true)) {
                $list[] = $locale;
            }
        }

        return $list;
    }

    /**
     * Whether this market presents this language.
     *
     * A market that names no list presents whatever the PLATFORM presents, which is the question every reader asked before markets existed
     * and the answer that keeps a one-market shop unchanged. A market with a list presents that list and nothing else -- which is the point:
     * a customer whose stored language is one the market does not present is written to in the market's own language rather than in a
     * language its storefront does not offer.
     */
    public static function presents(?string $country, string $locale): bool
    {
        $locale = trim($locale);

        if ($locale === '') {
            return false;
        }

        $list = self::listOf($country);

        return $list === [] ? in_array($locale, LocalisedSettings::activeLocales(), true) : in_array($locale, $list, true);
    }

    /**
     * Which way this market's own language reads: `rtl` or `ltr`, or null when the market names no language of its own.
     *
     * Taken from the DIRECTION the platform's language list declares for that locale and never derived from the language code, which is the
     * rule the website already follows (`SharedController::changeLanguage` writes the list entry's direction into the session, and
     * `WebsiteDirectionTest` fails on a direction read off the code). A list entry that declares no direction is left-to-right, which is what
     * the platform does with one today.
     */
    public static function directionOf(?string $country): ?string
    {
        $language = self::of($country);

        return $language === null ? null : self::directionOfLocale($language);
    }

    /** The direction the platform's language list declares for one locale: `rtl` where it says so, `ltr` otherwise. */
    public static function directionOfLocale(string $locale): string
    {
        foreach (self::platformLanguages() as $language) {
            if ((string) ($language['code'] ?? '') === $locale) {
                return strtolower(trim((string) ($language['direction'] ?? 'ltr'))) === 'rtl' ? 'rtl' : 'ltr';
            }
        }

        return 'ltr';
    }

    /** The market a question is asked about, upper case, or null for anything that is not a configured market. */
    private static function marketKey(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * @return list<array<string, mixed>> the platform's own language list, which is where a direction is declared
     */
    private static function platformLanguages(): array
    {
        try {
            $languages = getWebConfig(name: 'language');
        } catch (\Throwable) {
            return [];
        }

        return is_array($languages)
            ? array_values(array_filter(array_map(static fn ($language) => is_array($language) ? $language : null, $languages)))
            : [];
    }
}
