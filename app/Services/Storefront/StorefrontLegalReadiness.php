<?php

namespace App\Services\Storefront;

use App\Models\StorefrontLegalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a storefront may sell (GCC-11). For each required document type and locale, the only text that can count is the newest one
 * that is already effective: a published row with the latest effective date, then the latest version. Older texts it has superseded
 * never count, and drafts and scheduled texts are not effective yet. That current text must be approved by someone other than its
 * author, not revoked, not blank, and not an unedited template.
 *
 * The default storefront is grandfathered (GCC-19). When the table is missing the answer is no, so a storefront whose legal text cannot
 * be read stays closed instead of failing with a server error.
 */
final class StorefrontLegalReadiness
{
    private ?bool $tableExists = null;

    public function isReady(string $countryCode): bool
    {
        $country = strtoupper(trim($countryCode));

        if (in_array($country, (array) config('gcc.storefronts.legal.grandfathered', []), true)) {
            return true;
        }

        if (!$this->tableExists()) {
            return false;
        }

        $currentTexts = $this->currentTextsOf($country);

        foreach ($this->requiredPairsOf($country) as [$type, $locale]) {
            $text = $currentTexts[$type][$locale] ?? null;

            if ($text === null || !$this->isSellable($text)) {
                return false;
            }
        }

        return true;
    }

    /** The text a new acceptance is recorded against: the newest effective row for that type and locale, or null when there is none. */
    public function currentTextFor(string $countryCode, string $documentType, string $locale): ?StorefrontLegalDocument
    {
        return $this->currentTextsOf(strtoupper(trim($countryCode)))[$documentType][strtolower($locale)] ?? null;
    }

    /**
     * What a visitor of the storefront can read in one locale: for each document type, the current text, and only while it counts as approved
     * text. A current text that is revoked, not approved by someone other than its author, blank or an unedited template shows nothing, and
     * the older text it superseded never takes its place.
     *
     * @return array<string, StorefrontLegalDocument> keyed by document type
     */
    public function visibleTextsFor(string $countryCode, string $locale): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $visible = [];
        foreach ($this->currentTextsOf(strtoupper(trim($countryCode))) as $documentType => $textsByLocale) {
            $text = $textsByLocale[strtolower($locale)] ?? null;

            if ($text !== null && $this->isSellable($text)) {
                $visible[$documentType] = $text;
            }
        }

        return $visible;
    }

    /** When the next scheduled text of the storefront takes effect, or null when none is scheduled: what was read before that moment is stale after it. */
    public function nextTextTakesEffectAt(string $countryCode): ?CarbonImmutable
    {
        if (!$this->tableExists()) {
            return null;
        }

        $effectiveFrom = StorefrontLegalDocument::query()
            ->where('country_code', strtoupper(trim($countryCode)))
            ->where('status', StorefrontLegalDocument::STATUS_PUBLISHED)
            ->where('effective_from', '>', CarbonImmutable::now())
            ->min('effective_from');

        return $effectiveFrom === null ? null : CarbonImmutable::parse($effectiveFrom);
    }

    private function tableExists(): bool
    {
        return $this->tableExists ??= Schema::hasTable('storefront_legal_documents');
    }

    /**
     * @return array<string, array<string, StorefrontLegalDocument>> the newest effective row for each document type and locale
     */
    private function currentTextsOf(string $country): array
    {
        $effective = StorefrontLegalDocument::query()
            ->where('country_code', $country)
            ->where('status', StorefrontLegalDocument::STATUS_PUBLISHED)
            ->where('effective_from', '<=', CarbonImmutable::now())
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->get();

        $current = [];
        foreach ($effective as $text) {
            $current[$text->document_type][strtolower($text->locale)] ??= $text;
        }

        return $current;
    }

    private function isSellable(StorefrontLegalDocument $text): bool
    {
        return $text->revoked_at === null
            && $text->approved_at !== null
            && $text->created_by !== null
            && $text->approved_by !== null
            && $text->approved_by !== $text->created_by
            && trim((string) $text->body) !== ''
            && ($text->template_hash === null || $text->template_hash !== $text->body_hash);
    }

    /** @return list<array{0: string, 1: string}> */
    private function requiredPairsOf(string $country): array
    {
        $locales = config("gcc.storefronts.legal.locales_by_country.{$country}") ?? config('gcc.storefronts.legal.required_locales', []);

        $pairs = [];
        foreach ((array) config('gcc.storefronts.legal.required_documents', []) as $type) {
            foreach ((array) $locales as $locale) {
                $pairs[] = [$type, strtolower($locale)];
            }
        }

        return $pairs;
    }
}
