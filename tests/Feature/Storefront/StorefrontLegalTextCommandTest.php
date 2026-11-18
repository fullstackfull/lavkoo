<?php

namespace Tests\Feature\Storefront;

use App\Models\StorefrontLegalDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * gcc:storefront:legal-text is the entry point of the legal text publisher until an admin screen exists: it names the admin who takes each step, refuses an admin who does not
 * exist, reads a template or a text from a file, says what it did and what is still needed, lists the texts without their words, and refuses what the service refuses with the reason
 * in words and a failing exit code. It works while the switches are off.
 */
class StorefrontLegalTextCommandTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsStorefrontLegalDocuments;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        DB::table('storefront_legal_documents')->truncate(); // the messages name the number of a text, and a real engine keeps counting from the last test
        Schema::dropIfExists('audit_logs');
        $this->buildAuditLogTable();
        Schema::dropIfExists('admins');
        Schema::create('admins', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        DB::table('admins')->insert([['id' => 11, 'name' => 'Author'], ['id' => 12, 'name' => 'Approver']]);

        $this->directory = sys_get_temp_dir() . '/legal-text-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('admins');

        parent::tearDown();
    }

    private function file(string $name, string $content): string
    {
        $path = "{$this->directory}/{$name}";
        file_put_contents($path, $content);

        return $path;
    }

    private function text(string $action, array $options = []): PendingCommand
    {
        return $this->artisan('gcc:storefront:legal-text', ['action' => $action] + $options);
    }

    private function draftTerms(): int
    {
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('template.txt', 'TEMPLATE: the terms.')])->assertExitCode(0);

        return (int) StorefrontLegalDocument::query()->latest('id')->value('id');
    }

    private function hashPrefixOf(string $words): string
    {
        return substr(hash('sha256', $words), 0, 12);
    }

    private function editedTerms(string $words = 'The terms of Kuwait.'): int
    {
        $id = $this->draftTerms();
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body.txt', $words)])->assertExitCode(0);

        return $id;
    }

    public function test_a_text_goes_from_a_template_to_a_published_text_through_the_command(): void
    {
        $this->text('draft', ['--country' => 'kw', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('template.txt', 'TEMPLATE: the terms.')])
            ->expectsOutputToContain('Draft 1: the terms (en) of KW, version 1, a copy of the template.')
            ->expectsOutputToContain('A text that is still the template is not approved')
            ->assertExitCode(0);

        $this->text('edit', ['--id' => 1, '--admin' => 11, '--body-file' => $this->file('body.txt', 'The terms of Kuwait.')])
            ->expectsOutputToContain('Text 1 is changed and is a draft of admin 11')
            ->assertExitCode(0);

        $this->text('approve', ['--id' => 1, '--admin' => 12])
            ->expectsOutputToContain('Text 1 is approved by admin 12 (written by admin 11). It is not in force until it is published.')
            ->expectsOutputToContain('KW: a required text is still missing')
            ->assertExitCode(0);

        $this->text('publish', ['--id' => 1, '--admin' => 12, '--effective-from' => now()->addDay()->utc()->format('Y-m-d H:i')])
            ->expectsOutputToContain('Text 1 is published and takes effect on')
            ->assertExitCode(0);

        $text = StorefrontLegalDocument::query()->findOrFail(1);
        $this->assertSame([StorefrontLegalDocument::STATUS_PUBLISHED, 11, 12], [$text->status, (int) $text->created_by, (int) $text->approved_by]);
        $this->assertSame(['drafted', 'edited', 'approved', 'published'], DB::table('audit_logs')->orderBy('id')->pluck('action')->map(fn (string $action): string => substr($action, strlen('storefront.legal_text.')))->all());
    }

    public function test_the_author_cannot_approve_and_the_command_fails_with_the_reason(): void
    {
        $id = $this->draftTerms();
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body.txt', 'The terms of Kuwait.')])->assertExitCode(0);

        $this->text('approve', ['--id' => $id, '--admin' => 11])
            ->expectsOutputToContain('someone other than its author')
            ->assertExitCode(1);

        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::query()->findOrFail($id)->status);
    }

    public function test_an_admin_who_does_not_exist_takes_no_step(): void
    {
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 99, '--template-file' => $this->file('template.txt', 'TEMPLATE')])
            ->expectsOutputToContain('There is no admin 99.')
            ->assertExitCode(1);

        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--template-file' => $this->file('template.txt', 'TEMPLATE')])
            ->expectsOutputToContain('--admin is the id of an admin.')
            ->assertExitCode(1);

        $this->assertSame(0, StorefrontLegalDocument::query()->count());
    }

    public function test_a_file_that_is_not_there_is_refused(): void
    {
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => "{$this->directory}/missing.txt"])
            ->expectsOutputToContain('is not a readable file')
            ->assertExitCode(1);

        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11])
            ->expectsOutputToContain('--template-file names the file')
            ->assertExitCode(1);
    }

    /** @return array<string, array{string}> */
    public static function filesThatAreNotUtf8(): array
    {
        return [
            'Windows-1256, the Arabic of a Windows machine' => ["\xE3\xD1\xCD\xC8\xC7 terms of Kuwait"],
            'UTF-16 with a byte order mark' => ["\xFF\xFE" . mb_convert_encoding('The terms of Kuwait, in Arabic: شروط', 'UTF-16LE', 'UTF-8')],
            'UTF-16 big endian with a byte order mark' => ["\xFE\xFF" . mb_convert_encoding('The terms of Kuwait', 'UTF-16BE', 'UTF-8')],
            'UTF-16 without a byte order mark' => [mb_convert_encoding('The terms of Kuwait', 'UTF-16LE', 'UTF-8')],
            'a stray byte in the middle of UTF-8' => ["The terms of \xC3 Kuwait"],
        ];
    }

    #[DataProvider('filesThatAreNotUtf8')]
    public function test_a_file_that_is_not_utf8_is_refused_whether_it_is_a_template_or_a_text(string $bytes): void
    {
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'ar', '--admin' => 11, '--template-file' => $this->file('template.txt', $bytes)])
            ->expectsOutputToContain('is not UTF-8 text')
            ->assertExitCode(1);

        $this->assertSame(0, StorefrontLegalDocument::query()->count());

        $id = $this->draftTerms();
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body.txt', $bytes)])
            ->expectsOutputToContain('save the file as UTF-8')
            ->assertExitCode(1);

        $this->assertSame('TEMPLATE: the terms.', StorefrontLegalDocument::query()->findOrFail($id)->body);
    }

    public function test_a_utf8_file_with_a_byte_order_mark_is_read_without_it(): void
    {
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('template.txt', "\xEF\xBB\xBFTEMPLATE: the terms.")])->assertExitCode(0);

        $draft = StorefrontLegalDocument::query()->firstOrFail();
        $this->assertSame('TEMPLATE: the terms.', $draft->body);
        $this->assertSame(hash('sha256', 'TEMPLATE: the terms.'), $draft->body_hash);
        $this->assertSame($draft->body_hash, $draft->template_hash);

        $this->text('edit', ['--id' => $draft->id, '--admin' => 11, '--body-file' => $this->file('body.txt', "\xEF\xBB\xBFThe terms of Kuwait.")])->assertExitCode(0);
        $this->assertSame('The terms of Kuwait.', $draft->fresh()->body);
    }

    public function test_a_valid_arabic_utf8_file_is_stored_as_it_is(): void
    {
        $arabic = "شروط الاستخدام في دولة الكويت\nالمادة الأولى: التعريفات";
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'ar', '--admin' => 11, '--template-file' => $this->file('template.txt', 'TEMPLATE: ' . $arabic)])->assertExitCode(0);
        $id = (int) StorefrontLegalDocument::query()->value('id');

        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body.txt', $arabic)])->assertExitCode(0);

        $this->assertSame($arabic, StorefrontLegalDocument::query()->findOrFail($id)->body);
    }

    public function test_a_day_that_is_not_a_day_is_refused_and_the_text_stays_approved(): void
    {
        $id = $this->draftTerms();
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body.txt', 'The terms of Kuwait.')])->assertExitCode(0);
        $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);

        foreach (['tomorrow', '2026-13-01 10:00', '2026-12-01'] as $day) {
            $this->text('publish', ['--id' => $id, '--admin' => 12, '--effective-from' => $day])
                ->expectsOutputToContain('--effective-from is a day and a time in UTC')
                ->assertExitCode(1);
        }

        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, StorefrontLegalDocument::query()->findOrFail($id)->status);
    }

    public function test_a_text_without_a_day_takes_effect_now(): void
    {
        $id = $this->draftTerms();
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body.txt', 'The terms of Kuwait.')])->assertExitCode(0);
        $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);

        $this->text('publish', ['--id' => $id, '--admin' => 12])->assertExitCode(0);

        $this->assertTrue(StorefrontLegalDocument::query()->findOrFail($id)->effective_from->lessThanOrEqualTo(now()));
    }

    public function test_it_revokes_with_a_reason_and_the_replacement_is_written_from_a_template(): void
    {
        $id = $this->draftTerms();
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body.txt', 'The terms of Kuwait.')])->assertExitCode(0);
        $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);
        $this->text('publish', ['--id' => $id, '--admin' => 12])->assertExitCode(0);

        $this->text('revoke', ['--id' => $id, '--admin' => 12])->expectsOutputToContain('The reason is blank.')->assertExitCode(1);
        $this->text('revoke', ['--id' => $id, '--admin' => 12, '--reason' => 'Wrong clause'])->expectsOutputToContain('Text 1 is revoked')->assertExitCode(0);

        $this->text('draft', ['--new-version-of' => $id, '--admin' => 11])
            ->expectsOutputToContain('Text 1 is revoked: a revoked text is not copied into a new version.')
            ->assertExitCode(1);

        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('template.txt', 'TEMPLATE: the terms.')])
            ->expectsOutputToContain('Draft 2: the terms (en) of KW, version 2, a copy of the template.')
            ->assertExitCode(0);
    }

    public function test_it_starts_a_new_version_of_a_published_text_that_cannot_be_approved_until_it_is_edited(): void
    {
        $id = $this->editedTerms();
        $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);
        $this->text('publish', ['--id' => $id, '--admin' => 12])->assertExitCode(0);

        $this->text('draft', ['--new-version-of' => $id, '--admin' => 11])
            ->expectsOutputToContain('Draft 2 is version 2 of the terms (en) of KW, a copy of the text it replaces.')
            ->assertExitCode(0);

        $this->text('approve', ['--id' => 2, '--admin' => 12])->expectsOutputToContain('still the template it was copied from')->assertExitCode(1);

        $this->text('edit', ['--id' => 2, '--admin' => 11, '--body-file' => $this->file('body2.txt', 'The terms of Kuwait, version 2.')])->assertExitCode(0);
        $this->text('approve', ['--id' => 2, '--admin' => 12])->assertExitCode(0);
    }

    public function test_it_discards_a_draft_that_is_not_wanted(): void
    {
        $id = $this->draftTerms();

        $this->text('discard', ['--id' => $id, '--admin' => 11, '--reason' => 'Wrong template'])->expectsOutputToContain('Text 1 is discarded and kept as history.')->assertExitCode(0);

        $this->assertSame(StorefrontLegalDocument::STATUS_ARCHIVED, StorefrontLegalDocument::query()->findOrFail($id)->status);
    }

    public function test_the_list_shows_the_texts_without_their_words_and_can_be_narrowed(): void
    {
        $this->draftTerms();
        $this->text('draft', ['--country' => 'AE', '--type' => 'privacy', '--locale' => 'ar', '--admin' => 11, '--template-file' => $this->file('other.txt', 'TEMPLATE: privacy.')])->assertExitCode(0);

        $this->text('list', ['--country' => 'KW'])
            ->expectsOutputToContain('terms')
            ->doesntExpectOutputToContain('privacy')
            ->doesntExpectOutputToContain('TEMPLATE')
            ->assertExitCode(0);

        $this->text('list')->expectsOutputToContain('privacy')->expectsOutputToContain('terms')->assertExitCode(0);
    }

    // ---- an approval and a publication are bound to the words that were read ---------------------------------------------------------------------

    public function test_the_list_and_every_step_that_leaves_a_text_show_the_first_twelve_characters_of_its_hash(): void
    {
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('template.txt', 'TEMPLATE: the terms.')])
            ->expectsOutputToContain($this->hashPrefixOf('TEMPLATE: the terms.'))
            ->assertExitCode(0);

        $this->text('edit', ['--id' => 1, '--admin' => 11, '--body-file' => $this->file('body.txt', 'The terms of Kuwait.')])
            ->expectsOutputToContain($this->hashPrefixOf('The terms of Kuwait.'))
            ->assertExitCode(0);

        $this->text('approve', ['--id' => 1, '--admin' => 12])
            ->expectsOutputToContain($this->hashPrefixOf('The terms of Kuwait.'))
            ->assertExitCode(0);

        $this->text('list')
            ->expectsOutputToContain('hash')
            ->expectsOutputToContain($this->hashPrefixOf('The terms of Kuwait.'))
            ->doesntExpectOutputToContain(hash('sha256', 'The terms of Kuwait.'))
            ->assertExitCode(0);
    }

    public function test_a_new_version_shows_the_hash_of_the_copy(): void
    {
        $id = $this->editedTerms();
        $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);
        $this->text('publish', ['--id' => $id, '--admin' => 12])->assertExitCode(0);

        $this->text('draft', ['--new-version-of' => $id, '--admin' => 11])
            ->expectsOutputToContain($this->hashPrefixOf('The terms of Kuwait.'))
            ->assertExitCode(0);
    }

    public function test_a_step_taken_with_the_hash_of_the_words_that_were_read_is_accepted_as_a_prefix_or_whole_in_any_case(): void
    {
        $id = $this->editedTerms();

        $this->text('approve', ['--id' => $id, '--admin' => 12, '--hash' => strtoupper($this->hashPrefixOf('The terms of Kuwait.'))])
            ->expectsOutputToContain("Text {$id} is approved by admin 12")
            ->assertExitCode(0);

        $this->text('publish', ['--id' => $id, '--admin' => 12, '--hash' => hash('sha256', 'The terms of Kuwait.')])
            ->expectsOutputToContain("Text {$id} is published")
            ->assertExitCode(0);
    }

    public function test_a_hash_that_is_not_the_hash_of_the_words_refuses_the_step_and_changes_nothing(): void
    {
        $id = $this->editedTerms();
        $linesBefore = DB::table('audit_logs')->count();

        $this->text('approve', ['--id' => $id, '--admin' => 12, '--hash' => $this->hashPrefixOf('Other words.')])
            ->expectsOutputToContain("Text {$id} changed after it was read")
            ->assertExitCode(1);

        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::query()->findOrFail($id)->status);

        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body2.txt', 'The terms of Kuwait, with a clause added.')])->assertExitCode(0);
        $this->text('approve', ['--id' => $id, '--admin' => 12, '--hash' => $this->hashPrefixOf('The terms of Kuwait.')])
            ->expectsOutputToContain('changed after it was read')
            ->assertExitCode(1);

        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::query()->findOrFail($id)->status);
        $this->assertSame($linesBefore + 1, DB::table('audit_logs')->count(), 'only the edit was audited');

        $this->text('approve', ['--id' => $id, '--admin' => 12, '--hash' => $this->hashPrefixOf('The terms of Kuwait, with a clause added.')])->assertExitCode(0);

        $this->text('publish', ['--id' => $id, '--admin' => 12, '--hash' => $this->hashPrefixOf('The terms of Kuwait.')])
            ->expectsOutputToContain('changed after it was read')
            ->assertExitCode(1);

        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, StorefrontLegalDocument::query()->findOrFail($id)->status);
    }

    public function test_a_hash_that_is_shorter_than_twelve_characters_or_is_not_hex_is_refused(): void
    {
        $id = $this->editedTerms();
        $prefix = $this->hashPrefixOf('The terms of Kuwait.');

        foreach ([substr($prefix, 0, 11), 'abc', str_repeat('g', 12), $prefix . 'z', hash('sha256', 'x') . 'a'] as $hash) {
            $this->text('approve', ['--id' => $id, '--admin' => 12, '--hash' => $hash])
                ->expectsOutputToContain('--hash is the first 12 characters, or all 64')
                ->assertExitCode(1);
        }

        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::query()->findOrFail($id)->status);
    }

    public function test_a_hash_named_for_a_text_that_does_not_exist_is_refused(): void
    {
        $this->text('approve', ['--id' => 99, '--admin' => 12, '--hash' => $this->hashPrefixOf('The terms of Kuwait.')])
            ->expectsOutputToContain('There is no legal text 99.')
            ->assertExitCode(1);
    }

    public function test_an_action_it_does_not_know_is_refused(): void
    {
        $this->text('publicise')->expectsOutputToContain('The action is list, draft, edit, approve, publish, revoke or discard.')->assertExitCode(1);
    }

    public function test_a_number_that_is_not_a_number_is_refused(): void
    {
        $this->text('approve', ['--id' => 'one', '--admin' => 12])->expectsOutputToContain('--id is the number of a text.')->assertExitCode(1);
        $this->text('approve', ['--admin' => 12])->expectsOutputToContain('--id is the number of a text.')->assertExitCode(1);
    }
}
