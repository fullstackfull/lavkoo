<?php

namespace App\Services\SellerCenter;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How this product spells a moment in time.
 *
 * Three reasons a screen must not call `->format()` itself.
 *
 * **Bidi.** "25 Aug 05:05" inside an Arabic sentence is reordered by the bidirectional algorithm
 * into "Aug 05:05 25" — the reader sees a date that does not exist. A timestamp is a code, and the
 * markup around it has to isolate it (`.sc-ts`), which only happens if there is one place that
 * knows a timestamp is being rendered.
 *
 * **The month is a word.** `format()` returns "Aug" whatever the reader's language;
 * `translatedFormat()` returns "أغسطس". A panel that translates its sentences and not its dates is
 * half-translated.
 *
 * **The locale is not the language folder.** This install's Arabic lives in `sy`, which Carbon has
 * never heard of: asking it to translate under that locale emits two `include()` failures per call
 * before silently falling back. `Shell::locale()` already maps the folder to the tag Carbon knows,
 * so the translation is asked for in a language it has.
 *
 * A null moment renders as an em dash. It is not "now", and it is not the epoch.
 */
class Moment
{
    /** Date and clock together: a table cell, a card's meta line, a drawer's subtitle. */
    public static function stamp(?DateTimeInterface $at, bool $withYear = false): string
    {
        return self::render($at, $withYear ? 'j M Y H:i' : 'j M H:i');
    }

    /** The day alone, where the time would be noise. */
    public static function day(?DateTimeInterface $at, bool $withYear = true): string
    {
        return self::render($at, $withYear ? 'j M Y' : 'j M');
    }

    /** The clock alone, for a timeline whose rows all happened on the day above them. */
    public static function time(?DateTimeInterface $at): string
    {
        return self::render($at, 'H:i');
    }

    /** The long form a page header uses: "Tuesday 25 August". */
    public static function longDay(?DateTimeInterface $at): string
    {
        return self::render($at, 'l j F');
    }

    private static function render(?DateTimeInterface $at, string $pattern): string
    {
        if ($at === null) {
            return '—';
        }

        return Carbon::instance(
            $at instanceof Carbon ? $at : Carbon::parse($at->format('Y-m-d H:i:s'))
        )->locale(Shell::locale())->translatedFormat($pattern);
    }
}
