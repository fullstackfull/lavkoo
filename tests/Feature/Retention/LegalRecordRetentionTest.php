<?php

namespace Tests\Feature\Retention;

use App\Models\StorefrontLegalAcceptance;
use App\Models\StorefrontLegalDocument;
use App\Services\Retention\LegalRecordRetention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * I2-01 and I2-05: how long a storefront's legal texts and the acceptances of them are kept, and what may ever be removed.
 *
 * Nothing said. Both tables were created without a word about retention, which for a consent record is the first question a regulator asks,
 * and "undefined" is the worst answer because every installation then decides by accident -- one by never pruning and one by a cron
 * somebody wrote. `App\Services\Retention\LegalRecordRetention` is the definition and this is the proof of it.
 *
 * The first test is the one that protects what exists: the budget ships at 0, which means never, so an installation that has chosen nothing
 * keeps everything it keeps today.
 */
class LegalRecordRetentionTest extends TestCase
{
    use BuildsStorefrontLegalDocuments;
    use RestoresLanguageFiles;

    private const ACCEPTANCE_MIGRATION = '2026_11_24_110000_create_storefront_legal_acceptances_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStorefrontLegalDocumentsTable();
        if (!Schema::hasTable('storefront_legal_acceptances')) {
            (require database_path('migrations/' . self::ACCEPTANCE_MIGRATION))->up();
        }
        DB::table('storefront_legal_acceptances')->delete();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- the budget ships at never -----------------------------------------------------------------------------------------------------

    public function test_with_no_budget_chosen_nothing_is_removable(): void
    {
        self::assertSame(0, (int) config('gcc.retention.legal.draft_days'), 'the budget ships at 0, which means never');
        $this->giveDraft(ageInDays: 4000);

        $retention = app(LegalRecordRetention::class);

        self::assertFalse($retention->removesAnything());
        self::assertSame(0, $retention->plan()['removable_drafts']);
        self::assertSame(0, $retention->apply());
        self::assertSame(1, StorefrontLegalDocument::query()->count(), 'a four-thousand-day-old draft is still there');
    }

    // ---- rule 1: a text that was ever approved is kept for good ------------------------------------------------------------------------

    public function test_a_text_that_was_ever_approved_is_kept_for_good_whatever_the_budget_says(): void
    {
        config(['gcc.retention.legal.draft_days' => 1]);
        $published = $this->giveDocument(status: StorefrontLegalDocument::STATUS_PUBLISHED, ageInDays: 4000, extra: ['published_at' => now()->subDays(4000), 'approved_at' => now()->subDays(4000)]);
        $archived = $this->giveDocument(status: StorefrontLegalDocument::STATUS_ARCHIVED, ageInDays: 4000, extra: ['approved_at' => now()->subDays(4000)], version: 2);
        $revoked = $this->giveDocument(status: StorefrontLegalDocument::STATUS_PUBLISHED, ageInDays: 4000, extra: ['approved_at' => now()->subDays(4000), 'revoked_at' => now()->subDay()], version: 3);
        // A row that was approved and then PUT BACK to draft: the status says draft and the history says otherwise, and the history wins.
        $wasApproved = $this->giveDocument(status: StorefrontLegalDocument::STATUS_DRAFT, ageInDays: 4000, extra: ['approved_at' => now()->subDays(4000)], version: 4);

        self::assertSame(0, app(LegalRecordRetention::class)->apply());

        foreach ([$published, $archived, $revoked, $wasApproved] as $id) {
            self::assertTrue(StorefrontLegalDocument::query()->whereKey($id)->exists(), "version {$id} is still there");
        }
    }

    // ---- rule 2: the one removal these rules permit -------------------------------------------------------------------------------------

    public function test_a_never_approved_draft_past_the_budget_is_removed_and_one_inside_it_is_kept(): void
    {
        config(['gcc.retention.legal.draft_days' => 30]);
        $old = $this->giveDraft(ageInDays: 31);
        $recent = $this->giveDraft(ageInDays: 29, version: 2);

        self::assertSame(1, app(LegalRecordRetention::class)->plan()['removable_drafts']);
        self::assertSame(1, app(LegalRecordRetention::class)->apply());

        self::assertFalse(StorefrontLegalDocument::query()->whereKey($old)->exists());
        self::assertTrue(StorefrontLegalDocument::query()->whereKey($recent)->exists(), 'a draft inside the budget is nobody\'s to remove');
    }

    public function test_the_plan_changes_nothing(): void
    {
        config(['gcc.retention.legal.draft_days' => 1]);
        $this->giveDraft(ageInDays: 400);

        app(LegalRecordRetention::class)->plan();

        self::assertSame(1, StorefrontLegalDocument::query()->count(), 'reading the plan is not applying it');
    }

