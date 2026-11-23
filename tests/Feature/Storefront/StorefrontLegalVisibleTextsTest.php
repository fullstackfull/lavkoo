<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLegalReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontLegalPages;
use Tests\TestCase;

/**
 * What a visitor of a storefront can read is the current text of each document type in one locale, and only while that text counts as
 * approved text (I2-04). A current text that is unfit shows nothing, and the older text it superseded never takes its place. The texts of
 * another country or another locale are never read. Readiness itself is unchanged: these are two more readings of the same table.
 */
class StorefrontLegalVisibleTextsTest extends TestCase
{
    use BuildsStorefrontLegalPages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
    }

    private function readiness(): StorefrontLegalReadiness
    {
        return app(StorefrontLegalReadiness::class);
    }

    /** @return list<string> the document types the storefront shows in the locale */
    private function visibleTypes(string $country = 'KW', string $locale = 'en'): array
    {
        return array_keys($this->readiness()->visibleTextsFor(countryCode: $country, locale: $locale));
    }

    public function test_the_current_text_of_every_document_type_is_visible(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $texts = $this->readiness()->visibleTextsFor(countryCode: 'KW', locale: 'en');

        $this->assertSame(config('gcc.storefronts.legal.required_documents'), array_keys($texts));
        $this->assertSame('<p>KW terms text in en</p>', $texts['terms']->body);
    }

    public function test_the_types_come_back_in_the_configured_order_whatever_order_the_rows_were_written_in(): void
    {
        $configured = (array) config('gcc.storefronts.legal.required_documents');

        // Deliberately the reverse of the configured order. publishStorefrontTexts writes them in configured order, so the
        // reader used to agree with the configuration by accident on an engine that returns rows in insertion order -- and
        // disagree on one that does not. MySQL 8 disagreed where MariaDB agreed.
        foreach (array_reverse($configured) as $type) {
            foreach (['en', 'ar'] as $locale) {
                $this->publishStorefrontText(country: 'KW', type: $type, locale: $locale);
            }
        }

        $this->assertSame($configured, $this->visibleTypes(country: 'KW', locale: 'en'), 'the configured order, not the order the rows were written in');
    }

    public function test_the_country_and_the_locale_are_matched_regardless_of_case(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->assertSame(config('gcc.storefronts.legal.required_documents'), $this->visibleTypes(country: ' kw ', locale: 'EN'));
    }

    public function test_the_texts_of_another_country_or_another_locale_are_not_visible(): void
    {
        $this->publishStorefrontTexts(country: 'AE');
        $this->publishStorefrontTexts(country: 'KW', locales: ['ar']);

        $this->assertSame([], $this->visibleTypes(country: 'KW', locale: 'en'));
        $this->assertSame([], $this->visibleTypes(country: 'QA', locale: 'en'));
    }

    #[DataProvider('unfitCurrentTexts')]
    public function test_a_current_text_that_is_unfit_is_not_visible(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW', withheldTypes: ['terms']);
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: $this->overridesOf(defect: $defect, body: $this->storefrontTextBody('KW', 'terms', 'en')));

        $this->assertNotContains('terms', $this->visibleTypes());
        $this->assertContains('privacy', $this->visibleTypes());
    }

    #[DataProvider('unfitCurrentTexts')]
    public function test_an_older_text_does_not_take_the_place_of_a_current_text_that_is_unfit(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: [
            'version' => 2, 'effective_from' => now()->subHour(),
        ] + $this->overridesOf(defect: $defect, body: $this->storefrontTextBody('KW', 'terms', 'en')));

        $this->assertNotContains('terms', $this->visibleTypes());
    }

    #[DataProvider('textsThatAreNotCurrent')]
    public function test_a_row_that_is_not_current_is_never_visible_and_does_not_replace_the_current_text(string $defect): void
    {
        $this->publishStorefrontTexts(country: 'KW');
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: [
            'version' => 2, 'body' => '<p>never shown</p>', 'body_hash' => hash('sha256', 'never shown'),
        ] + $this->overridesOf(defect: $defect, body: '<p>never shown</p>'));

        $this->assertSame('<p>KW terms text in en</p>', $this->readiness()->visibleTextsFor(countryCode: 'KW', locale: 'en')['terms']->body);
    }

    public function test_a_missing_table_shows_nothing_and_no_error(): void
    {
        Schema::dropIfExists('storefront_legal_documents');

        $this->assertSame([], $this->visibleTypes());
        $this->assertNull($this->readiness()->nextTextTakesEffectAt(countryCode: 'KW'));
    }

    public function test_no_scheduled_text_means_no_next_moment(): void
    {
        $this->publishStorefrontTexts(country: 'KW');

        $this->assertNull($this->readiness()->nextTextTakesEffectAt(countryCode: 'KW'));
    }

    public function test_the_next_moment_is_the_earliest_published_text_that_is_not_effective_yet(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $this->travelTo($now);
        $this->publishStorefrontTexts(country: 'KW');
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: ['version' => 2, 'effective_from' => $now->addHours(40)]);
        $this->publishStorefrontText(country: 'KW', type: 'privacy', locale: 'ar', overrides: ['version' => 2, 'effective_from' => $now->addHours(21)]);

        $this->assertEquals($now->addHours(21), $this->readiness()->nextTextTakesEffectAt(countryCode: ' kw'));
    }

    public function test_drafts_texts_already_effective_and_other_countries_do_not_make_a_next_moment(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $this->travelTo($now);
        $this->publishStorefrontText(country: 'KW', type: 'terms', locale: 'en', overrides: ['version' => 2, 'status' => 'draft', 'effective_from' => $now->addHours(21)]);
        $this->publishStorefrontText(country: 'KW', type: 'privacy', locale: 'en', overrides: ['effective_from' => $now->subHours(3)]);
        $this->publishStorefrontText(country: 'AE', type: 'terms', locale: 'en', overrides: ['version' => 2, 'effective_from' => $now->addHours(21)]);

        $this->assertNull($this->readiness()->nextTextTakesEffectAt(countryCode: 'KW'));
    }
}
