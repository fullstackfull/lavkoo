<?php

namespace App\Http\Middleware;

use App\Http\Requests\Admin\LanguageRequest;
use App\Utils\Helpers;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class APILocalizationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $requested = strtolower((string) $request->header('lang'));
        if (preg_match('/^' . LanguageRequest::CODE_PATTERN . '$/D', $requested) !== 1) {
            $requested = '';
        }
        // Customer-app builds in the field sent the COUNTRY code here ('sa'/'us'); everything
        // localized keys on the language code, so those aliases are folded in rather than
        // letting an installed app fall out of its own language.
        $requested = ['sa' => 'ar', 'us' => 'en'][$requested] ?? $requested;
        $local = $requested !== '' ? $requested : Helpers::default_lang();
        App::setLocale($this->resolveAgainstStoreLanguages(requested: $local));
        return $next($request);
    }

    /**
     * The code the STORE actually files its translations under.
     *
     * A shop is free to give its Arabic the code it likes — this one uses `sy` —
     * while every published app asks for `ar`. Translations are looked up by an
     * exact locale match, so the mismatch silently served English to Arabic
     * speakers even though the merchant had entered every name. The requested
     * code is therefore matched against the store's own languages first, and
     * only then by family, so a client never falls out of its language over a
     * naming choice it cannot see.
     */
    private function resolveAgainstStoreLanguages(string $requested): string
    {
        // Choosing a locale is a courtesy, never a precondition for serving the request: before
        // the settings table exists — a fresh install, a migration in flight — the requested code
        // stands on its own. Same cached probe AppServiceProvider uses, so this costs nothing.
        if (!Cache::remember('_schema_has_business_settings', 21600, fn () => Schema::hasTable('business_settings'))) {
            return $requested;
        }

        $languages = getWebConfig(name: 'language');
        if (!is_array($languages) || $languages === []) {
            return $requested;
        }

        $codes = array_values(array_filter(array_map(
            fn ($language) => isset($language['code']) ? strtolower((string)$language['code']) : null,
            $languages
        )));

        // The tag the store lists (`pt-br`), then the language the tag names whatever its region (`pt`).
        $requestedLanguage = $this->primaryLanguage(tag: $requested);
        foreach (array_unique([$requested, $requestedLanguage]) as $tag) {
            if (in_array($tag, $codes, true)) {
                return $tag;
            }
        }

        // Same language, different code: match on the language's own name, then
        // on the well-known regional codes a store might have chosen for it.
        $families = [
            'ar' => ['arabic', 'العربية'],
            'en' => ['english'],
        ];
        $names = $families[$requestedLanguage] ?? [];

        foreach ($languages as $language) {
            $name = strtolower((string)($language['name'] ?? ''));
            $code = strtolower((string)($language['code'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            if ($name === $requestedLanguage) {
                return $code;
            }
            foreach ($names as $candidate) {
                if (str_contains($name, $candidate)) {
                    return $code;
                }
            }
        }

        return $this->storeDefaultLanguage(languages: $languages) ?? $requested;
    }

    /** The language of a language tag, whatever its region: `en-us` and `en_us` are `en`. */
    private function primaryLanguage(string $tag): string
    {
        return preg_split('/[-_]/', $tag)[0];
    }

    /**
     * A code the store does not list is no locale: translate() includes the files of resources/lang/<code> and writes the keys it did not
     * know, so a visitor's header must never name a directory of its own choosing. The store's default language answers instead.
     */
    private function storeDefaultLanguage(array $languages): ?string
    {
        foreach ($languages as $language) {
            if (!empty($language['default']) && isset($language['code'])) {
                return strtolower((string)$language['code']);
            }
        }

        return isset($languages[0]['code']) ? strtolower((string)$languages[0]['code']) : null;
    }
}
