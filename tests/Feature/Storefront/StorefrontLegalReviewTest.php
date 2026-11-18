<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontLegalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\RecordsStorefrontLegalReview;
use Tests\TestCase;

/**
 * I2-07: a storefront other than the one that keeps its old documents does not sell until an independent legal review of its texts is on record: who reviewed them, where the
 * opinion is written and the day it was given. The service records what it is told and reviews nothing itself; a tool or an assistant is refused as a reviewer, a day that is
 * not a day or lies in the future is refused, and a row that does not say all of it counts for nothing. The names and references in the tests are examples, not opinions.
 */
class StorefrontLegalReviewTest extends TestCase
{
    use RecordsStorefrontLegalReview;

    private const REVIEWER = 'Al Sabah and Partners, Kuwait';

    private const OPINION = 'Opinion KW-2026-11';

    private const DAY = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_legal_review_');
        DB::table('audit_logs')->delete();
        DB::table('business_settings')->where('type', 'like', 'storefront_legal_review_%')->delete();
        clearWebConfigCacheKeys();
    }

    private function review(): StorefrontLegalReview
    {
        return app(StorefrontLegalReview::class);
    }

    private function record(string $country = 'KW', string $by = self::REVIEWER, string $reference = self::OPINION, string $on = self::DAY): array
    {
        return $this->review()->record(country: $country, reviewedBy: $by, reference: $reference, reviewedOn: $on);
    }

    private function statusOf(string $country = 'KW'): string
    {
        return $this->review()->statusOf($country)['status'];
    }

    private function settingRows(string $country = 'KW'): int
    {
        return DB::table('business_settings')->where('type', StorefrontLegalReview::settingName($country))->count();
    }

    // ---- who needs a review ----------------------------------------------------------------------------------------------------------------

    public function test_a_storefront_that_keeps_its_old_documents_needs_no_review_and_cannot_be_given_one(): void
    {
        $this->assertSame(StorefrontLegalReview::NOT_REQUIRED, $this->statusOf('SA'));
        $this->assertTrue($this->review()->isRecorded('SA'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SA keeps the documents it has always had');
        $this->record('SA');
    }

    public function test_the_grandfathered_list_of_the_configuration_decides_not_the_default_storefront(): void
    {
        config(['gcc.storefronts.legal.grandfathered' => ['KW']]);

        $this->assertSame(StorefrontLegalReview::NOT_REQUIRED, $this->statusOf('KW'));
        $this->assertSame(StorefrontLegalReview::MISSING, $this->statusOf('SA'));
    }

    public function test_a_storefront_with_no_review_on_record_is_not_reviewed(): void
    {
        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            $this->assertSame(StorefrontLegalReview::MISSING, $this->statusOf($country), $country);
            $this->assertFalse($this->review()->isRecorded($country), $country);
        }
    }

    public function test_the_country_is_read_without_regard_to_case_or_spaces(): void
    {
        $this->record(' kw ');

        $this->assertSame(StorefrontLegalReview::RECORDED, $this->statusOf('KW'));
        $this->assertSame(StorefrontLegalReview::RECORDED, $this->statusOf(' kw'));
        $this->assertSame('storefront_legal_review_kw', StorefrontLegalReview::settingName(' KW '));
    }

    // ---- what is recorded ------------------------------------------------------------------------------------------------------------------

    public function test_the_review_records_who_where_and_when_and_leaves_an_audit_line(): void
    {
        $record = $this->record();

        $this->assertSame([self::REVIEWER, self::OPINION, self::DAY], [$record['reviewed_by'], $record['reference'], $record['reviewed_on']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $record['recorded_at']);
        $this->assertEquals($record, json_decode((string) DB::table('business_settings')->where('type', StorefrontLegalReview::settingName('KW'))->value('value'), true));
        $this->assertSame(['status' => StorefrontLegalReview::RECORDED] + $record, $this->review()->statusOf('KW'));
        $this->assertTrue($this->review()->isRecorded('KW'));

        $line = DB::table('audit_logs')->where('action', 'storefront.legal_review.recorded')->first();
        $this->assertSame(['storefront', 'KW'], [$line->subject_type, $line->subject_id]);
        $this->assertEquals($record, json_decode((string) $line->after, true));
        $this->assertNull($line->before);
    }

    public function test_the_text_given_is_trimmed(): void
    {
        $record = $this->record(by: "  Al Sabah \n", reference: ' Opinion 7 ', on: ' ' . self::DAY . ' ');

        $this->assertSame(['Al Sabah', 'Opinion 7', self::DAY], [$record['reviewed_by'], $record['reference'], $record['reviewed_on']]);
    }

    public function test_a_review_recorded_today_is_accepted_and_one_of_tomorrow_is_not(): void
    {
        $this->assertSame(now()->toDateString(), $this->record(on: now()->toDateString())['reviewed_on']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be in the future');
        $this->record(on: now()->addDay()->toDateString());
    }

    public function test_recording_again_replaces_the_review_and_the_audit_line_keeps_the_one_before(): void
    {
        $first = $this->record();

        $second = $this->record(by: 'Another firm', reference: 'Opinion 12', on: '2026-11-15');

        $this->assertSame(1, $this->settingRows());
        $this->assertSame('Another firm', $this->review()->statusOf('KW')['reviewed_by']);
        $line = DB::table('audit_logs')->where('action', 'storefront.legal_review.recorded')->orderByDesc('id')->first();
        $this->assertEquals([$first, $second], [json_decode((string) $line->before, true), json_decode((string) $line->after, true)]);
    }

    public function test_a_review_whose_audit_line_cannot_be_written_is_not_recorded(): void
    {
        Schema::drop('audit_logs');

        try {
            $this->record();
            $this->fail('A review with no trace must not be recorded.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('audit_logs has not been migrated', $failure->getMessage());
        }

        $this->assertSame(0, $this->settingRows());
        $this->assertSame(StorefrontLegalReview::MISSING, $this->statusOf());
    }

    public function test_a_review_of_one_storefront_says_nothing_about_another(): void
    {
        $this->record('KW');

        $this->assertSame(StorefrontLegalReview::MISSING, $this->statusOf('AE'));
        $this->assertFalse($this->review()->isRecorded('AE'));
    }

    // ---- what is refused ---------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{array<string, string>, string}> */
    public static function refusals(): array
    {
        return [
            'a storefront that is not configured' => [['country' => 'XX'], 'XX is not a configured storefront.'],
            'nobody named' => [['by' => '  '], 'Say who reviewed the texts'],
            'a name too long to keep' => [['by' => str_repeat('a', 192)], 'Say who reviewed the texts'],
            'an assistant' => [['by' => 'Claude'], 'not a tool or an assistant'],
            'a pipeline' => [['by' => 'the CI pipeline'], 'not a tool or an assistant'],
            'a workflow' => [['by' => 'GitHub Actions'], 'not a tool or an assistant'],
            'a bot' => [['by' => 'release bot'], 'not a tool or an assistant'],
            'no source' => [['reference' => ''], 'Say where the opinion is written down'],
            'a source too long to keep' => [['reference' => str_repeat('r', 192)], 'Say where the opinion is written down'],
            'a day written the wrong way round' => [['on' => '10/11/2026'], 'as YYYY-MM-DD'],
            'a day that does not exist' => [['on' => '2026-13-45'], 'as YYYY-MM-DD'],
            'a day with no leading zero' => [['on' => '2026-11-1'], 'as YYYY-MM-DD'],
            'a word' => [['on' => 'yesterday'], 'as YYYY-MM-DD'],
            'no day' => [['on' => ''], 'as YYYY-MM-DD'],
            'a day in the future' => [['on' => '2999-01-01'], 'cannot be in the future'],
        ];
    }

    /**
     * @param array<string, string> $given
     */
    #[DataProvider('refusals')]
    public function test_a_review_that_does_not_say_who_where_and_when_is_refused_and_nothing_is_kept(array $given, string $message): void
    {
        try {
            $this->record(...$given);
            $this->fail('This review must be refused.');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString($message, $refusal->getMessage());
        }

        $this->assertSame(0, DB::table('business_settings')->where('type', 'like', 'storefront_legal_review_%')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_names_that_only_contain_the_letters_of_a_tool_are_not_refused(): void
    {
        $this->assertSame('Cilla and Partners', $this->record(by: 'Cilla and Partners')['reviewed_by']);
        $this->assertSame('Botros Law Office', $this->record(by: 'Botros Law Office')['reviewed_by']);
    }

    public function test_without_a_settings_table_nothing_can_be_recorded(): void
    {
        Schema::drop('business_settings');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no business_settings table.');
        $this->record();
    }

    // ---- what counts -------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{mixed}> */
    public static function rowsThatApproveNothing(): array
    {
        $complete = ['reviewed_by' => 'A firm', 'reference' => 'Opinion 1', 'reviewed_on' => '2026-11-10', 'recorded_at' => '2026-11-11T09:00:00+00:00'];

        return [
            'a row that is not an object' => ['"reviewed"'],
            'a number' => ['7'],
            'no reviewer' => [json_encode(array_diff_key($complete, ['reviewed_by' => 1]))],
            'no source' => [json_encode(array_diff_key($complete, ['reference' => 1]))],
            'no day' => [json_encode(array_diff_key($complete, ['reviewed_on' => 1]))],
            'no time of recording' => [json_encode(array_diff_key($complete, ['recorded_at' => 1]))],
            'a blank reviewer' => [json_encode(['reviewed_by' => '   '] + $complete)],
            'a reviewer that is not text' => [json_encode(['reviewed_by' => ['A firm']] + $complete)],
            'an empty object' => ['{}'],
        ];
    }

    #[DataProvider('rowsThatApproveNothing')]
    public function test_a_row_that_does_not_say_all_of_it_counts_for_nothing(string $stored): void
    {
        DB::table('business_settings')->insert(['type' => StorefrontLegalReview::settingName('KW'), 'value' => $stored, 'created_at' => now(), 'updated_at' => now()]);
        clearWebConfigCacheKeys();

        $this->assertSame(StorefrontLegalReview::INCOMPLETE, $this->statusOf('KW'));
        $this->assertFalse($this->review()->isRecorded('KW'));
    }

    // ---- withdrawing -------------------------------------------------------------------------------------------------------------------------

    public function test_withdrawing_a_review_closes_the_storefront_again_and_leaves_a_trace(): void
    {
        $record = $this->record();

        $this->assertTrue($this->review()->revoke('KW', 'The owner'));

        $this->assertSame(StorefrontLegalReview::MISSING, $this->statusOf());
        $this->assertSame(0, $this->settingRows());
        $line = DB::table('audit_logs')->where('action', 'storefront.legal_review.revoked')->first();
        $this->assertSame(['storefront', 'KW'], [$line->subject_type, $line->subject_id]);
        $this->assertEquals($record, json_decode((string) $line->before, true));
        $this->assertSame('The owner', json_decode((string) $line->context, true)['revoked_by']);
    }

    public function test_withdrawing_a_review_that_is_not_there_says_so_and_leaves_no_audit_line(): void
    {
        $this->assertFalse($this->review()->revoke('KW'));

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'storefront.legal_review.revoked')->count());
    }

    public function test_a_storefront_that_keeps_its_old_documents_has_no_review_to_withdraw(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SA keeps the documents it has always had and has no review to withdraw.');
        $this->review()->revoke('SA');
    }

    public function test_a_review_that_is_withdrawn_one_storefront_at_a_time_leaves_the_others(): void
    {
        $this->record('KW');
        $this->record('AE');

        $this->review()->revoke('KW');

        $this->assertFalse($this->review()->isRecorded('KW'));
        $this->assertTrue($this->review()->isRecorded('AE'));
    }
}
