<?php

namespace Tests\Unit\SalesChannels;

use App\Services\SalesChannels\Noon\NoonItemStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * noon answers a batch with HTTP 200 and one `items[]` entry per item, each carrying its own `status`. Success is `status_code == "OK"` AND `status_id == 0`.
 *
 * This class exists for one failure mode, and these are its tests: `status_id` is read, not cast. `(int) null` is 0 and `(int) "OK"` is 0, so a cast would have turned a
 * MISSING id into noon's success value and let `status_code: "OK"` alone stand for a confirmed write — a price nobody set, reported as set. Every shape of an unusable id
 * below is therefore a failure, and the reason says which failure it was rather than printing a bare "OK".
 *
 * The accepting cases matter as much: a gateway that renders 0 as `"0"` or as `0.0` has still told us 0, and refusing those would invent failures out of JSON typing.
 */
class NoonItemStatusTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function statusIdShapes(): array
    {
        return [
            'the integer zero noon documents' => [['status_code' => 'OK', 'status_id' => 0], true],
            'zero as a json string' => [['status_code' => 'OK', 'status_id' => '0'], true],
            'zero as a whole float' => [['status_code' => 'OK', 'status_id' => 0.0], true],

            'the field is absent altogether' => [['status_code' => 'OK'], false],
            'the field is null' => [['status_code' => 'OK', 'status_id' => null], false],
            'the field is an empty string' => [['status_code' => 'OK', 'status_id' => ''], false],
            'the field repeats the code instead of an id' => [['status_code' => 'OK', 'status_id' => 'OK'], false],
            'the field is a word' => [['status_code' => 'OK', 'status_id' => 'zero'], false],
            'the field is a fraction' => [['status_code' => 'OK', 'status_id' => 0.5], false],
            'the field is a boolean' => [['status_code' => 'OK', 'status_id' => true], false],
            'the field is false, which casts to zero' => [['status_code' => 'OK', 'status_id' => false], false],
            'the field is an empty array' => [['status_code' => 'OK', 'status_id' => []], false],
            'the field is an object' => [['status_code' => 'OK', 'status_id' => ['id' => 0]], false],
            'a real id noon uses for a real failure' => [['status_code' => 'OK', 'status_id' => 7], false],
        ];
    }

    /** @param array<string, mixed> $status */
    #[DataProvider('statusIdShapes')]
    public function test_only_a_status_id_that_really_says_zero_is_success(array $status, bool $expected): void
    {
        $this->assertSame($expected, NoonItemStatus::of(['status' => $status], 'SKU-1')->ok);
    }

    public function test_a_missing_status_id_is_never_read_as_the_success_value(): void
    {
        $status = NoonItemStatus::of(['status' => ['status_code' => 'OK']], 'SKU-1');

        $this->assertFalse($status->ok, 'a cast would have made (int) null the success value 0');
        $this->assertSame('ok_without_a_usable_status_id', $status->reason(), 'the reason may not print a bare OK for an item nobody confirmed');
    }

    public function test_a_null_status_id_is_named_for_what_it_is_rather_than_shown_as_ok(): void
    {
        $status = NoonItemStatus::of(['status' => ['status_code' => 'OK', 'status_id' => null]], 'SKU-1');

        $this->assertFalse($status->ok);
        $this->assertStringContainsString('ok_without_a_usable_status_id', $status->reason());
    }

    public function test_an_unreadable_status_id_keeps_noons_own_message_alongside_the_named_cause(): void
    {
        $status = NoonItemStatus::of(['status' => ['status_code' => 'OK', 'status_id' => 'OK', 'message' => 'partner_sku accepted']], 'SKU-1');

        $this->assertFalse($status->ok);
        $this->assertSame('ok_without_a_usable_status_id: partner_sku accepted', $status->reason(), 'noon\'s words are kept; what is added is that we could not read its id');
    }

    public function test_no_status_at_all_is_its_own_reason_and_not_an_unreadable_id(): void
    {
        $status = NoonItemStatus::of([], 'SKU-1');

        $this->assertFalse($status->ok);
        $this->assertSame('no_status_from_noon', $status->reason(), 'nothing was said, which is a different problem from something unreadable');
        $this->assertNull($status->code);
    }

    public function test_a_failure_noon_named_is_reported_as_noon_named_it(): void
    {
        $status = NoonItemStatus::of(['status' => ['status_code' => 'SKU_NOT_FOUND', 'status_id' => 5, 'message' => 'noon holds no such partner_sku']], 'SKU-1');

        $this->assertFalse($status->ok);
        $this->assertSame('SKU_NOT_FOUND: noon holds no such partner_sku', $status->reason(), 'a named failure is never relabelled as an unreadable id');
    }

    public function test_a_usable_zero_under_a_failing_code_is_still_a_failure(): void
    {
        // Both fields have to agree. An id of 0 does not rescue a code that is not OK.
        $status = NoonItemStatus::of(['status' => ['status_code' => 'VALIDATION_ERROR', 'status_id' => 0]], 'SKU-1');

        $this->assertFalse($status->ok);
        $this->assertSame('VALIDATION_ERROR', $status->reason());
    }

    public function test_the_key_is_kept_so_an_answer_is_matched_by_its_own_identifiers(): void
    {
        // noon echoes back the fields it was given and promises no order, so the key an answer was read under is part of the answer.
        $status = NoonItemStatus::of(['status' => ['status_code' => 'OK', 'status_id' => 0]], 'RUH1|SKU-9');

        $this->assertSame('RUH1|SKU-9', $status->key);
        $this->assertTrue($status->ok);
    }

    public function test_an_empty_message_is_dropped_rather_than_shown_as_a_colon(): void
    {
        $status = NoonItemStatus::of(['status' => ['status_code' => 'SKU_NOT_FOUND', 'status_id' => 5, 'message' => '']], 'SKU-1');

        $this->assertSame('SKU_NOT_FOUND', $status->reason());
        $this->assertNull($status->message);
    }
}
