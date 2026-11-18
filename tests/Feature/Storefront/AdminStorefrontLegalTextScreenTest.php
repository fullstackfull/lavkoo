<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalPublisher;
use App\Services\Storefront\StorefrontLegalReadiness;
use App\Services\Storefront\StorefrontLegalTextBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * I2-03: the screen where the legal text of a storefront is written, approved, published and revoked. The routes, the role check, the requests, the publisher and the templates are the real
 * ones. What is pinned: who may open the page and who may take which step (writing and approving are two permissions, and a person who holds both is still refused as the approver of their own
 * text), that the person of every step is the admin who is signed in, that a text is cleaned of anything that could run before it is kept, that the day is read as UTC, and that every step leaves
 * its audit line. The texts are placeholders and no text is legal advice.
 */
class AdminStorefrontLegalTextScreenTest extends AdminStorefrontScreenTestCase
{
    private const TEMPLATE = '<p>TEMPLATE: replace this with the terms of the country.</p>';

    private const WORDS = '<p>The terms of Kuwait.</p>';

    private function page(string $country = 'KW'): string
    {
        return $this->get(route('admin.storefronts.legal-texts.index', $country))->assertOk()->getContent();
    }

    /** A draft written through the screen by the admin who is signed in; its number. */
    private function draftThroughTheScreen(string $type = 'terms', string $locale = 'en', string $template = self::TEMPLATE): int
    {
        $this->post(route('admin.storefronts.legal-texts.draft', 'KW'), ['document_type' => $type, 'locale' => $locale, 'template' => $template])->assertRedirect();

        return (int) StorefrontLegalDocument::query()->where('country_code', 'KW')->where('document_type', $type)->where('locale', $locale)->orderByDesc('id')->value('id');
    }

    /** A text that is edited, approved by someone else and published, all through the screen. */
    private function publishedThroughTheScreen(string $type = 'terms', string $locale = 'en'): int
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen(type: $type, locale: $locale);
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($id);
        $this->publishThroughTheScreen($id);

