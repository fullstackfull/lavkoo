<?php

namespace App\Services\SellerCenter;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

/**
 * Shell-level user preferences: reading direction and table density.
 *
 * Both are set once on the app root and read everywhere else (handoff 02 §1, 03 §11, 10 B1). They
 * live in the session so a page render is authoritative — a client-side class swap after paint is
 * a visible flash, and the density decides row heights that the server already knows.
 */
class Shell
{
    public const DENSITY_COMPACT = 'compact';
    public const DENSITY_COMFORTABLE = 'comfortable';

    private const DENSITY_KEY = 'sc_density';

    /**
     * Arabic is the primary locale, so RTL is the default when the panel has no stored preference
     * and the active language is one of the Arabic locales this install ships.
     */
    public static function direction(): string
    {
        $stored = Session::get('direction');
        if ($stored === 'rtl' || $stored === 'ltr') {
            return $stored;
        }

        return in_array(self::language(), ['sy', 'sa', 'ar'], true) ? 'rtl' : 'ltr';
    }

    public static function isRtl(): bool
    {
        return self::direction() === 'rtl';
    }

    public static function language(): string
    {
        return (string) (Session::get('local') ?: (function_exists('getDefaultLanguage') ? getDefaultLanguage() : 'en'));
    }

    /** The BCP-47 tag for `lang`, which is not the same thing as this install's language folder. */
    public static function locale(): string
    {
        return match (self::language()) {
            'sy', 'sa' => 'ar',
            'bd' => 'bn',
            'in' => 'hi',
            default => self::language(),
        };
    }

    public static function density(): string
    {
        $stored = Session::get(self::DENSITY_KEY);

        return $stored === self::DENSITY_COMFORTABLE ? self::DENSITY_COMFORTABLE : self::DENSITY_COMPACT;
    }

    public static function setDensity(string $density): void
    {
        Session::put(self::DENSITY_KEY, $density === self::DENSITY_COMFORTABLE ? self::DENSITY_COMFORTABLE : self::DENSITY_COMPACT);
    }

    /**
     * A URL for a named route, or null while that screen has not shipped.
     *
     * The shell is built once and filled in over eight waves, so it must render correctly with
     * half its destinations missing. Returning null lets a partial omit the control entirely
     * rather than draw one that goes nowhere.
     */
    public static function route(string $name, array|int|string $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }

    /** The glyph for "back", which is the one arrow that must flip with the reading direction. */
    public static function backGlyph(): string
    {
        return self::isRtl() ? 'arrow-right' : 'arrow-left';
    }
}
