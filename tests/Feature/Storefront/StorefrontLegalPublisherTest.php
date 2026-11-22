<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalPublisher;
use App\Services\Storefront\StorefrontLegalReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * I2-01 and I2-03: the legal text of a storefront is written, approved, published and revoked through one service, whose every step is audited in the same transaction. A copy of a
 * template is a draft and is never published by being copied, a text that is still the template is not approved, the approver is not the author, a published text is never edited
 * (a change is a new version), a text never takes effect in the past nor before one that is already scheduled, revoking keeps the row and closes the storefront, and the readiness
 * of a storefront reads exactly what the service writes. The people in the tests are admin ids, the texts are placeholders and no text is legal advice.
 */
class StorefrontLegalPublisherTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsStorefrontLegalDocuments;

    private const AUTHOR = 11;

    private const APPROVER = 12;

    private const TEMPLATE = 'TEMPLATE: replace this sentence with the terms of the country.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        Schema::dropIfExists('audit_logs');
        $this->buildAuditLogTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');

        parent::tearDown();
    }

    private function publisher(): StorefrontLegalPublisher
    {
        return app(StorefrontLegalPublisher::class);
    }

    private function draft(string $country = 'KW', string $type = 'terms', string $locale = 'en', string $template = self::TEMPLATE): StorefrontLegalDocument
    {
        return $this->publisher()->draftFromTemplate(country: $country, documentType: $type, locale: $locale, template: $template, author: self::AUTHOR);
    }

    private function approvedText(string $country = 'KW', string $type = 'terms', string $locale = 'en'): StorefrontLegalDocument
    {
        $draft = $this->draft(country: $country, type: $type, locale: $locale);
        $this->publisher()->edit(textId: $draft->id, body: "The {$locale} {$type} of {$country}.", editor: self::AUTHOR);

        return $this->publisher()->approve(textId: $draft->id, approver: self::APPROVER);
    }

    private function publishedText(string $country = 'KW', string $type = 'terms', string $locale = 'en'): StorefrontLegalDocument
    {
        return $this->publisher()->publish(textId: $this->approvedText(country: $country, type: $type, locale: $locale)->id, publisher: self::APPROVER);
    }

    private function publishEveryRequiredText(string $country = 'KW'): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            foreach (config('gcc.storefronts.legal.required_locales') as $locale) {
                $this->publishedText(country: $country, type: $type, locale: $locale);
            }
        }
    }

    private function ready(string $country = 'KW'): bool
    {
        return app(StorefrontLegalReadiness::class)->isReady($country);
    }

    /** @return list<object> the audit lines of the publisher, oldest first, with the context decoded */
    private function auditLines(): array
    {
        return DB::table('audit_logs')->where('action', 'like', 'storefront.legal_text.%')->orderBy('id')->get()
            ->map(function (object $line): object {
                $line->context = (array) json_decode((string) $line->context, true);
                $line->before = json_decode((string) $line->before, true);
                $line->after = json_decode((string) $line->after, true);

                return $line;
            })->all();
    }

    // ---- the copy of a template -----------------------------------------------------------------------------------------------------------------

    public function test_a_copy_of_a_template_is_a_draft_that_carries_the_hash_of_the_template_and_is_not_published(): void
    {
        $draft = $this->draft();

        $this->assertSame(['KW', 'terms', 'en', 1, StorefrontLegalDocument::STATUS_DRAFT], [$draft->country_code, $draft->document_type, $draft->locale, $draft->version, $draft->status]);
        $this->assertSame(self::TEMPLATE, $draft->body);
        $this->assertSame(hash('sha256', self::TEMPLATE), $draft->template_hash);
        $this->assertSame($draft->template_hash, $draft->body_hash);
        $this->assertSame(self::AUTHOR, (int) $draft->created_by);
        $this->assertNull($draft->approved_by);
        $this->assertNull($draft->published_at);
        $this->assertNull($draft->effective_from);
        $this->assertFalse($this->ready());
    }

    public function test_the_draft_of_one_type_and_locale_does_not_stop_the_draft_of_another(): void
    {
        $this->draft(type: 'terms', locale: 'en');
        $this->draft(type: 'terms', locale: 'ar');
        $this->draft(type: 'privacy', locale: 'en');
        $this->draft(country: 'AE', type: 'terms', locale: 'en');

        $this->assertSame(4, StorefrontLegalDocument::query()->count());
    }

    public function test_a_second_text_of_the_same_type_and_locale_cannot_be_started_while_one_is_not_published(): void
    {
        $first = $this->draft();

        try {
            $this->draft();
            $this->fail('A second draft was started beside an unpublished one.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString("Text {$first->id}", $refusal->getMessage());
        }

        $this->publisher()->discard(textId: $first->id, admin: self::AUTHOR, reason: 'Wrong template');
        $this->assertSame(2, (int) $this->draft()->version, 'the discarded version keeps its number, so the next one is the second');
    }

    // ---- approval -------------------------------------------------------------------------------------------------------------------------------

    public function test_a_text_that_is_still_the_template_is_not_approved(): void
    {
        $draft = $this->draft();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('still the template');

        $this->publisher()->approve(textId: $draft->id, approver: self::APPROVER);
    }

    public function test_the_author_does_not_approve_their_own_text(): void
    {
        $draft = $this->draft();
        $this->publisher()->edit(textId: $draft->id, body: 'The terms of Kuwait.', editor: self::AUTHOR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('someone other than its author');

        $this->publisher()->approve(textId: $draft->id, approver: self::AUTHOR);
    }

    public function test_a_text_that_someone_else_edited_has_that_person_as_its_author_and_the_first_author_may_approve_it(): void
    {
        $draft = $this->draft();
        $this->publisher()->edit(textId: $draft->id, body: 'The terms of Kuwait, reread.', editor: self::APPROVER);

        $this->assertSame(self::APPROVER, (int) $draft->fresh()->created_by);

        try {
            $this->publisher()->approve(textId: $draft->id, approver: self::APPROVER);
            $this->fail('The last editor approved the text.');
        } catch (RuntimeException) {
        }

        $approved = $this->publisher()->approve(textId: $draft->id, approver: self::AUTHOR);
        $this->assertSame([StorefrontLegalDocument::STATUS_APPROVED, self::AUTHOR], [$approved->status, (int) $approved->approved_by]);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_editing_an_approved_text_returns_it_to_draft_and_voids_the_approval(): void
    {
        $approved = $this->approvedText();

        $edited = $this->publisher()->edit(textId: $approved->id, body: 'The terms of Kuwait, changed after the approval.', editor: self::APPROVER);

        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, $edited->status);
        $this->assertNull($edited->approved_by);
        $this->assertNull($edited->approved_at);
        $this->assertSame(self::APPROVER, (int) $edited->created_by);
        $this->assertSame(hash('sha256', 'The terms of Kuwait, changed after the approval.'), $edited->body_hash);
    }

    public function test_only_a_draft_is_approved(): void
    {
        $approved = $this->approvedText();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only a draft is approved');

        $this->publisher()->approve(textId: $approved->id, approver: 13);
    }

    // ---- publishing -----------------------------------------------------------------------------------------------------------------------------

    public function test_an_approved_text_is_published_from_now_by_default_and_a_draft_is_not_published(): void
    {
        $draft = $this->draft();
        $this->publisher()->edit(textId: $draft->id, body: 'The terms of Kuwait.', editor: self::AUTHOR);

        try {
            $this->publisher()->publish(textId: $draft->id, publisher: self::APPROVER);
            $this->fail('A draft was published.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('only an approved text is published', $refusal->getMessage());
        }

        $this->publisher()->approve(textId: $draft->id, approver: self::APPROVER);
        $published = $this->publisher()->publish(textId: $draft->id, publisher: self::APPROVER);

        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertTrue($published->effective_from->lessThanOrEqualTo(CarbonImmutable::now()));
    }

    public function test_a_text_can_be_scheduled_for_a_later_day_and_waits(): void
    {
        $this->publishEveryRequiredText();
        $this->assertTrue($this->ready());

        $new = $this->publisher()->draftNewVersionOf(textId: (int) StorefrontLegalDocument::query()->where('document_type', 'terms')->where('locale', 'en')->value('id'), author: self::AUTHOR);
        $this->publisher()->edit(textId: $new->id, body: 'The terms of Kuwait, version 2.', editor: self::AUTHOR);
        $this->publisher()->approve(textId: $new->id, approver: self::APPROVER);
        $this->publisher()->publish(textId: $new->id, publisher: self::APPROVER, effectiveFrom: now()->addDays(10));

        $current = app(StorefrontLegalReadiness::class)->currentTextFor('KW', 'terms', 'en');
        $this->assertSame(1, (int) $current->version, 'the text in force stays in force until the new one takes effect');
        $this->assertTrue($this->ready());
    }

    /** @return array<string, array{string}> */
    public static function applicationTimeZones(): array
    {
        return ['east of UTC' => ['Asia/Dhaka'], 'west of UTC' => ['America/New_York'], 'UTC itself' => ['UTC']];
    }

    #[DataProvider('applicationTimeZones')]
    public function test_a_moment_given_in_utc_takes_effect_at_that_instant_whatever_the_time_zone_of_the_application_is(string $zone): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set($zone);
        config(['app.timezone' => $zone]);

        try {
            $this->publishEveryRequiredText();
            $first = StorefrontLegalDocument::query()->where('document_type', 'terms')->where('locale', 'en')->firstOrFail();

            $second = $this->publisher()->draftNewVersionOf(textId: $first->id, author: self::AUTHOR);
            $this->publisher()->edit(textId: $second->id, body: 'The terms of Kuwait, version 2.', editor: self::AUTHOR);
            $this->publisher()->approve(textId: $second->id, approver: self::APPROVER);
            $this->publisher()->publish(textId: $second->id, publisher: self::APPROVER, effectiveFrom: CarbonImmutable::now('UTC')->addHours(3));

            $this->assertSame(1, (int) app(StorefrontLegalReadiness::class)->currentTextFor('KW', 'terms', 'en')->version, "three hours before it takes effect, the first text is still in force ({$zone})");

            $this->travelTo(CarbonImmutable::now()->addHours(2));
            $this->assertSame(1, (int) app(StorefrontLegalReadiness::class)->currentTextFor('KW', 'terms', 'en')->version, "two hours later it is still the first ({$zone})");

            $this->travelTo(CarbonImmutable::now()->addHours(2));
            $this->assertSame(2, (int) app(StorefrontLegalReadiness::class)->currentTextFor('KW', 'terms', 'en')->version, "four hours later the second is in force ({$zone})");
        } finally {
            $this->travelBack();
            date_default_timezone_set($original);
        }
    }

    public function test_a_text_does_not_take_effect_in_the_past(): void
    {
        $approved = $this->approvedText();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not take effect in the past');

        $this->publisher()->publish(textId: $approved->id, publisher: self::APPROVER, effectiveFrom: now()->subDay());
    }

    public function test_a_text_does_not_take_effect_before_one_that_is_already_scheduled(): void
    {
        $first = $this->approvedText();
        $this->publisher()->publish(textId: $first->id, publisher: self::APPROVER, effectiveFrom: now()->addDays(30));

        $second = $this->publisher()->draftNewVersionOf(textId: $first->id, author: self::AUTHOR);
        $this->publisher()->edit(textId: $second->id, body: 'The terms of Kuwait, version 2.', editor: self::AUTHOR);
        $this->publisher()->approve(textId: $second->id, approver: self::APPROVER);

        try {
            $this->publisher()->publish(textId: $second->id, publisher: self::APPROVER, effectiveFrom: now()->addDays(2));
            $this->fail('The newer version was published to take effect before the scheduled one.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('already scheduled', $refusal->getMessage());
        }

        $published = $this->publisher()->publish(textId: $second->id, publisher: self::APPROVER, effectiveFrom: now()->addDays(31));
        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $published->status);
    }

    // ---- a published text is never edited -----------------------------------------------------------------------------------------------------

    public function test_a_published_text_is_never_edited_approved_again_or_discarded(): void
    {
        $published = $this->publishedText();
        $body = $published->body;

        foreach ([
            fn () => $this->publisher()->edit(textId: $published->id, body: 'Changed in place.', editor: self::AUTHOR),
            fn () => $this->publisher()->approve(textId: $published->id, approver: 13),
            fn () => $this->publisher()->discard(textId: $published->id, admin: self::AUTHOR, reason: 'Not wanted'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A published text was changed.');
            } catch (RuntimeException) {
            }
        }

        $this->assertSame($body, $published->fresh()->body);
        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $published->fresh()->status);
    }

    public function test_a_new_version_starts_as_a_copy_of_the_text_it_replaces_and_is_a_draft_of_its_author(): void
    {
        $published = $this->publishedText();

        $new = $this->publisher()->draftNewVersionOf(textId: $published->id, author: 14);

        $this->assertSame([2, StorefrontLegalDocument::STATUS_DRAFT, 14], [(int) $new->version, $new->status, (int) $new->created_by]);
        $this->assertSame($published->body, $new->body);
        $this->assertNull($new->template_hash);
        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $published->fresh()->status);
    }

    public function test_only_a_published_text_has_a_new_version(): void
    {
        $draft = $this->draft();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only a published text has a new version');

        $this->publisher()->draftNewVersionOf(textId: $draft->id, author: self::AUTHOR);
    }

    // ---- revoking and discarding ------------------------------------------------------------------------------------------------------------

    public function test_a_revoked_text_stays_a_row_and_closes_the_storefront_until_a_replacement_takes_effect(): void
    {
        $this->publishEveryRequiredText();
        $this->assertTrue($this->ready());
        $terms = StorefrontLegalDocument::query()->where('document_type', 'terms')->where('locale', 'en')->firstOrFail();

        $revoked = $this->publisher()->revoke(textId: $terms->id, revoker: self::APPROVER, reason: 'Wrong clause');

        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $revoked->status);
        $this->assertFalse($this->ready(), 'a revoked text in force keeps the storefront closed');

        $new = $this->publisher()->draftNewVersionOf(textId: $terms->id, author: self::AUTHOR);
        $this->publisher()->edit(textId: $new->id, body: 'The terms of Kuwait, corrected.', editor: self::AUTHOR);
        $this->publisher()->approve(textId: $new->id, approver: self::APPROVER);
        $this->assertFalse($this->ready(), 'an approved text that is not published replaces nothing');

        $this->publisher()->publish(textId: $new->id, publisher: self::APPROVER);
        $this->assertTrue($this->ready());
        $this->assertSame(2, StorefrontLegalDocument::query()->where('document_type', 'terms')->where('locale', 'en')->count(), 'nothing was deleted');
    }

    public function test_a_text_revoked_before_it_takes_effect_never_takes_effect_and_the_text_in_force_stays_in_force(): void
    {
        $this->publishEveryRequiredText();
        $terms = StorefrontLegalDocument::query()->where('document_type', 'terms')->where('locale', 'en')->firstOrFail();

        $second = $this->publisher()->draftNewVersionOf(textId: $terms->id, author: self::AUTHOR);
        $this->publisher()->edit(textId: $second->id, body: 'The terms of Kuwait, version 2.', editor: self::AUTHOR);
        $this->publisher()->approve(textId: $second->id, approver: self::APPROVER);
        $this->publisher()->publish(textId: $second->id, publisher: self::APPROVER, effectiveFrom: now()->addDays(30));

        $revoked = $this->publisher()->revoke(textId: $second->id, revoker: self::APPROVER, reason: 'Wrong clause, found before it took effect');

        $this->assertSame(StorefrontLegalDocument::STATUS_ARCHIVED, $revoked->status);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertTrue($this->ready());

        $this->travelTo(now()->addDays(31));
        $this->assertTrue($this->ready(), 'the revoked text never became the text in force, so the storefront did not close on its day');
        $this->assertSame(1, (int) app(StorefrontLegalReadiness::class)->currentTextFor('KW', 'terms', 'en')->version);

        $this->travelBack();
        $third = $this->publisher()->draftNewVersionOf(textId: $terms->id, author: self::AUTHOR);
        $this->publisher()->edit(textId: $third->id, body: 'The terms of Kuwait, version 3.', editor: self::AUTHOR);
        $this->publisher()->approve(textId: $third->id, approver: self::APPROVER);
        $this->publisher()->publish(textId: $third->id, publisher: self::APPROVER, effectiveFrom: now()->addDays(2));
        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $third->fresh()->status, 'a revoked scheduled text no longer stands in the way of a text that takes effect earlier');

        $line = collect($this->auditLines())->firstWhere('action', 'storefront.legal_text.revoked');
        $this->assertTrue($line->context['before_it_took_effect'], 'the audit line says the text was revoked before it took effect');
        $this->assertSame(StorefrontLegalDocument::STATUS_ARCHIVED, $line->after['status']);
    }

    public function test_only_a_published_text_that_still_stands_is_revoked_and_a_reason_is_required(): void
    {
        $published = $this->publishedText();

        try {
            $this->publisher()->revoke(textId: $published->id, revoker: self::APPROVER, reason: '   ');
            $this->fail('A text was revoked without a reason.');
        } catch (InvalidArgumentException) {
        }

        $this->publisher()->revoke(textId: $published->id, revoker: self::APPROVER, reason: 'Wrong clause');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a published text that is still standing');

        $this->publisher()->revoke(textId: $published->id, revoker: self::APPROVER, reason: 'Again');
    }

    public function test_a_draft_that_is_not_wanted_is_discarded_and_kept_as_history(): void
    {
        $draft = $this->draft();

        $discarded = $this->publisher()->discard(textId: $draft->id, admin: self::AUTHOR, reason: 'Wrong template');

        $this->assertSame(StorefrontLegalDocument::STATUS_ARCHIVED, $discarded->status);
        $this->assertSame(1, StorefrontLegalDocument::query()->count());
        $this->assertFalse($this->ready());
    }

    // ---- what a storefront reads ------------------------------------------------------------------------------------------------------------

    public function test_the_readiness_of_a_storefront_reads_exactly_what_the_service_wrote(): void
    {
        $this->assertFalse($this->ready());

        $this->publishEveryRequiredText();
        $this->assertTrue($this->ready());

        $this->assertFalse($this->ready('AE'), 'another storefront has no text');
    }

    public function test_a_storefront_with_one_required_text_missing_is_not_ready(): void
    {
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            foreach (config('gcc.storefronts.legal.required_locales') as $locale) {
                if ([$type, $locale] !== ['shipping', 'ar']) {
                    $this->publishedText(type: $type, locale: $locale);
                }
            }
        }

        $this->assertFalse($this->ready());

        $this->publishedText(type: 'shipping', locale: 'ar');
        $this->assertTrue($this->ready());
    }

    // ---- who and what ----------------------------------------------------------------------------------------------------------------------------

    public function test_the_default_storefront_keeps_its_documents_and_has_no_text_here(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keeps the documents it has always had');

        $this->draft(country: 'SA');
    }

    #[DataProvider('refusedInputs')]
    public function test_input_that_makes_no_sense_is_refused_and_nothing_is_written(string $country, string $type, string $locale, string $template): void
    {
        try {
            $this->publisher()->draftFromTemplate(country: $country, documentType: $type, locale: $locale, template: $template, author: self::AUTHOR);
            $this->fail('Input that makes no sense was accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, StorefrontLegalDocument::query()->count());
        $this->assertSame([], $this->auditLines());
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function refusedInputs(): array
    {
        return [
            'a country that is not a storefront' => ['XX', 'terms', 'en', self::TEMPLATE],
            'a document type with a space' => ['KW', 'terms and conditions', 'en', self::TEMPLATE],
            'a document type that is blank' => ['KW', ' ', 'en', self::TEMPLATE],
            'a locale of three letters' => ['KW', 'terms', 'eng', self::TEMPLATE],
            'a locale with a region' => ['KW', 'terms', 'ar-KW', self::TEMPLATE],
            'a blank template' => ['KW', 'terms', 'en', "  \n "],
        ];
    }

    public function test_a_step_is_taken_by_an_admin_with_an_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name the id of the admin');

        $this->publisher()->draftFromTemplate(country: 'KW', documentType: 'terms', locale: 'en', template: self::TEMPLATE, author: 0);
    }

    public function test_a_text_that_does_not_exist_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no legal text 999');

        $this->publisher()->approve(textId: 999, approver: self::APPROVER);
    }

    public function test_the_country_and_the_type_and_the_locale_are_read_in_any_case_and_kept_in_one(): void
    {
        $draft = $this->publisher()->draftFromTemplate(country: ' kw ', documentType: 'Terms', locale: 'EN', template: self::TEMPLATE, author: self::AUTHOR);

        $this->assertSame(['KW', 'terms', 'en'], [$draft->country_code, $draft->document_type, $draft->locale]);
    }

    // ---- the audit trail ---------------------------------------------------------------------------------------------------------------------

    public function test_every_step_writes_one_audit_line_with_who_what_and_the_hash_of_the_words(): void
    {
        $draft = $this->draft();
        $this->publisher()->edit(textId: $draft->id, body: 'The terms of Kuwait.', editor: self::AUTHOR);
        $this->publisher()->approve(textId: $draft->id, approver: self::APPROVER);
        $this->publisher()->publish(textId: $draft->id, publisher: self::APPROVER);
        $this->publisher()->revoke(textId: $draft->id, revoker: self::APPROVER, reason: 'Wrong clause');

        $lines = $this->auditLines();

        $this->assertSame(
            ['storefront.legal_text.drafted', 'storefront.legal_text.edited', 'storefront.legal_text.approved', 'storefront.legal_text.published', 'storefront.legal_text.revoked'],
            array_map(fn (object $line): string => $line->action, $lines),
        );
        $this->assertSame(['storefront_legal_document', (string) $draft->id], [$lines[0]->subject_type, $lines[0]->subject_id]);
        $this->assertSame([self::AUTHOR, self::AUTHOR, self::APPROVER, self::APPROVER, self::APPROVER], array_map(fn (object $line): int => $line->context['admin_id'], $lines));
        $this->assertSame(['KW', 'terms', 'en', 1], [$lines[0]->context['storefront'], $lines[0]->context['document_type'], $lines[0]->context['locale'], $lines[0]->context['version']]);
        $this->assertSame('template', $lines[0]->context['from']);
        $this->assertSame([StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::STATUS_APPROVED], [$lines[2]->before['status'], $lines[2]->after['status']]);
        $this->assertSame(hash('sha256', 'The terms of Kuwait.'), $lines[2]->after['body_hash']);
        $this->assertSame('Wrong clause', $lines[4]->context['reason']);
        $this->assertStringNotContainsString('The terms of Kuwait.', json_encode($lines), 'the words of a text are not copied into the audit trail');
    }

    public function test_a_step_whose_trace_cannot_be_written_is_not_taken(): void
    {
        $approved = $this->approvedText();
        Schema::drop('audit_logs');

        try {
            $this->publisher()->publish(textId: $approved->id, publisher: self::APPROVER);
            $this->fail('A step was taken without its trace.');
        } catch (RuntimeException) {
        }

        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, $approved->fresh()->status);
        $this->assertNull($approved->fresh()->published_at);

        try {
            $this->draft(type: 'privacy');
            $this->fail('A draft was started without its trace.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, StorefrontLegalDocument::query()->where('document_type', 'privacy')->count());
    }
}