        return $id;
    }

    /** The approval of a text as its form sends it: with the hash of the words the person read, which here are the words it holds now. */
    private function approveThroughTheScreen(int $id): TestResponse
    {
        return $this->post(route('admin.storefronts.legal-texts.approve', $id), ['body_hash' => $this->text($id)->body_hash]);
    }

    /** The publication of a text as its form sends it: from the day given (none is now), with the hash of the words it holds now. */
    private function publishThroughTheScreen(int $id, array $form = []): TestResponse
    {
        return $this->post(route('admin.storefronts.legal-texts.publish', $id), ['body_hash' => $this->text($id)->body_hash] + $form);
    }

    /** The hash a person's approve or publish form carries, read from the page the way a browser would post it. */
    private function hashOnTheFormOf(string $page, string $route, int $id): string
    {
        $pattern = '#<form[^>]*action="' . preg_quote(route($route, $id), '#') . '"[^>]*>(?:(?!</form>).)*?<input type="hidden" name="body_hash" value="([^"]*)"#s';
        $this->assertSame(1, preg_match($pattern, $page, $found), "the form of {$route} for text {$id} carries a body_hash");

        return $found[1];
    }

    private function text(int $id): StorefrontLegalDocument
    {
        return StorefrontLegalDocument::query()->findOrFail($id);
    }

    // ---- who may open the page --------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{int, bool}> */
    public static function adminsAndTheirAccessToTheLegalTexts(): array
    {
        return [
            'the master admin' => [self::MASTER, true],
            'a legal writer' => [self::LEGAL_WRITER, true],
            'a legal approver' => [self::LEGAL_APPROVER, true],
            'a role that holds only the lifecycle' => [self::LIFECYCLE_ADMIN, false],
            'a role that holds another module' => [self::CATALOG_ADMIN, false],
            'a role that holds all three but is switched off' => [self::ADMIN_OF_A_SWITCHED_OFF_ROLE, false],
        ];
    }

    #[DataProvider('adminsAndTheirAccessToTheLegalTexts')]
    public function test_only_a_writer_or_an_approver_opens_the_legal_texts(int $admin, bool $mayOpen): void
    {
        $this->signInAs($admin);

        $response = $this->get(route('admin.storefronts.legal-texts.index', 'KW'));

        $mayOpen ? $response->assertOk() : $response->assertRedirect(route('admin.dashboard.index'));
    }

    // ---- writing ----------------------------------------------------------------------------------------------------------------------------

    public function test_a_writer_drafts_a_text_from_a_template_and_it_is_neither_approved_nor_published_and_the_step_is_audited(): void
    {
        $this->signInAs(self::LEGAL_WRITER);

        $id = $this->draftThroughTheScreen();

        $text = $this->text($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_DRAFT, self::LEGAL_WRITER, null], [$text->status, $text->created_by, $text->approved_by]);
        $this->assertSame($text->template_hash, $text->body_hash, 'the copy is the template and says so');
        $lines = $this->auditLines('storefront.legal_text.drafted');
        $this->assertCount(1, $lines);
        $this->assertSame([self::LEGAL_WRITER, 'Legal writer'], [(int) $lines[0]->actor_id, $lines[0]->actor_name]);
    }

    public function test_a_text_that_is_still_its_template_is_not_approved_and_an_edited_one_is_approved_by_someone_else(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();

        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($id);
        $this->assertToastContains('still the template');
        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, $this->text($id)->status);

        $this->signInAs(self::LEGAL_WRITER);
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($id);

        $text = $this->text($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_APPROVED, self::LEGAL_WRITER, self::LEGAL_APPROVER], [$text->status, $text->created_by, $text->approved_by]);
        $this->assertSame(self::LEGAL_APPROVER, (int) $this->auditLines('storefront.legal_text.approved')[0]->actor_id);
    }

    public function test_the_author_cannot_approve_their_own_text_even_when_their_role_holds_both_permissions(): void
    {
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);

        $this->approveThroughTheScreen($id);

        $this->assertToastContains('someone other than its author');
        $this->assertSame([StorefrontLegalDocument::STATUS_DRAFT, null], [$this->text($id)->status, $this->text($id)->approved_by]);

        $page = $this->page();
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*' . preg_quote(e(translate('approve_the_text')), '/') . '/', $page, 'the page does not offer the step the author cannot take');
    }

    public function test_a_second_writer_becomes_the_author_of_what_they_edit_and_so_cannot_approve_it(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);

        $this->signInAs(self::OTHER_LEGAL_WRITER);
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => '<p>The terms of Kuwait, changed.</p>']);

        $this->assertSame(self::OTHER_LEGAL_WRITER, $this->text($id)->created_by);
    }

    // ---- the permissions of each step -------------------------------------------------------------------------------------------------------

    public function test_a_writer_cannot_approve_publish_or_revoke_and_an_approver_cannot_write_edit_discard_or_start_a_version(): void
    {
        $draft = $this->draftThroughTheScreen(type: 'terms');
        $this->post(route('admin.storefronts.legal-texts.edit', $draft), ['body' => self::WORDS]);
        $approved = $this->draftThroughTheScreen(type: 'privacy');
        $this->post(route('admin.storefronts.legal-texts.edit', $approved), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($approved);
        $published = $this->publishedThroughTheScreen(type: 'refund');
        $linesBefore = DB::table('audit_logs')->count();
        $statuses = fn (): array => StorefrontLegalDocument::query()->orderBy('id')->pluck('status', 'id')->all();
        $before = $statuses();

        $this->signInAs(self::LEGAL_WRITER);
        foreach ([
            route('admin.storefronts.legal-texts.approve', $draft) => [],
            route('admin.storefronts.legal-texts.publish', $approved) => [],
            route('admin.storefronts.legal-texts.revoke', $published) => ['reason' => 'No longer right'],
        ] as $url => $payload) {
            $this->post($url, $payload)->assertRedirect(route('admin.dashboard.index'));
        }

        $this->signInAs(self::LEGAL_APPROVER);
        foreach ([
            route('admin.storefronts.legal-texts.draft', 'KW') => ['document_type' => 'shipping', 'locale' => 'en', 'template' => self::TEMPLATE],
            route('admin.storefronts.legal-texts.edit', $draft) => ['body' => '<p>Changed by an approver.</p>'],
            route('admin.storefronts.legal-texts.discard', $draft) => ['reason' => 'Not wanted'],
            route('admin.storefronts.legal-texts.new-version', $published) => [],
        ] as $url => $payload) {
            $this->post($url, $payload)->assertRedirect(route('admin.dashboard.index'));
        }

        $this->assertSame($before, $statuses());
        $this->assertSame($linesBefore, DB::table('audit_logs')->count());
        $this->assertSame(self::WORDS, $this->text($draft)->body);
    }

    public function test_the_page_offers_each_admin_only_the_steps_of_their_permission(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);

        $writerSees = $this->page();
        $this->assertStringContainsString(route('admin.storefronts.legal-texts.edit', $id), $writerSees);
        $this->assertStringContainsString(route('admin.storefronts.legal-texts.draft', 'KW'), $writerSees);
        $this->assertStringNotContainsString(route('admin.storefronts.legal-texts.approve', $id), $writerSees);

        $this->signInAs(self::LEGAL_APPROVER);
        $approverSees = $this->page();
        $this->assertStringContainsString(route('admin.storefronts.legal-texts.approve', $id), $approverSees);
        $this->assertStringNotContainsString(route('admin.storefronts.legal-texts.edit', $id), $approverSees);
        $this->assertStringNotContainsString(route('admin.storefronts.legal-texts.draft', 'KW'), $approverSees);
    }

    // ---- publishing and revoking ------------------------------------------------------------------------------------------------------------

    public function test_a_text_is_published_from_a_day_read_as_utc_and_never_from_the_past(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($id);

        $this->publishThroughTheScreen(id: $id, form: ['effective_from' => '2020-01-01 00:00']);
        $this->assertToastContains('does not take effect in the past');
        $this->publishThroughTheScreen(id: $id, form: ['effective_from' => 'tomorrow'])->assertSessionHasErrors('effective_from');
        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, $this->text($id)->status);

        $day = CarbonImmutable::now('UTC')->addDays(3)->setTime(8, 0);
        $this->publishThroughTheScreen(id: $id, form: ['effective_from' => $day->format('Y-m-d H:i')]);

        $text = $this->text($id);
        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $text->status);
        $this->assertSame($day->getTimestamp(), $text->effective_from->getTimestamp(), 'the day typed is the instant in UTC');
        $this->assertNull(app(StorefrontLegalReadiness::class)->currentTextFor('KW', 'terms', 'en'), 'a text scheduled for a later day is not in force yet');
    }

    /** @return array<string, array{string}> */
    public static function daysAfterTheLastMomentTheDatabaseKeeps(): array
    {
        return ['the first minute after it' => ['2038-01-01 00:00'], 'a year after it' => ['2038-12-31 23:59'], 'far after it' => ['9999-12-31 23:59']];
    }

    #[DataProvider('daysAfterTheLastMomentTheDatabaseKeeps')]
    public function test_a_day_after_the_end_of_2037_is_a_validation_error_not_a_database_failure_and_the_last_minute_is_accepted(string $day): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($id);
        $linesBefore = DB::table('audit_logs')->count();

        $this->publishThroughTheScreen(id: $id, form: ['effective_from' => $day])->assertSessionHasErrors(['effective_from' => translate('the_day_is_too_late_the_latest_moment_a_text_can_take_effect_utc') . ': ' . StorefrontLegalPublisher::LATEST_EFFECTIVE_MOMENT_UTC]);

        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, $this->text($id)->status);
        $this->assertSame($linesBefore, DB::table('audit_logs')->count());

        $this->publishThroughTheScreen(id: $id, form: ['effective_from' => '2037-12-31 23:59'])->assertSessionHasNoErrors();

        $text = $this->text($id);
        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $text->status);
        $this->assertSame(CarbonImmutable::parse('2037-12-31 23:59', 'UTC')->getTimestamp(), $text->effective_from->getTimestamp(), 'the last minute of 2037 is kept as the instant that was typed');
    }

    public function test_the_days_of_the_page_are_in_utc_with_the_suffix_whatever_the_time_zone_of_the_application_is(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Asia/Dhaka');
        config(['app.timezone' => 'Asia/Dhaka']);

        try {
            $day = CarbonImmutable::now('UTC')->addDays(2)->setTime(7, 30);
            $this->signInAs(self::LEGAL_WRITER);
            $id = $this->draftThroughTheScreen();
            $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
            $this->signInAs(self::LEGAL_APPROVER);
            $this->approveThroughTheScreen($id);
            $this->publishThroughTheScreen(id: $id, form: ['effective_from' => $day->format('Y-m-d H:i')]);
            $this->travelTo($day->addHour());
            $this->signInAs(self::MASTER);

            $page = $this->page();

            $this->assertStringContainsString('data-required="terms.en" data-in-force="yes"', $page, 'the text is in force on its day');
            $this->assertSame(2, substr_count($page, $day->format('Y-m-d H:i') . ' UTC'), 'the table of what is in force and the list of versions both print the UTC minute that was typed, with its suffix');
            $this->assertStringNotContainsString($day->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'), $page, 'the application\'s own time zone is not printed as if it were the day that was typed');
        } finally {
            $this->travelBack();
            date_default_timezone_set($original);
        }
    }

    public function test_a_text_published_without_a_day_is_in_force_now_and_the_page_says_so(): void
    {
        $this->publishedThroughTheScreen();
        $this->signInAs(self::MASTER);

        $page = $this->page();

        $this->assertStringContainsString('data-required="terms.en" data-in-force="yes"', $page);
        $this->assertStringContainsString('data-required="terms.ar" data-in-force="no"', $page);
        $this->assertSame(self::LEGAL_APPROVER, (int) $this->auditLines('storefront.legal_text.published')[0]->actor_id);
    }

    public function test_revoking_needs_a_reason_and_a_storefront_that_sold_stops_selling(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW', activated: false);
        $this->assertTrue(app(StorefrontLegalReadiness::class)->isReady('KW'));
        $id = (int) StorefrontLegalDocument::query()->where('country_code', 'KW')->where('document_type', 'terms')->where('locale', 'en')->value('id');
        $this->signInAs(self::LEGAL_APPROVER);

        $this->post(route('admin.storefronts.legal-texts.revoke', $id))->assertSessionHasErrors('reason');
        $this->assertNull($this->text($id)->revoked_at);

        $this->post(route('admin.storefronts.legal-texts.revoke', $id), ['reason' => 'A clause is wrong']);

        $this->assertNotNull($this->text($id)->revoked_at);
        $this->assertFalse(app(StorefrontLegalReadiness::class)->isReady('KW'), 'a revoked text in force closes the storefront');
        $line = $this->auditLines('storefront.legal_text.revoked')[0];
        $this->assertSame(self::LEGAL_APPROVER, (int) $line->actor_id);
    }

    // ---- an approval and a publication are bound to the words that were read ---------------------------------------------------------------

    public function test_the_forms_carry_the_whole_hash_of_the_text_and_the_post_of_the_page_as_it_was_rendered_takes_the_step_in_either_letter_case(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);

        $hashOnTheApproveForm = $this->hashOnTheFormOf(page: $this->page(), route: 'admin.storefronts.legal-texts.approve', id: $id);
        $this->assertSame($this->text($id)->body_hash, $hashOnTheApproveForm, 'the form carries the whole hash, not the twelve characters the page shows');
        $this->assertSame(64, strlen($hashOnTheApproveForm));
        $this->post(route('admin.storefronts.legal-texts.approve', $id), ['body_hash' => strtoupper($hashOnTheApproveForm)])->assertRedirect();
        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, $this->text($id)->status);

        $hashOnThePublishForm = $this->hashOnTheFormOf(page: $this->page(), route: 'admin.storefronts.legal-texts.publish', id: $id);
        $this->assertSame($this->text($id)->body_hash, $hashOnThePublishForm);
        $this->post(route('admin.storefronts.legal-texts.publish', $id), ['body_hash' => $hashOnThePublishForm])->assertRedirect();

        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $this->text($id)->status);
        $this->assertCount(1, $this->auditLines('storefront.legal_text.approved'));
        $this->assertCount(1, $this->auditLines('storefront.legal_text.published'));
    }

    public function test_an_approval_posted_from_a_page_that_showed_other_words_is_refused_and_nothing_is_approved_or_audited(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $hashThatWasRead = $this->hashOnTheFormOf(page: $this->page(), route: 'admin.storefronts.legal-texts.approve', id: $id);

        $this->signInAs(self::LEGAL_WRITER);
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => '<p>The terms of Kuwait, changed after the approver read them.</p>']);
        $linesBefore = DB::table('audit_logs')->count();
        $this->signInAs(self::LEGAL_APPROVER);

        $this->post(route('admin.storefronts.legal-texts.approve', $id), ['body_hash' => $hashThatWasRead])->assertRedirect();

        $this->assertToastContains('changed after it was read');
        $text = $this->text($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_DRAFT, null, null], [$text->status, $text->approved_by, $text->approved_at]);
        $this->assertCount(0, $this->auditLines('storefront.legal_text.approved'));
        $this->assertSame($linesBefore, DB::table('audit_logs')->count(), 'a refused approval leaves no audit line');

        $this->post(route('admin.storefronts.legal-texts.approve', $id), ['body_hash' => $this->hashOnTheFormOf(page: $this->page(), route: 'admin.storefronts.legal-texts.approve', id: $id)]);

        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, $this->text($id)->status, 'read again, the words are approved');
        $this->assertCount(1, $this->auditLines('storefront.legal_text.approved'));
    }

    public function test_a_publication_posted_from_a_page_that_showed_other_words_is_refused_and_nothing_is_published_or_audited(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($id);
        $hashThatWasRead = $this->hashOnTheFormOf(page: $this->page(), route: 'admin.storefronts.legal-texts.publish', id: $id);

        $this->signInAs(self::LEGAL_WRITER);
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => '<p>The terms of Kuwait, changed after the approval was read.</p>']);
        $linesBefore = DB::table('audit_logs')->count();
        $this->signInAs(self::LEGAL_APPROVER);

        $this->post(route('admin.storefronts.legal-texts.publish', $id), ['body_hash' => $hashThatWasRead])->assertRedirect();
        $this->assertToastContains('changed after it was read');
        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, $this->text($id)->status, 'the edit sent the approved text back to draft and the stale post did not publish it');

        $this->approveThroughTheScreen($id);
        $this->post(route('admin.storefronts.legal-texts.publish', $id), ['body_hash' => $hashThatWasRead])->assertRedirect();

        $text = $this->text($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_APPROVED, null, null], [$text->status, $text->published_at, $text->effective_from], 'approved again by someone else, the words are still not the ones the publisher read');
        $this->assertCount(0, $this->auditLines('storefront.legal_text.published'));
        $this->assertSame($linesBefore + 1, DB::table('audit_logs')->count(), 'only the second approval was audited');

        $this->publishThroughTheScreen($id);

        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $this->text($id)->status);
        $this->assertCount(1, $this->auditLines('storefront.legal_text.published'));
    }

    /** @return array<string, array{mixed}> */
    public static function hashesThatAreNotTheHashOfAText(): array
    {
        $hash = hash('sha256', 'The terms of Kuwait.');

        return [
            'none' => [null],
            'an empty one' => [''],
            'the twelve characters the page shows' => [substr($hash, 0, 12)],
            'sixty-three characters' => [substr($hash, 0, 63)],
            'sixty-five characters' => [$hash . '0'],
            'sixty-four characters that are not hex' => [str_repeat('g', 64)],
            'a list' => [[$hash]],
        ];
    }

    #[DataProvider('hashesThatAreNotTheHashOfAText')]
    public function test_approving_or_publishing_without_the_hash_of_a_text_is_a_validation_error_and_changes_nothing(mixed $hash): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $draft = $this->draftThroughTheScreen(type: 'terms');
        $this->post(route('admin.storefronts.legal-texts.edit', $draft), ['body' => self::WORDS]);
        $approved = $this->draftThroughTheScreen(type: 'privacy');
        $this->post(route('admin.storefronts.legal-texts.edit', $approved), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($approved);
        $linesBefore = DB::table('audit_logs')->count();
        $statuses = fn (): array => StorefrontLegalDocument::query()->orderBy('id')->pluck('status', 'id')->all();
        $before = $statuses();
        $form = $hash === null ? [] : ['body_hash' => $hash];

        $this->post(route('admin.storefronts.legal-texts.approve', $draft), $form)->assertSessionHasErrors('body_hash');
        $this->post(route('admin.storefronts.legal-texts.publish', $approved), $form)->assertSessionHasErrors('body_hash');

        $this->assertSame($before, $statuses());
        $this->assertSame($linesBefore, DB::table('audit_logs')->count());
    }

    // ---- versions and putting a draft away --------------------------------------------------------------------------------------------------

    public function test_a_new_version_starts_as_a_draft_and_the_published_text_stays_in_force(): void
    {
        $published = $this->publishedThroughTheScreen();
        $this->signInAs(self::LEGAL_WRITER);

        $this->post(route('admin.storefronts.legal-texts.new-version', $published));

        $new = StorefrontLegalDocument::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame([StorefrontLegalDocument::STATUS_DRAFT, 2, self::LEGAL_WRITER], [$new->status, $new->version, $new->created_by]);
        $this->assertSame(1, (int) app(StorefrontLegalReadiness::class)->currentTextFor('KW', 'terms', 'en')->version);
    }

    public function test_a_new_version_is_offered_only_on_the_newest_published_text_of_its_type_and_language_and_revoking_only_on_a_text_that_is_not_revoked(): void
    {
        $this->publishLegalDocument(country: 'KW', type: 'terms', locale: 'en', overrides: ['version' => 1, 'effective_from' => now()->subDays(3)]);
        $this->publishLegalDocument(country: 'KW', type: 'terms', locale: 'en', overrides: ['version' => 2]);
        $this->publishLegalDocument(country: 'KW', type: 'privacy', locale: 'en', overrides: ['version' => 1, 'effective_from' => now()->subDays(3)]);
        $this->publishLegalDocument(country: 'KW', type: 'privacy', locale: 'en', overrides: ['version' => 2, 'revoked_at' => now()]);
        $this->publishLegalDocument(country: 'KW', type: 'refund', locale: 'en', overrides: ['version' => 1]);
        $this->publishLegalDocument(country: 'KW', type: 'refund', locale: 'en', overrides: ['version' => 2, 'status' => StorefrontLegalDocument::STATUS_ARCHIVED, 'effective_from' => now()->addDay(), 'revoked_at' => now()]);
        $this->publishLegalDocument(country: 'KW', type: 'terms', locale: 'ar', overrides: ['status' => StorefrontLegalDocument::STATUS_DRAFT, 'approved_by' => null, 'approved_at' => null, 'published_at' => null, 'effective_from' => null]);
        $this->publishLegalDocument(country: 'KW', type: 'privacy', locale: 'ar', overrides: ['status' => StorefrontLegalDocument::STATUS_APPROVED, 'published_at' => null, 'effective_from' => null]);
        $this->publishLegalDocument(country: 'KW', type: 'refund', locale: 'ar', overrides: ['version' => 1, 'effective_from' => now()->subDays(3)]);
        $this->publishLegalDocument(country: 'KW', type: 'refund', locale: 'ar', overrides: ['version' => 2]);
        $this->publishLegalDocument(country: 'KW', type: 'shipping', locale: 'en', overrides: ['version' => 1, 'effective_from' => now()->subDays(3)]);
        $this->publishLegalDocument(country: 'KW', type: 'shipping', locale: 'en', overrides: ['version' => 2]);
        $this->publishLegalDocument(country: 'KW', type: 'shipping', locale: 'ar', overrides: ['version' => 1]);

        $steps = [];
        foreach (app(StorefrontLegalTextBoard::class)->of('KW')['texts'] as $entry) {
            $steps[$entry['text']->id] = [$entry['text']->document_type . '.' . $entry['text']->locale . '.v' . $entry['text']->version, $entry['steps']];
        }
        $byText = array_column($steps, 1, 0);
        ksort($byText);

        $this->assertSame([
            'privacy.ar.v1' => [StorefrontLegalTextBoard::EDIT, StorefrontLegalTextBoard::PUBLISH, StorefrontLegalTextBoard::DISCARD],
            'privacy.en.v1' => [StorefrontLegalTextBoard::REVOKE],
            'privacy.en.v2' => [],
            'refund.ar.v1' => [StorefrontLegalTextBoard::REVOKE],
            'refund.ar.v2' => [StorefrontLegalTextBoard::REVOKE, StorefrontLegalTextBoard::NEW_VERSION],
            'refund.en.v1' => [StorefrontLegalTextBoard::REVOKE, StorefrontLegalTextBoard::NEW_VERSION],
            'refund.en.v2' => [],
            'shipping.ar.v1' => [StorefrontLegalTextBoard::REVOKE, StorefrontLegalTextBoard::NEW_VERSION],
            'shipping.en.v1' => [StorefrontLegalTextBoard::REVOKE],
            'shipping.en.v2' => [StorefrontLegalTextBoard::REVOKE, StorefrontLegalTextBoard::NEW_VERSION],
            'terms.ar.v1' => [StorefrontLegalTextBoard::EDIT, StorefrontLegalTextBoard::APPROVE, StorefrontLegalTextBoard::DISCARD],
            'terms.en.v1' => [StorefrontLegalTextBoard::REVOKE],
            'terms.en.v2' => [StorefrontLegalTextBoard::REVOKE, StorefrontLegalTextBoard::NEW_VERSION],
        ], $byText, 'revoking is offered on a published text that is not revoked; a new version on the newest published text of its type and language when it is not revoked (an older one, a revoked one and one archived before it took effect have none); the newest is read for each language on its own: refund is ahead in Arabic and shipping in English, and neither takes the new version of the other language away');

        $page = $this->page();
        foreach ($steps as $id => [$name, $offered]) {
            foreach ([StorefrontLegalTextBoard::NEW_VERSION => 'admin.storefronts.legal-texts.new-version', StorefrontLegalTextBoard::REVOKE => 'admin.storefronts.legal-texts.revoke'] as $step => $route) {
                in_array($step, $offered, true)
                    ? $this->assertStringContainsString(route($route, $id), $page, "{$name} is offered {$step}")
                    : $this->assertStringNotContainsString(route($route, $id), $page, "{$name} is not offered {$step}");
            }
        }
    }

    public function test_a_draft_is_put_away_with_a_reason_and_kept_as_history(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();

        $this->post(route('admin.storefronts.legal-texts.discard', $id))->assertSessionHasErrors('reason');
        $this->post(route('admin.storefronts.legal-texts.discard', $id), ['reason' => 'Wrong template']);

        $this->assertSame(StorefrontLegalDocument::STATUS_ARCHIVED, $this->text($id)->status);
        $this->assertSame(1, StorefrontLegalDocument::query()->count(), 'nothing is deleted');
        $this->assertStringContainsString('data-text="' . $id . '" data-status="archived"', $this->page());
    }

    // ---- what is typed ----------------------------------------------------------------------------------------------------------------------

    public function test_whatever_could_run_is_removed_from_a_text_before_it_is_kept_and_the_page_shows_the_text_escaped(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $hostile = '<p>Terms</p><script>alert(1)</script><img src="https://example.test/a.png" onerror="alert(2)"><a href="javascript:alert(3)">link</a><iframe src="https://example.test"></iframe>';

        $id = $this->draftThroughTheScreen(template: $hostile);
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => $hostile . '<p>More terms</p>']);

        $kept = $this->text($id)->body;
        $this->assertStringContainsString('<p>Terms</p>', $kept);
        $this->assertStringContainsString('<p>More terms</p>', $kept);
        foreach (['<script', 'alert(1)', 'onerror', 'javascript:', '<iframe'] as $dangerous) {
            $this->assertStringNotContainsString($dangerous, $kept, "{$dangerous} is not kept");
        }
        $this->assertStringNotContainsString('<script', $this->page());
    }

    public function test_a_text_that_is_only_markup_that_is_removed_is_refused_as_blank(): void
    {
        $this->signInAs(self::LEGAL_WRITER);
        $id = $this->draftThroughTheScreen();

        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => '<script>alert(1)</script>']);

        $this->assertToastContains('The text is blank');
        $this->assertSame(self::TEMPLATE, $this->text($id)->body);
    }

    public function test_a_draft_with_a_type_or_a_language_that_is_not_one_is_refused_before_it_reaches_the_publisher(): void
    {
        $this->signInAs(self::LEGAL_WRITER);

        $this->post(route('admin.storefronts.legal-texts.draft', 'KW'), ['document_type' => 'Terms And Conditions', 'locale' => 'en', 'template' => self::TEMPLATE])->assertSessionHasErrors('document_type');
        $this->post(route('admin.storefronts.legal-texts.draft', 'KW'), ['document_type' => 'terms', 'locale' => 'english', 'template' => self::TEMPLATE])->assertSessionHasErrors('locale');
        $this->post(route('admin.storefronts.legal-texts.draft', 'KW'), ['document_type' => 'terms', 'locale' => 'en'])->assertSessionHasErrors('template');

        $this->assertSame(0, StorefrontLegalDocument::query()->count());
    }

    // ---- the storefronts it is for ----------------------------------------------------------------------------------------------------------

    public function test_the_default_storefront_keeps_its_documents_and_nothing_is_written_for_it(): void
    {
        $page = $this->page('SA');

        $this->assertStringContainsString(e(translate('this_storefront_keeps_the_documents_it_has_always_had_its_texts_are_not_written_here')), $page);
        $this->assertStringNotContainsString('<form', $page);

        $this->post(route('admin.storefronts.legal-texts.draft', 'SA'), ['document_type' => 'terms', 'locale' => 'en', 'template' => self::TEMPLATE]);

        $this->assertToastContains('keeps the documents it has always had');
        $this->assertSame(0, StorefrontLegalDocument::query()->count());
    }

    public function test_a_country_that_is_not_a_storefront_has_no_page(): void
    {
        $this->get(route('admin.storefronts.legal-texts.index', 'ZZ'))->assertNotFound();
        $this->get(route('admin.storefronts.legal-texts.index', 'KWT'))->assertNotFound();
    }

    public function test_the_page_says_so_when_the_table_of_texts_does_not_exist(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('storefront_legal_documents');

        $this->get(route('admin.storefronts.legal-texts.index', 'KW'))->assertOk()->assertSee(e(translate('the_table_of_legal_texts_does_not_exist_yet_run_the_migrations')), false);
    }

    // ---- every screen in every language the panel is translated into ------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function languagesOfThePanel(): array
    {
        return ['English' => ['en'], 'Arabic (Saudi Arabia)' => ['sa'], 'Arabic (UAE)' => ['ae']];
    }

    #[DataProvider('languagesOfThePanel')]
    public function test_both_screens_in_every_state_read_in_each_language_without_an_untranslated_phrase(string $language): void
    {
        $this->withSession(['local' => $language]);
        $this->publishedThroughTheScreen(type: 'refund');
        $this->signInAs(self::LEGAL_WRITER);
        $drafted = $this->draftThroughTheScreen(type: 'terms');
        $this->post(route('admin.storefronts.legal-texts.edit', $drafted), ['body' => self::WORDS]);
        $approvedId = $this->draftThroughTheScreen(type: 'privacy');
        $this->post(route('admin.storefronts.legal-texts.edit', $approvedId), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->approveThroughTheScreen($approvedId);
        $this->signInAs(self::MASTER);
        $this->post(route('admin.storefronts.legal-texts.revoke', $this->textIdOf('refund')), ['reason' => 'A clause is wrong']);
        $this->meetThePrerequisitesOf('AE');
        $this->post(route('admin.storefronts.ready', 'AE'), ['reason' => 'Ready']);
        $this->post(route('admin.storefronts.activate', 'AE'), ['reason' => 'Open', 'reference' => 'Minutes']);
        $this->post(route('admin.storefronts.pause', 'AE'), ['mode' => 'redirect', 'reason' => 'Stock count', 'message' => 'Back soon.', 'until' => now('UTC')->addDay()->format('Y-m-d H:i')]);

        foreach (['KW', 'AE', 'SA'] as $country) {
            $this->get(route('admin.storefronts.legal-texts.index', $country))->assertOk();
        }
        $this->get(route('admin.storefronts.index'))->assertOk();
        $this->signInAs(self::LEGAL_WRITER);
        $this->get(route('admin.storefronts.legal-texts.index', 'KW'))->assertOk();
        $this->signInAs(self::LEGAL_APPROVER);
        $this->get(route('admin.storefronts.legal-texts.index', 'KW'))->assertOk();
    }

    private function textIdOf(string $type): int
    {
        return (int) StorefrontLegalDocument::query()->where('document_type', $type)->orderBy('id')->value('id');
    }
}
