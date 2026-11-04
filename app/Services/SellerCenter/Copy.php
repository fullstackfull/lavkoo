<?php

namespace App\Services\SellerCenter;

/**
 * Sentences, not fragments.
 *
 * Two things make `translate()` alone the wrong tool for this product's copy. It upper-cases the
 * first letter of every English string, so a word used mid-sentence comes back capitalised ("1–10
 * Of 218"); and it takes no placeholders, so the only way to get a number into a sentence is to
 * concatenate — which produces word order no translator can fix, because Arabic does not put the
 * pieces where English does.
 *
 * So the whole sentence is one key with `:placeholders`, and this substitutes them. The approved
 * copy in the handoff is written that way throughout: "14 orders are inside their ship-by window",
 * never "Orders: 14".
 */
class Copy
{
    /**
     * One translated sentence with its values filled in.
     *
     * @param  array<string, string|int|float|null>  $replace
     */
    public static function line(string $key, array $replace = []): string
    {
        $sentence = (string) translate($key);

        if ($replace === []) {
            return $sentence;
        }

        $tokens = [];
        foreach ($replace as $name => $value) {
            $tokens[':' . $name] = (string) ($value ?? '—');
        }

        return strtr($sentence, $tokens);
    }

    /**
     * A clause meant to sit inside a larger sentence.
     *
     * `translate()` upper-cases the first letter of every English string it returns, which is
     * correct when the string starts a sentence and wrong when it is the second half of one — it
     * produced "When stock runs out, Hide the listing." Only an ASCII capital is undone, so Arabic,
     * which has no case, is returned exactly as the translator wrote it.
     *
     * @param  array<string, string|int|float|null>  $replace
     */
    public static function clause(string $key, array $replace = []): string
    {
        return lcfirst(self::line($key, $replace));
    }

    /**
     * Singular or plural, chosen by the count and translated as two separate whole sentences.
     *
     * Arabic has more plural forms than English, so this deliberately does not try to be a general
     * pluraliser: a screen that needs the dual or the "few" form asks for its own key.
     */
    public static function choice(string $singularKey, string $pluralKey, int $count, array $replace = []): string
    {
        return self::line($count === 1 ? $singularKey : $pluralKey, $replace + ['count' => $count]);
    }

    /**
     * A duration a person can read: days and hours, or hours and minutes, never 61101 minutes.
     *
     * Kept LTR-isolated at the call site rather than here, because the digits are a code and the
     * surrounding sentence is prose.
     */
    public static function duration(int $minutes): string
    {
        $minutes = max(0, $minutes);

        if ($minutes < 60) {
            return self::line('n_minutes', ['count' => $minutes]);
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            $rest = $minutes % 60;

            return $rest === 0
                ? self::line('n_hours', ['count' => $hours])
                : self::line('n_hours_n_minutes', ['hours' => $hours, 'minutes' => $rest]);
        }

        $days = intdiv($hours, 24);
        $restHours = $hours % 24;

        return $restHours === 0
            ? self::line('n_days', ['count' => $days])
            : self::line('n_days_n_hours', ['days' => $days, 'hours' => $restHours]);
    }

    /**
     * How an SLA cell reads, from the state `Status::sla()` decided (handoff 06 §5).
     *
     * @param  array{state: string, minutes: ?int}  $sla
     */
    public static function sla(array $sla): string
    {
        return match ($sla['state']) {
            'breached' => self::line('breached_by_time', ['time' => self::duration((int) $sla['minutes'])]),
            'closing', 'soon' => self::line('time_left', ['time' => self::duration((int) $sla['minutes'])]),
            'on_time' => translate('on_time'),
            'met' => translate('met'),
            default => '—',
        };
    }
}