    // ---- rules 3 and 4: the acceptance row and the identity on it ------------------------------------------------------------------------

    public function test_an_acceptance_row_cannot_be_deleted_or_changed_by_these_rules_or_by_anything_else(): void
    {
        $acceptance = $this->giveAcceptance();

        self::assertSame(1, app(LegalRecordRetention::class)->plan()['acceptances']);
        self::assertSame(0, app(LegalRecordRetention::class)->apply(), 'the rules remove drafts and nothing else');

        try {
            $acceptance->delete();
            self::fail('an acceptance was deleted: it is the only record that a customer agreed to a text');
        } catch (RuntimeException) {
        }

        try {
            $acceptance->forceFill(['document_version' => 99])->save();
            self::fail('an acceptance was changed: a receipt that can be edited is not a receipt');
        } catch (RuntimeException) {
        }

        self::assertSame(1, (int) StorefrontLegalAcceptance::query()->whereKey($acceptance->id)->value('document_version'));
    }

    public function test_the_acceptance_holds_no_identity_of_its_own_so_closing_the_account_removes_the_person(): void
    {
        $acceptance = $this->giveAcceptance(customerId: 4242);

        $columns = array_keys($acceptance->getAttributes());

        self::assertSame(
            [],
            array_values(array_intersect($columns, ['name', 'f_name', 'l_name', 'email', 'phone', 'street_address', 'city', 'zip'])),
            'an acceptance names no person: the identity is on the customer row, which closing the account anonymises or deletes',
        );
        self::assertContains('customer_id', $columns);
        self::assertContains('document_hash', $columns, 'and what it DOES hold is the proof: the version and the hash of the text shown');
        self::assertContains('document_version', $columns);
    }

    // ---- the rules are stated in one place, and the command reads that place --------------------------------------------------------------

    public function test_every_record_this_policy_governs_has_a_stated_rule(): void
    {
        $rules = LegalRecordRetention::rules();

        self::assertSame(
            ['legal_text_ever_approved', 'legal_text_revoked', 'legal_text_draft_never_approved', 'legal_acceptance_row', 'legal_acceptance_identity'],
            array_keys($rules),
            'a record governed by this policy with no rule beside it is the state this whole file exists to end',
        );
        foreach ($rules as $record => $rule) {
            self::assertNotSame('', trim($rule), "the rule for {$record} says nothing");
        }
    }

    public function test_the_command_reports_without_changing_anything_and_applies_only_when_asked(): void
    {
        config(['gcc.retention.legal.draft_days' => 30]);
        $this->giveDraft(ageInDays: 400);

        $this->artisan('gcc:legal:retention')
            ->expectsOutputToContain('never deleted and never changed')
            ->expectsOutputToContain('drafts the rules would remove now: 1')
            ->assertExitCode(0);

        self::assertSame(1, StorefrontLegalDocument::query()->count(), 'the default run changed nothing');

        $this->artisan('gcc:legal:retention', ['--apply' => true])->assertExitCode(0);

        self::assertSame(0, StorefrontLegalDocument::query()->count());
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------------------

    private function giveDraft(int $ageInDays, int $version = 1): int
    {
        return $this->giveDocument(status: StorefrontLegalDocument::STATUS_DRAFT, ageInDays: $ageInDays, version: $version);
    }

    private function giveDocument(string $status, int $ageInDays, array $extra = [], int $version = 1): int
    {
        return (int) DB::table('storefront_legal_documents')->insertGetId([
            'country_code' => 'SA',
            'document_type' => 'terms',
            'locale' => 'en',
            'version' => $version,
            'status' => $status,
            'body' => 'the text',
            'body_hash' => hash('sha256', 'the text'),
            'created_at' => now()->subDays($ageInDays),
            'updated_at' => now()->subDays($ageInDays),
        ] + $extra);
    }

    private function giveAcceptance(?int $customerId = 7): StorefrontLegalAcceptance
    {
        return StorefrontLegalAcceptance::query()->create([
            'storefront_country' => 'SA',
            'document_type' => 'terms',
            'locale' => 'en',
            'legal_document_id' => $this->giveDocument(status: StorefrontLegalDocument::STATUS_PUBLISHED, ageInDays: 10, extra: ['published_at' => now()->subDays(10), 'approved_at' => now()->subDays(10)], version: 9),
            'document_version' => 1,
            'document_hash' => hash('sha256', 'the text'),
            'customer_id' => $customerId,
            'accepted_at' => now()->subDay(),
        ]);
    }
}
