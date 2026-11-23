<?php

namespace Tests\Unit\PlatformAdmin;

use App\Services\PlatformAdmin\PlatformAdminText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlatformAdminTextTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function textThatIsNotText(): array
    {
        return [
            'bytes no UTF-8 sequence starts with' => ["\xff\xfe\xfa"],
            'an overlong form' => ["\xc0\xaf"],
            'a sequence cut short' => ["Phone \xe2\x82"],
            'a continuation byte alone' => ["\x80abc"],
            'a surrogate half' => ["\xed\xa0\x80"],
        ];
    }

    #[DataProvider('textThatIsNotText')]
    public function test_text_that_is_not_text_comes_back_as_text_that_json_can_hold(string $sent): void
    {
        $kept = PlatformAdminText::clipped(text: $sent, limit: 64);

        $this->assertTrue(mb_check_encoding($kept, 'UTF-8'), bin2hex($kept));
        $this->assertNotFalse(json_encode(['kept' => $kept]), 'JSON can hold it');
    }

    public function test_text_is_kept_as_it_was_sent_when_it_is_text(): void
    {
        $this->assertSame('Phone 12 · café', PlatformAdminText::clipped(text: 'Phone 12 · café', limit: 64));
    }

    public function test_the_limit_counts_characters_and_never_cuts_one_in_two(): void
    {
        $this->assertSame('ééé', PlatformAdminText::clipped(text: 'ééééé', limit: 3));
        $this->assertSame('', PlatformAdminText::clipped(text: 'é', limit: 0));
        $this->assertSame('abc', PlatformAdminText::clipped(text: 'abc', limit: 255));
    }

    public function test_the_limit_applies_after_the_bytes_that_are_not_text_have_been_replaced(): void
    {
        $this->assertSame(64, mb_strlen(PlatformAdminText::clipped(text: str_repeat("\xff", 300), limit: 64)));
    }
}
