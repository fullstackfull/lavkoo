<?php

namespace Tests\Feature\Coupon;

use App\Services\CouponService;
use ArrayObject;
use Brian2694\Toastr\Facades\Toastr;
use Tests\Feature\Address\StorefrontTestCase;

/**
 * The admin and vendor coupon form refuses a fixed discount above the minimum purchase (app/Services/CouponService.php:120-130).
 *
 * The rule and its wording stay as they are. What is tested is the message key: it must be the key the translation files define,
 * so that the message resolves from the file in the shopper's language and translate() writes nothing into resources/lang.
 */
class AdminCouponMinimumMessageTest extends StorefrontTestCase
{
    private const MESSAGE_KEY = 'The_minimum_purchase_amount_must_be_greater_than_discount_amount';

    /** @var array<string, string> */
    private array $languageFileDigests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->languageFileDigests = $this->digestsOfLanguageFiles();
    }

    protected function tearDown(): void
    {
        if ($this->languageFileDigests !== []) {
            $this->assertSame($this->languageFileDigests, $this->digestsOfLanguageFiles(), 'translate() wrote a language file during the test');
        }

        parent::tearDown();
    }

    public function test_an_arabic_admin_sees_the_arabic_message_for_a_discount_above_the_minimum(): void
    {
        session(['local' => 'sa']);
        $expected = include base_path('resources/lang/sa/messages.php');

        Toastr::shouldReceive('error')->once()->with($expected[self::MESSAGE_KEY]);

        $this->assertFalse((new CouponService())->checkConditions($this->fixedDiscountAboveTheMinimum()));
    }

    public function test_an_english_admin_sees_the_english_message_for_a_discount_above_the_minimum(): void
    {
        session(['local' => 'en']);
        $expected = include base_path('resources/lang/en/messages.php');

        Toastr::shouldReceive('error')->once()->with($expected[self::MESSAGE_KEY]);

        $this->assertFalse((new CouponService())->checkConditions($this->fixedDiscountAboveTheMinimum()));
    }

    public function test_a_fixed_discount_equal_to_the_minimum_is_still_accepted(): void
    {
        session(['local' => 'sa']);
        Toastr::shouldReceive('error')->never();

        $this->assertTrue((new CouponService())->checkConditions(new ArrayObject([
            'coupon_type' => 'discount_on_purchase', 'discount_type' => 'amount', 'discount' => '100', 'min_purchase' => '100',
        ])));
    }

    public function test_the_admin_coupon_controller_asks_for_the_key_the_translation_files_define(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/Promotion/CouponController.php'));

        $this->assertStringNotContainsString("translate('the_minimum_purchase_amount_must_be_greater_than_discount_amount')", $controller);
        $this->assertStringContainsString("translate('" . self::MESSAGE_KEY . "')", $controller);
    }

    private function fixedDiscountAboveTheMinimum(): ArrayObject
    {
        return new ArrayObject([
            'coupon_type' => 'discount_on_purchase', 'discount_type' => 'amount', 'discount' => '101', 'min_purchase' => '100',
        ]);
    }

    /** @return array<string, string> */
    private function digestsOfLanguageFiles(): array
    {
        $digests = [];
        foreach (glob(base_path('resources/lang/*/*.php')) ?: [] as $file) {
            $digests[$file] = md5_file($file);
        }

        return $digests;
    }
}
