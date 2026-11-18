<?php

namespace Tests\Feature\Storefront;

use App\Console\Commands\StorefrontLegalTextCommand;
use App\Models\StorefrontLegalDocument;
use App\Services\Storefront\StorefrontLegalPublisher;
use App\Services\Storefront\StorefrontLegalReadiness;
use Carbon\CarbonImmutable;
use Closure;
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
            $table->boolean('status')->default(1);
        });
        DB::table('admins')->insert([['id' => 11, 'name' => 'Author', 'status' => 1], ['id' => 12, 'name' => 'Approver', 'status' => 1], ['id' => 13, 'name' => 'Former employee', 'status' => 0]]);

        $this->directory = sys_get_temp_dir() . '/legal-text-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        StorefrontLegalDocument::flushEventListeners();
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

    private function writeText(string $type, string $locale, string $words): int
    {
        $this->text('draft', ['--country' => 'KW', '--type' => $type, '--locale' => $locale, '--admin' => 11, '--template-file' => $this->file('template.txt', "TEMPLATE: the {$type}.")])->assertExitCode(0);
        $id = (int) StorefrontLegalDocument::query()->latest('id')->value('id');
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('body.txt', $words)])->assertExitCode(0);

        return $id;
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

    /** Runs $change once, at the moment the text is next read: the command's own read of it, before the service has locked it. That is where a second admin's step lands in a race. */
    private function whenTheCommandHasReadTheText(int $textId, Closure $change): void
    {
        $changed = false;

        StorefrontLegalDocument::retrieved(function (StorefrontLegalDocument $text) use ($textId, $change, &$changed): void {
            if (!$changed && (int) $text->id === $textId) {
                $changed = true;
                $change();
            }
        });
    }

    /** @return list<string> the steps of the publisher that were audited after the audit line with this id */
    private function stepsAuditedAfter(int $lineId): array
    {
        return DB::table('audit_logs')->where('id', '>', $lineId)->orderBy('id')->pluck('action')
            ->map(fn (string $action): string => substr($action, strlen('storefront.legal_text.')))->all();
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

    public function test_an_admin_who_is_switched_off_takes_no_step_in_any_action(): void
    {
        $id = $this->editedTerms();

        $this->text('draft', ['--country' => 'AE', '--type' => 'terms', '--locale' => 'en', '--admin' => 13, '--template-file' => $this->file('template2.txt', 'TEMPLATE')])
            ->expectsOutputToContain('Admin 13 is switched off and takes no step.')
            ->assertExitCode(1);
        $this->text('edit', ['--id' => $id, '--admin' => 13, '--body-file' => $this->file('body2.txt', 'The terms of Kuwait, changed.')])
            ->expectsOutputToContain('Admin 13 is switched off')
            ->assertExitCode(1);
        $this->text('approve', ['--id' => $id, '--admin' => 13])
            ->expectsOutputToContain('Admin 13 is switched off')
            ->assertExitCode(1);
        $this->text('discard', ['--id' => $id, '--admin' => 13, '--reason' => 'Not wanted'])
            ->expectsOutputToContain('Admin 13 is switched off')
            ->assertExitCode(1);

        $text = StorefrontLegalDocument::query()->findOrFail($id);
        $this->assertSame([1, StorefrontLegalDocument::STATUS_DRAFT, 'The terms of Kuwait.'], [StorefrontLegalDocument::query()->count(), $text->status, $text->body]);
        $this->assertSame(2, DB::table('audit_logs')->count(), 'only the draft and the edit of the admin who is on were audited');

        DB::table('admins')->where('id', 13)->update(['status' => 1]);
        $this->text('approve', ['--id' => $id, '--admin' => 13])->assertExitCode(0);
    }

    public function test_the_admin_with_the_id_one_is_not_exempt_from_the_status_check(): void
    {
        DB::table('admins')->insert(['id' => 1, 'name' => 'First admin, switched off', 'status' => 0]);

        $this->text(action: 'draft', options: ['--country' => 'AE', '--type' => 'terms', '--locale' => 'en', '--admin' => 1, '--template-file' => $this->file(name: 'template2.txt', content: 'TEMPLATE')])
            ->expectsOutputToContain('Admin 1 is switched off and takes no step.')
            ->assertExitCode(1);
        $this->assertSame(0, StorefrontLegalDocument::query()->count());

        $id = $this->editedTerms();
        $this->text(action: 'approve', options: ['--id' => $id, '--admin' => 1])
            ->expectsOutputToContain('Admin 1 is switched off')
            ->assertExitCode(1);

        $this->assertSame(StorefrontLegalDocument::STATUS_DRAFT, StorefrontLegalDocument::query()->findOrFail($id)->status);
        $this->assertSame(2, DB::table('audit_logs')->count(), 'only the draft and the edit of the admin who is on were audited');

        DB::table('admins')->where('id', 1)->update(['status' => 1]);
        $this->text(action: 'approve', options: ['--id' => $id, '--admin' => 1])->assertExitCode(0);
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

    /** @return array<string, array{string, string}> */
    public static function optionsOfADraft(): array
    {
        return ['the country' => ['country', 'the storefront'], 'the type' => ['type', 'the document type'], 'the locale' => ['locale', 'the locale']];
    }

    #[DataProvider('optionsOfADraft')]
    public function test_a_draft_without_its_country_type_or_locale_is_refused_with_the_name_of_the_option(string $option, string $meaning): void
    {
        $complete = ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('template.txt', 'TEMPLATE: the terms.')];

        foreach (['', '   ', null] as $value) {
            $options = $complete;
            unset($options["--{$option}"]);
            if ($value !== null) {
                $options["--{$option}"] = $value;
            }

            $this->text('draft', $options)
                ->expectsOutputToContain("--{$option} is missing: it is {$meaning}")
                ->assertExitCode(1);
        }

        $this->assertSame(0, StorefrontLegalDocument::query()->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_file_of_exactly_the_limit_is_read_and_one_byte_more_is_refused(): void
    {
        $limit = StorefrontLegalTextCommand::MAXIMUM_FILE_BYTES;

        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('exact.txt', str_repeat('a', $limit))])->assertExitCode(0);
        $this->assertSame($limit, strlen(StorefrontLegalDocument::query()->firstOrFail()->body));

        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'ar', '--admin' => 11, '--template-file' => $this->file('over.txt', str_repeat('a', $limit + 1))])
            ->expectsOutputToContain("is not a readable file of at most {$limit} bytes")
            ->assertExitCode(1);

        $this->assertSame(1, StorefrontLegalDocument::query()->count());
    }

    public function test_a_directory_is_not_a_file_that_can_be_read(): void
    {
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->directory])
            ->expectsOutputToContain('is not a readable file')
            ->assertExitCode(1);

        $id = $this->draftTerms();
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->directory])
            ->expectsOutputToContain('is not a readable file')
            ->assertExitCode(1);
    }

    public function test_an_empty_file_is_a_blank_template_and_a_blank_text(): void
    {
        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('empty.txt', '')])
            ->expectsOutputToContain('The template is blank.')
            ->assertExitCode(1);

        $this->text('draft', ['--country' => 'KW', '--type' => 'terms', '--locale' => 'en', '--admin' => 11, '--template-file' => $this->file('mark-only.txt', "\xEF\xBB\xBF\n")])
            ->expectsOutputToContain('The template is blank.')
            ->assertExitCode(1);

        $id = $this->draftTerms();
        $this->text('edit', ['--id' => $id, '--admin' => 11, '--body-file' => $this->file('empty-body.txt', '')])
            ->expectsOutputToContain('The text is blank.')
            ->assertExitCode(1);

        $this->assertSame('TEMPLATE: the terms.', StorefrontLegalDocument::query()->findOrFail($id)->body);
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

    public function test_a_day_after_the_last_moment_the_database_keeps_is_refused_and_the_last_moment_is_accepted(): void
    {
        $id = $this->editedTerms();
        $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);

        foreach (['2038-01-01 00:00', '2099-12-31 23:59'] as $day) {
            $this->text('publish', ['--id' => $id, '--admin' => 12, '--effective-from' => $day])
                ->expectsOutputToContain('does not take effect after 2037-12-31 23:59:59 UTC')
                ->assertExitCode(1);
        }

        $this->assertSame(StorefrontLegalDocument::STATUS_APPROVED, StorefrontLegalDocument::query()->findOrFail($id)->status);

        $this->text('publish', ['--id' => $id, '--admin' => 12, '--effective-from' => '2037-12-31 23:59'])
            ->expectsOutputToContain('takes effect on 2037-12-31 23:59 UTC')
            ->assertExitCode(0);
    }

    public function test_a_day_is_read_in_utc_whatever_the_time_zone_of_the_application_is(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Asia/Dhaka');
        config(['app.timezone' => 'Asia/Dhaka']);

        try {
            $id = $this->editedTerms();
            $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);
            $moment = CarbonImmutable::now('UTC')->addHour()->startOfMinute();

            $this->text('publish', ['--id' => $id, '--admin' => 12, '--effective-from' => $moment->format('Y-m-d H:i')])
                ->expectsOutputToContain("takes effect on {$moment->format('Y-m-d H:i')} UTC")
                ->assertExitCode(0);

            $stored = StorefrontLegalDocument::query()->findOrFail($id)->effective_from;
            $this->assertTrue($stored->equalTo($moment), "stored {$stored->toIso8601String()}, read from {$moment->toIso8601String()}");
            $this->assertEqualsWithDelta(60, now()->diffInMinutes($stored, absolute: false), 1.5, 'an hour ahead of now, not five hours behind it as the digits would be if they were read as Dhaka time');
        } finally {
            date_default_timezone_set($original);
        }
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

    public function test_it_says_what_the_revocation_of_a_text_in_force_means(): void
    {
        $id = $this->editedTerms();
        $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);
        $this->text('publish', ['--id' => $id, '--admin' => 12])->assertExitCode(0);

        $this->text('revoke', ['--id' => $id, '--admin' => 12, '--reason' => 'Wrong clause'])
            ->expectsOutputToContain("Text {$id} is revoked: while it is the text in force the storefront does not sell. Write its replacement from a template")
            ->doesntExpectOutputToContain('never takes effect')
            ->assertExitCode(0);

        $this->assertSame(StorefrontLegalDocument::STATUS_PUBLISHED, StorefrontLegalDocument::query()->findOrFail($id)->status);
    }

    public function test_it_says_that_a_text_revoked_before_it_took_effect_is_archived_and_the_text_in_force_stays(): void
    {
        $id = $this->editedTerms();
        $this->text('approve', ['--id' => $id, '--admin' => 12])->assertExitCode(0);
        $this->text('publish', ['--id' => $id, '--admin' => 12, '--effective-from' => now()->addDays(10)->utc()->format('Y-m-d H:i')])->assertExitCode(0);

        $this->text('revoke', ['--id' => $id, '--admin' => 12, '--reason' => 'Wrong clause, found before it took effect'])
            ->expectsOutputToContain("Text {$id} is revoked before it took effect: it is archived and never takes effect, and the text in force stays in force.")
            ->doesntExpectOutputToContain('the storefront does not sell')
            ->doesntExpectOutputToContain('Write its replacement')
            ->assertExitCode(0);

        $this->assertSame(StorefrontLegalDocument::STATUS_ARCHIVED, StorefrontLegalDocument::query()->findOrFail($id)->status);
    }

    public function test_every_required_text_of_the_storefront_published_through_the_command_makes_it_ready(): void
    {
        $required = [];
        foreach (config('gcc.storefronts.legal.required_documents') as $type) {
            foreach (config('gcc.storefronts.legal.required_locales') as $locale) {
                $required[] = [$type, $locale];
            }
        }
        $this->assertGreaterThan(1, count($required));

        foreach ($required as $position => [$type, $locale]) {
            $words = "The {$locale} {$type} of Kuwait.";
            $id = $this->writeText(type: $type, locale: $locale, words: $words);
            $isTheLast = $position === array_key_last($required);

            $this->text('approve', ['--id' => $id, '--admin' => 12, '--hash' => $this->hashPrefixOf($words)])
                ->expectsOutputToContain('KW: a required text is still missing')
                ->assertExitCode(0);

            $this->text('publish', ['--id' => $id, '--admin' => 12, '--hash' => $this->hashPrefixOf($words)])
                ->expectsOutputToContain($isTheLast ? 'KW: every required text is published and approved.' : 'KW: a required text is still missing')
                ->assertExitCode(0);
        }

        $this->assertSame(count($required), StorefrontLegalDocument::query()->where('status', StorefrontLegalDocument::STATUS_PUBLISHED)->count());
        $this->assertTrue(app(StorefrontLegalReadiness::class)->isReady('KW'));
        $this->assertFalse(app(StorefrontLegalReadiness::class)->isReady('AE'), 'another storefront has none of them');
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

    /** @return array<string, array{string}> */
    public static function hashesThatNameNoWords(): array
    {
        return ['empty, as a script gives it when its variable is empty' => [''], 'spaces only' => ['   ']];
    }

    #[DataProvider('hashesThatNameNoWords')]
    public function test_an_empty_hash_is_refused_on_an_approval_and_is_not_read_as_no_hash(string $hash): void
    {
        $id = $this->editedTerms();
        $linesBefore = DB::table('audit_logs')->count();

        $this->text(action: 'approve', options: ['--id' => $id, '--admin' => 12, '--hash' => $hash])
            ->expectsOutputToContain('--hash is the first 12 characters, or all 64')
            ->assertExitCode(1);

        $text = StorefrontLegalDocument::query()->findOrFail($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_DRAFT, null, null], [$text->status, $text->approved_by, $text->approved_at]);
        $this->assertSame($linesBefore, DB::table('audit_logs')->count());

        $this->text(action: 'approve', options: ['--id' => $id, '--admin' => 12])->assertExitCode(0);
    }

    #[DataProvider('hashesThatNameNoWords')]
    public function test_an_empty_hash_is_refused_on_a_publication_and_is_not_read_as_no_hash(string $hash): void
    {
        $id = $this->editedTerms();
        $this->text(action: 'approve', options: ['--id' => $id, '--admin' => 12])->assertExitCode(0);
        $linesBefore = DB::table('audit_logs')->count();

        $this->text(action: 'publish', options: ['--id' => $id, '--admin' => 12, '--hash' => $hash])
            ->expectsOutputToContain('--hash is the first 12 characters, or all 64')
            ->assertExitCode(1);

        $text = StorefrontLegalDocument::query()->findOrFail($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_APPROVED, null, null], [$text->status, $text->published_at, $text->effective_from]);
        $this->assertSame($linesBefore, DB::table('audit_logs')->count());

        $this->text(action: 'publish', options: ['--id' => $id, '--admin' => 12])->assertExitCode(0);
    }

    public function test_a_hash_named_for_a_text_that_does_not_exist_is_refused(): void
    {
        $this->text('approve', ['--id' => 99, '--admin' => 12, '--hash' => $this->hashPrefixOf('The terms of Kuwait.')])
            ->expectsOutputToContain('There is no legal text 99.')
            ->assertExitCode(1);
    }

    public function test_an_approval_is_refused_by_the_service_when_a_second_admin_changes_the_text_after_the_command_read_it(): void
    {
        $id = $this->editedTerms();
        $read = $this->hashPrefixOf(words: 'The terms of Kuwait.');
        $this->whenTheCommandHasReadTheText(textId: $id, change: fn () => app(StorefrontLegalPublisher::class)->edit(textId: $id, body: 'The terms of Kuwait, changed by a second admin.', editor: 11));
        $lastLineBefore = (int) DB::table('audit_logs')->max('id');

        $this->text(action: 'approve', options: ['--id' => $id, '--admin' => 12, '--hash' => $read])
            ->expectsOutputToContain('the words it holds now are not the ones whose hash was given')
            ->assertExitCode(1);

        $text = StorefrontLegalDocument::query()->findOrFail($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_DRAFT, null, 'The terms of Kuwait, changed by a second admin.'], [$text->status, $text->approved_by, $text->body]);
        $this->assertSame(['edited'], $this->stepsAuditedAfter(lineId: $lastLineBefore), 'only the change of the second admin was audited, not an approval');

        $this->text(action: 'approve', options: ['--id' => $id, '--admin' => 12, '--hash' => $this->hashPrefixOf(words: 'The terms of Kuwait, changed by a second admin.')])->assertExitCode(0);
    }

    public function test_a_publication_is_refused_by_the_service_when_a_second_admin_changes_and_approves_the_text_after_the_command_read_it(): void
    {
        $id = $this->editedTerms();
        $this->text(action: 'approve', options: ['--id' => $id, '--admin' => 12])->assertExitCode(0);
        $read = $this->hashPrefixOf(words: 'The terms of Kuwait.');
        $this->whenTheCommandHasReadTheText(textId: $id, change: function () use ($id): void {
            $publisher = app(StorefrontLegalPublisher::class);
            $publisher->edit(textId: $id, body: 'The terms of Kuwait, changed by a second admin.', editor: 11);
            $publisher->approve(textId: $id, approver: 12);
        });
        $lastLineBefore = (int) DB::table('audit_logs')->max('id');

        $this->text(action: 'publish', options: ['--id' => $id, '--admin' => 12, '--hash' => $read])
            ->expectsOutputToContain('the words it holds now are not the ones whose hash was given')
            ->assertExitCode(1);

        $text = StorefrontLegalDocument::query()->findOrFail($id);
        $this->assertSame([StorefrontLegalDocument::STATUS_APPROVED, 'The terms of Kuwait, changed by a second admin.', null, null], [$text->status, $text->body, $text->published_at, $text->effective_from]);
        $this->assertSame(['edited', 'approved'], $this->stepsAuditedAfter(lineId: $lastLineBefore), 'only the changes of the second admin were audited, not a publication');

        $this->text(action: 'publish', options: ['--id' => $id, '--admin' => 12, '--hash' => $this->hashPrefixOf(words: 'The terms of Kuwait, changed by a second admin.')])->assertExitCode(0);
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
