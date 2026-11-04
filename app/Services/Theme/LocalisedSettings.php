<?php

namespace App\Services\Theme;

/**
 * One section, two languages, no second section.
 *
 * The shop speaks Arabic and English, and a section's title was one string — so a merchant chose
 * a language to disappoint, or stacked two sections and targeted nobody. This makes any text a
 * merchant types translatable per language, the same way it is already overridable per breakpoint:
 * the base value stays where it always was, and a `title_ar` beside it wins for the shopper whose
 * request says so.
 *
 * The convention is the point. Values stay flat strings, so nothing about the API's shape moves:
 * an installed build keeps receiving `title` as a string — just the right string for the `lang`
 * header it already sends on every request. Blank override means "inherit the base", exactly as a
 * blank tablet override does; a merchant translates only the fields worth translating.
 */
class LocalisedSettings
{
    /**
     * Memoised list of live locale codes. Static, so it outlives the request in long-running
     * workers — which is fine for a value that changes when an admin edits languages (rare, and
     * the language save path calls {@see forget()}), and would NOT be fine for a failure: an
     * empty read is never memoised, so a transient config blip cannot switch localisation off
     * for the life of the worker.
     *
     * @var array<int, string>|null
     */
    private static ?array $activeLocales = null;

    /**
     * Fold the overrides for one locale into the base keys, and strip the rest.
     *
     * After this, the array looks exactly as it always did — plain keys, plain strings — which is
     * what lets every renderer, partial and installed app stay untouched.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function collapse(array $settings, ?string $locale, ?array $overrides = null): array
    {
        // Schema-aware path: the caller names exactly which keys are locale overrides (from
        // SectionRegistry::localeOverrideKeys), so a real setting whose name merely ends in a
        // language code — `source_id` beside Indonesian's `id` — can never be mistaken for one.
        if ($overrides !== null) {
            foreach ($overrides as $key => [$base, $code]) {
                if (!array_key_exists($key, $settings)) {
                    continue;
                }

                $value = $settings[$key];

                if ($code === $locale && is_string($value) && trim($value) !== '') {
                    $settings[$base] = $value;
                }

                unset($settings[$key]);
            }

            return $settings;
        }

        $codes = self::activeLocales();
        if ($codes === []) {
            return $settings;
        }

        foreach ($codes as $code) {
            $suffix = '_' . $code;

            foreach (array_keys($settings) as $key) {
                if (!str_ends_with($key, $suffix)) {
                    continue;
                }

                $base = substr($key, 0, -strlen($suffix));

                // Only ever touch a key that overrides one actually present: a schema key that
                // merely happens to end in a language code is somebody's setting, not our override.
                if ($base === '' || !array_key_exists($base, $settings)) {
                    continue;
                }

                $value = $settings[$key];

                if ($code === $locale && is_string($value) && trim($value) !== '') {
                    $settings[$base] = $value;
                }

                // Stripped whether it applied or not: an override for some other language is
                // noise to this request, and payload bytes the phone would download for nothing.
                unset($settings[$key]);
            }
        }

        return $settings;
    }

    /**
     * The languages a merchant can write an override for: every live language except the default,
     * whose text is the base value itself.
     *
     * @return array<string, string> code => display name
     */
    public static function overridable(): array
    {
        $default = self::defaultLocale();
        $extras = [];

        foreach (self::languages() as $language) {
            $code = (string) ($language['code'] ?? '');
            if ($code !== '' && $code !== $default) {
                $extras[$code] = (string) ($language['name'] ?? $code);
            }
        }

        return $extras;
    }

    public static function defaultLocale(): string
    {
        foreach (self::languages() as $language) {
            if (!empty($language['default'])) {
                return (string) ($language['code'] ?? 'en');
            }
        }

        return 'en';
    }

    /** @return array<int, string> */
    public static function activeLocales(): array
    {
        if (self::$activeLocales !== null) {
            return self::$activeLocales;
        }

        $codes = array_values(array_filter(array_map(
            static fn (array $language) => (string) ($language['code'] ?? ''),
            self::languages(),
        ), static fn (string $code) => $code !== ''));

        // An empty answer is indistinguishable from a failed read, and memoising a failure would
        // disable collapse — leaking override keys into payloads — until the worker restarts.
        if ($codes !== []) {
            self::$activeLocales = $codes;
        }

        return $codes;
    }

    /** Tests swap languages mid-process; a memo that outlives the shop's language list lies. */
    public static function forget(): void
    {
        self::$activeLocales = null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function languages(): array
    {
        try {
            $languages = getWebConfig(name: 'language');
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($languages)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($language) => is_array($language) ? $language : null, $languages),
            static fn ($language) => $language !== null && ($language['status'] ?? 1),
        ));
    }
}
