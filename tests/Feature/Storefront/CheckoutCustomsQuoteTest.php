<?php

namespace Tests\Feature\Storefront;

use App\Services\Shipping\CheckoutCustomsQuote;
use App\Services\Shipping\CustomsDuty;
use App\Services\Shipping\CustomsDutyQuote;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * H1-09's second criterion: a cross-border order shows the duty, or that the buyer pays it on delivery, BEFORE payment.
 *
 * One service answers it for every surface, because two formulas is how a customer is shown one figure on a phone and another in a
 * browser. The value quoted is the value of the GOODS -- the lines, less their discount, without shipping and without tax -- since duty
 * is charged on the thing crossing the border and not on what it cost to get it there, and the same figure is what the order keeps.
 *
 * The first test is the dormancy: with duty off the answer is null, and a surface reading null draws no line at all. A line saying "no
 * duty is owed" on a Saudi order is a line about a border that order never approaches.
 */
class CheckoutCustomsQuoteTest extends TestCase
{
    private const GROUP = 'cart-group-1';

    private const HOME = 'SA';

    private const ABROAD = 'KW';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCartWorld();
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        foreach (['carts', 'products'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function buildCartWorld(): void
    {
        Schema::dropIfExists('carts');
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string(StorefrontCatalog::COLUMN, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->decimal('price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->integer('quantity')->default(1);
            $table->boolean('is_checked')->default(1);
            $table->timestamps();
        });
    }

    private function aLine(float $price, int $quantity = 1, float $discount = 0, bool $checked = true, int $productId = 1): void
    {
        DB::table('products')->insertOrIgnore([
            'id' => $productId, 'name' => 'Product ' . $productId, StorefrontCatalog::COLUMN => self::HOME,
        ]);
        DB::table('carts')->insert([
            'cart_group_id' => self::GROUP, 'product_id' => $productId,
            'price' => $price, 'discount' => $discount, 'quantity' => $quantity, 'is_checked' => $checked ? 1 : 0,
        ]);
    }

    private function dutyIs(string $mode, array $routes = []): void
    {
        config(['gcc.shipping.duty.mode' => $mode, 'gcc.shipping.duty.routes' => $routes]);
    }

    private function quote(?string $destination = self::ABROAD): ?CustomsDutyQuote
    {
        return app(CheckoutCustomsQuote::class)->forCart([self::GROUP], $destination);
    }

    /** The dormancy: nothing is said at all, so no surface draws a line. */
    public function test_with_duty_off_nothing_is_quoted(): void
    {
        $this->dutyIs(CustomsDuty::MODE_OFF, ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY]]);
        $this->aLine(price: 100.0);

        $this->assertNull($this->quote());
    }

    /** And a domestic basket says nothing either: a line about duty on an order that crosses no border is noise. */
    public function test_a_domestic_basket_is_not_told_about_duty(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY]]);
        $this->aLine(price: 100.0);

        $this->assertNull($this->quote(destination: self::HOME));
    }

    public function test_the_customer_is_told_the_amount_and_who_pays_it(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::BUYER_ON_DELIVERY]]);
        $this->aLine(price: 200.0, quantity: 2);

        $quote = $this->quote();

        $this->assertNotNull($quote);
        $this->assertSame(CustomsDutyQuote::PAYABLE, $quote->kind);
        $this->assertSame(400.0, $quote->basis);
        $this->assertSame(20.0, $quote->amount);
        $this->assertSame('customs_duty_is_paid_by_you_on_delivery', $quote->noticeKey());
    }

    public function test_a_seller_paid_duty_says_so_instead(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::SELLER]]);
        $this->aLine(price: 100.0);

        $this->assertSame('customs_duty_is_paid_by_the_seller', $this->quote()->noticeKey());
    }

    /** The discount comes off, because what was actually paid for the goods is their value. */
    public function test_the_value_quoted_is_the_goods_less_their_discount(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 10, 'payer' => CustomsDutyQuote::SELLER]]);
        $this->aLine(price: 100.0, quantity: 3, discount: 10.0);

        $this->assertSame(270.0, $this->quote()->basis);
        $this->assertSame(27.0, $this->quote()->amount);
    }

    /** A line the customer unchecked is not being bought, so it is not being imported either. */
    public function test_an_unchecked_line_is_not_quoted(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, ['SA:KW' => ['percentage' => 10, 'payer' => CustomsDutyQuote::SELLER]]);
        $this->aLine(price: 100.0, productId: 1);
        $this->aLine(price: 500.0, checked: false, productId: 2);

        $this->assertSame(100.0, $this->quote()->basis);
    }

    /** An unquotable route says so rather than claiming nothing is owed, which is the whole point of H1-09. */
    public function test_a_route_with_no_rule_tells_the_customer_it_is_not_quoted(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE);
        $this->aLine(price: 100.0);

        $quote = $this->quote();

        $this->assertSame(CustomsDutyQuote::NOT_QUOTED, $quote->kind);
        $this->assertSame('customs_duty_has_not_been_quoted_for_this_country', $quote->noticeKey());
    }

    /** Under the destination's threshold a parcel enters free, and the customer is told nothing is owed rather than nothing at all. */
    public function test_a_basket_under_the_de_minimis_is_told_nothing_is_owed(): void
    {
        $this->dutyIs(CustomsDuty::MODE_DECLARE, [
            'SA:KW' => ['percentage' => 5, 'payer' => CustomsDutyQuote::SELLER, 'de_minimis' => 500],
        ]);
        $this->aLine(price: 100.0);

        $quote = $this->quote();

        $this->assertSame(CustomsDutyQuote::NONE, $quote->kind, 'the question was asked and answered');
        $this->assertNull($quote->noticeKey(), 'and nothing payable needs no sentence');
    }

    /** Every text this quote can be shown with exists in English and in both Arabic files. */
    public function test_every_notice_reads_in_arabic_and_english(): void
    {
        $keys = [
            'customs_duty_has_not_been_quoted_for_this_country',
            'customs_duty_is_paid_by_you_on_delivery',
            'customs_duty_is_paid_by_the_seller',
            'customs_duty',
        ];

        foreach (['en', 'sa', 'ae'] as $language) {
            $messages = require resource_path("lang/{$language}/new-messages.php");

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $messages, "{$key} has no {$language} text");
                $this->assertNotSame('', trim((string) $messages[$key]));

                if ($language !== 'en') {
                    $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $messages[$key], "{$key} is not Arabic in {$language}");
                }
            }
        }
    }
}
