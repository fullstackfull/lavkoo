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
 * be read stays closed instead of failing with a server error. A configuration that requires no document type or no locale is a missing
 * configuration, not a reason to open a market, so it answers no too (I2-02).
 */
final class StorefrontLegalReadiness
{
    private ?bool $tableExists = null;

    public function isReady(string $countryCode): bool
    {
        $country = strtoupper(trim($countryCode));

        if ($this->isGrandfathered($country)) {
            return true;
        }

        $requiredPairs = $this->requiredPairsOf($country);

        if ($requiredPairs === [] || !$this->tableExists()) {
            return false;
        }

        $currentTexts = $this->currentTextsOf($country);

        foreach ($requiredPairs as [$type, $locale]) {
            $text = $currentTexts[$type][$locale] ?? null;

            if ($text === null || !$this->isSellable($text)) {
                return false;
            }
        }

        return true;
    }

    /** Whether the storefront keeps the documents it has always had and so needs neither their texts nor a review of them. */
    public function isGrandfathered(string $countryCode): bool
    {
        return in_array(strtoupper(trim($countryCode)), (array) config('gcc.storefronts.legal.grandfathered', []), true);
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

        $current = $this->currentTextsOf(strtoupper(trim($countryCode)));

        $visible = [];
        foreach ($this->documentTypesInOrder(present: array_keys($current)) as $documentType) {
            $text = $current[$documentType][strtolower($locale)] ?? null;

            if ($text !== null && $this->isSellable($text)) {
                $visible[$documentType] = $text;
            }
        }

        return $visible;
    }

    /**
     * The configured document types first, in the order the configuration lists them, then any other type by name.
     *
     * The order was the order the rows happened to arrive in, which is the engine's to choose: currentTextsOf orders only
     * enough to pick the current text of each type, so two types of the same effective date and version could come back
     * either way round. MySQL 8 and MariaDB disagreed, and the configured order is the one every reader means -- it is what
     * the readiness report, the storefront's legal pages and this test all compare against.
     *
     * @param list<string> $present
     * @return list<string>
     */
    private function documentTypesInOrder(array $present): array
    {
        $configured = (array) config('gcc.storefronts.legal.required_documents');
        $others = array_values(array_diff($present, $configured));
        sort($others);

        return array_values(array_merge(array_intersect($configured, $present), $others));
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

    /** @return list<array{0: string, 1: string}> the document type and the locale of each text the storefront needs */
    public function requiredPairsOf(string $country): array
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
