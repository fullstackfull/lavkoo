<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        $id = $this->draftThroughTheScreen($type, $locale);
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->post(route('admin.storefronts.legal-texts.approve', $id));
        $this->post(route('admin.storefronts.legal-texts.publish', $id));

        return $id;
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
        $this->post(route('admin.storefronts.legal-texts.approve', $id));
        $this->assertToastContains('still the template');
        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, $this->text($id)->status);

        $this->signInAs(self::LEGAL_WRITER);
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);
        $this->signInAs(self::LEGAL_APPROVER);
        $this->post(route('admin.storefronts.legal-texts.approve', $id));

        $text = $this->text($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_APPROVED, self::LEGAL_WRITER, self::LEGAL_APPROVER], [$text->status, $text->created_by, $text->approved_by]);
        $this->assertSame(self::LEGAL_APPROVER, (int) $this->auditLines('storefront.legal_text.approved')[0]->actor_id);
    }

    public function test_the_author_cannot_approve_their_own_text_even_when_their_role_holds_both_permissions(): void
    {
        $id = $this->draftThroughTheScreen();
        $this->post(route('admin.storefronts.legal-texts.edit', $id), ['body' => self::WORDS]);

        $this->post(route('admin.storefronts.legal-texts.approve', $id));

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
        $this->post(route('admin.storefronts.legal-texts.approve', $approved));
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
        $this->post(route('admin.storefronts.legal-texts.approve', $id));

        $this->post(route('admin.storefronts.legal-texts.publish', $id), ['effective_from' => '2020-01-01 00:00']);
        $this->assertToastContains('does not take effect in the past');
        $this->post(route('admin.storefronts.legal-texts.publish', $id), ['effective_from' => 'tomorrow'])->assertSessionHasErrors('effective_from');
        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, $this->text($id)->status);

        $day = CarbonImmutable::now('UTC')->addDays(3)->setTime(8, 0);
        $this->post(route('admin.storefronts.legal-texts.publish', $id), ['effective_from' => $day->format('Y-m-d H:i')]);

        $text = $this->text($id);
        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, $text->status);
        $this->assertSame($day->getTimestamp(), $text->effective_from->getTimestamp(), 'the day typed is the instant in UTC');
        $this->assertNull(app(StorefrontLegalReadiness::class)->currentTextFor('KW', 'terms', 'en'), 'a text scheduled for a later day is not in force yet');
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
        $this->post(route('admin.storefronts.legal-texts.approve', $approvedId));
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
