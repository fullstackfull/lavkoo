<?php

namespace Tests\Feature\Pos;

use App\Services\Pos\PosSaleTotal;
use App\Enums\SessionKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the two panel tills CHARGE: the server's own total, added up in whole minor units.
 *
 * Both halves of that were missing, and both are money. Measured through these same routes at the base of this branch
 * (60f5a605):
 *
 *  - THE FIGURE WAS THE BROWSER'S. `placeOrder` read `$request['amount']`, compared it against the customer's wallet
 *    balance, debited it, and wrote it to `orders.order_amount` — while the cart sat in the server's own session and
 *    the prices sat in the database. A sale of three products at 100.00 posting `amount=1.00` was charged 1.00. The
 *    same sale posting `amount=5000.00` was charged 5000.00. A wallet customer with 50.00 to their name bought
 *    300.00-worth by posting `amount=50.00`, and the balance check passed because it was the posted figure it was
 *    checking.
 *
 *  - THE ADDITION WAS IN FLOATS. Seven items at 4.35 come to 30.45. `(4.35 * 7)` in binary floating point is
 *    30.449999999999996, and `usdToDefaultCurrency` FLOORS, so the figure the panel showed, posted and was charged was
 *    30.44: the shop lost a halala on a cart its own line says costs 30.45. The first test below pins that float
 *    answer with the figures in it, so the cart is not one nobody could ring up.
 *
 * And minor units are not always cents. The currencies table carries no decimal places; the platform's one notion of a
 * currency's exponent is the `decimal_point_settings` business setting. Set to 3, as a Kuwait, Bahrain or Oman
 * installation would set it, the same sale is added up and floored in whole fils — 30.450, not 30.44 and not 30.449.
 *
 * Driven through `admin/pos/order-place` and `vendor/pos/order-place` with the body the panel's own JavaScript posts,
 * the way PosTillTestCase describes. Nothing here builds the state it is supposed to detect: the carts are carts, the
 * posted figures are figures a request can carry, and what is read back is the order row, the wallet row, the shelf and
 * the stock movements.
 *
 * These tests say nothing about who may reach the two routes — the panel guards are switched off, as PosTillTestCase
 * says. What they say is what the sale does to the money.
 */
class PosTillServerTotalTest extends PosTillTestCase
{
    /** @return array<string, array{string}> */
    public static function tills(): array
    {
        return ['admin till' => [self::ADMIN_TILL], 'vendor till' => [self::VENDOR_TILL]];
    }

    // ---- the arithmetic: whole minor units, not floats ------------------------------------------------------------

    /**
     * The float answer this platform gives for an ordinary cart, with the figures in it.
     *
     * Not a test of the tills: a statement of the arithmetic the tests below are about, so that a reader does not have
     * to take "float addition gets this wrong" on trust. If a future PHP makes this cart exact, this test fails and
     * says so rather than quietly leaving the ones below proving nothing.
     *
     * The third assertion CHANGED in the fix round. It used to read `assertSame(30.44, usdToDefaultCurrency(4.35 * 7))`
     * and say "which is what the panel shows, posts and used to be charged" — and that was the defect, not the
     * background. `usdToDefaultCurrency` floors a binary double, so it lost the halala twice over: once for the float
     * product above, and once for figures with no accumulation error at all. See the test below.
     */
    public function test_seven_items_at_four_thirty_five_come_to_one_halala_less_in_binary_floating_point(): void
    {
        $this->assertSame(30.449999999999996, 4.35 * 7, 'the float product of the line');
        $this->assertSame(30.45, (float) usdToDefaultCurrency(amount: 4.35 * 7), 'which the helper now recovers, so the panel shows and posts what the sale costs');
        $this->assertSame(30.45, (float) usdToDefaultCurrency(amount: 30.45), 'and the figure the line actually comes to is 30.45');
    }

    /**
     * `usdToDefaultCurrency` did not return the figure handed to it, even when that figure was already an exact number
     * of minor units — reviewer A's finding, and the reason the first version of this lane left a one-minor-unit gap
     * on the order row of lawful cash sales instead of closing it.
     *
     * `floor()` of a binary double is not idempotent. The double nearest 0.58 is a shade below 0.58, so `0.58 * 100` is
     * 57.99999999999999289 and the floor of it is 57. There is no accumulation anywhere in that: a single line of 0.29
     * at a quantity of 2 is enough, and `0.29 * 2` IS the correctly-rounded double for 0.58.
     *
     * The counts are the whole scale of it, measured rather than described: of the first 100000 exact minor-unit
     * figures, 4586 came back one unit low at two decimal places and 741 at three. A till ringing up ordinary
     * small-ticket prices meets them constantly.
     */
    public function test_the_money_helper_returns_the_figure_it_was_given(): void
    {
        $this->assertSame(0.58, 0.29 * 2, 'the cart has no accumulation error to fix: this IS the double for 0.58');
        $this->assertSame(0.58, (float) usdToDefaultCurrency(amount: 0.29 * 2), 'and the helper used to answer 0.57');
        $this->assertSame(1.14, (float) usdToDefaultCurrency(amount: 0.57 * 2), 'used to answer 1.13');

        $lost = 0;
        for ($minorUnits = 1; $minorUnits <= 100000; $minorUnits++) {
            if ((int) round(((float) usdToDefaultCurrency(amount: $minorUnits / 100)) * 100) !== $minorUnits) {
                $lost++;
            }
        }
        $this->assertSame(0, $lost, 'of the first 100000 two-decimal figures, 4586 came back one minor unit low before the fix');

        // And the figures that are genuinely finer than a minor unit still floor DOWN, which is what keeps a shipped
        // installation's money where it was: this is a floor, not a round.
        $this->assertSame(272.84, (float) usdToDefaultCurrency(amount: 272.849), 'a sale costing 272.849 still costs 272.84');
        $this->assertSame(13.56, (float) usdToDefaultCurrency(amount: 13.5675), 'and a tax of 13.5675 is still 13.56');
    }

    /**
     * The same helper at three decimal places, and the same floor.
     */
    public function test_the_money_helper_returns_the_figure_it_was_given_in_a_three_decimal_currency(): void
    {
        $this->givenThreeDecimalCurrency();

        $lost = 0;
        for ($minorUnits = 1; $minorUnits <= 100000; $minorUnits++) {
            if ((int) round(((float) usdToDefaultCurrency(amount: $minorUnits / 1000)) * 1000) !== $minorUnits) {
                $lost++;
            }
        }
        $this->assertSame(0, $lost, 'of the first 100000 three-decimal figures, 741 came back one fils low before the fix');
        $this->assertSame(272.849, (float) usdToDefaultCurrency(amount: 272.8495), 'and a figure finer than a fils still floors down');
    }

    /**
     * The decimal places the helper answers in are the setting's own, for every value the admin form allows.
     *
     * The first version of this lane clamped this helper to MAX_CURRENCY_DECIMAL_POINTS, so at a setting of 8 it
     * returned 1.234567 — six places — where the base branch returned eight (reviewer B, F4). That clamp is gone: it
     * belongs to PosSaleTotal's integer grid and lives there now, and this helper answers in the setting's own places
     * for every value the admin form allows.
     *
     * The figure is not the base's either, and that is the round() fix rather than the clamp: at eight places the base
     * answered 1.23456788 for 1.23456789, the same one-unit-low floor at a different exponent. Idempotent at every
     * exponent is the point.
     */
    public function test_the_money_helper_answers_in_the_settings_own_decimal_places(): void
    {
        foreach ([6 => 1.234567, 8 => 1.23456789, 12 => 1.23456789] as $decimalPoints => $expected) {
            DB::table('business_settings')->updateOrInsert(['type' => 'decimal_point_settings'], ['value' => json_encode($decimalPoints)]);
            Cache::flush();

            $this->assertSame($decimalPoints, defaultCurrencyDecimalPoints(), 'the helper reports the setting, unclamped');
            // At eight places 1.23456789 is already a whole number of minor units, so it comes back whole: the base
            // answered 1.23456788. At six it has a ninth and an eighth digit to floor away, which it still does.
            $this->assertSame($expected, (float) usdToDefaultCurrency(amount: 1.23456789), "at {$decimalPoints} places");
        }
    }

    /**
     * The sale's own integer grid IS bounded, at both ends, and the bound is PosSaleTotal's.
     *
     * Mutations MF (the ceiling raised to 20) and MG (the floor dropped) both survived the first version's tests.
     */
    public function test_the_sale_is_added_up_at_no_more_than_the_platforms_maximum_decimal_places(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'decimal_point_settings'], ['value' => json_encode(8)]);
        Cache::flush();

        // 1.23456789 at the clamped six places floors to 1.234567, i.e. 1234567 minor units of a six-decimal grid.
        $sale = PosSaleTotal::inDefaultCurrency()->plusLine(lineSubtotal: 1.23456789, appliedTaxAmount: 0);
        $this->assertSame(1234567, $sale->minorUnits(), 'six places, not eight');

        DB::table('business_settings')->updateOrInsert(['type' => 'decimal_point_settings'], ['value' => json_encode(-2)]);
        Cache::flush();

        // Below zero an exponent would MULTIPLY the sale instead of dividing it. 100.00 costs 100.00, not 10000.00.
        $sale = PosSaleTotal::inDefaultCurrency()->plusLine(lineSubtotal: 100.0, appliedTaxAmount: 0);
        $this->assertSame(100, $sale->minorUnits(), 'nought places: whole units');
        $this->assertSame(100.0, $sale->amount(), 'and the amount is not multiplied');
    }

    /**
     * A line too large to count is refused, not wrapped round into a NEGATIVE sale.
     *
     * Reviewer A's F5, measured at this lane's own first head: a cash sale of 93,000,000,000 units at 1.00 wrote
     * orders.order_amount = -91467440737.10 and took the stock for it. `is_finite(9.3e18)` is true, and
     * `(int) round(9.3e18)` is -9146744073709551616, so the unguarded cast turned a sale too big to count into a sale
     * that credits the shop. It also made the declared M6b survivor (the negative floor truncating instead of
     * flooring) reachable, which is why its declared reason was wrong.
     */
    public function test_a_line_too_large_to_count_is_refused_rather_than_wrapped_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PosSaleTotal::inDefaultCurrency()->plusLine(lineSubtotal: 93000000000.0, appliedTaxAmount: 0);
    }

    /** The same door, for a figure that is not a number at all. */
    public function test_a_line_that_is_not_a_number_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PosSaleTotal::inDefaultCurrency()->plusLine(lineSubtotal: INF, appliedTaxAmount: 0);
    }

    /**
     * And the same door for a CART too large to count, not only a line.
     *
     * I found this by attacking my own fix for reviewer A's F5: the per-line bound is not enough, because PHP does not
     * wrap an integer overflow — it turns the sum into a float, and a float handed to this class's int-typed
     * constructor raises a TypeError, which the till would answer with a 500 nobody sees. Two lines each just inside
     * the per-line bound are enough to reach it.
     */
    public function test_a_cart_whose_lines_together_are_too_large_to_count_is_refused(): void
    {
        $justInsideTheLineBound = PHP_INT_MAX / (10 ** 8) - 1;

        $this->expectException(InvalidArgumentException::class);

        PosSaleTotal::inDefaultCurrency()
            ->plusLine(lineSubtotal: $justInsideTheLineBound, appliedTaxAmount: 0)
            ->plusLine(lineSubtotal: $justInsideTheLineBound, appliedTaxAmount: 0);
    }

    /**
     * And the till itself answers that refusal in a shape the panel can show, rather than a 500 nobody sees.
     *
     * Neither panel's place-order $.post defines an `error:` callback (reviewer B, F6), so an exception out of this
     * action leaves the cashier with a stopped spinner and no message at all.
     */
    #[DataProvider('tills')]
    public function test_a_cart_too_large_to_count_is_refused_with_a_message_and_writes_nothing(string $till): void
    {
        // A high PRICE rather than a huge quantity, because `products.current_stock` is a 32-bit integer and MariaDB
        // clamps a stock of 1e14 to 2147483647 where SQLite stores it whole. The bound is on what a LINE comes to:
        // PHP_INT_MAX / 10 ** (2 + 6) is about 9.2234e10, and five at 20,000,000,000.00 is 1e11.
        $this->shelfProduct(id: 760, stock: 100, till: $till, price: 20000000000.0);
        $this->givenCartAtTill($till, [760 => ['quantity' => 5, 'price' => 20000000000.0]]);

        $response = $this->placeSaleAtTill($till, amount: 1.0);

        $this->assertSame(200, $response->status(), 'the panel gets an answer, not a 500');
        $this->assertSame('[]', $response->content(), 'in the shape it already reloads on');
        $this->assertSame(['error'], $this->toastTypes());
        // Measured at this lane's first head a096d9dd on the equivalent cart: one order charged -91467440737.10,
        // with the stock taken for it.
        $this->assertSame(0, DB::table('orders')->count(), 'and no order at all');
        $this->assertSame(100, (int) DB::table('products')->where('id', 760)->value('current_stock'), 'the shelf is untouched');
    }

    /**
     * The sale's own arithmetic, measured where it lives.
     *
     * A percentage extra discount is a share of what the LINES cost, not of what they cost plus the VAT, so the figure
     * `CartService::getTotalCalculation` is handed to resolve that percentage has to be the subtotal alone. My own
     * first version of this class added the tax into it, which on a 15 % VAT cart with a 10 % extra discount would have
     * discounted the tax as well and undercharged the sale — a defect no till test in this file would have caught,
     * because the tills' own fixtures here carry no tax. This is the test that catches it.
     */
    public function test_the_subtotal_a_percentage_discount_is_a_share_of_leaves_the_tax_out_of_it(): void
    {
        $sale = PosSaleTotal::inDefaultCurrency()
            ->plusLine(lineSubtotal: 100.0, appliedTaxAmount: 15.0)
            ->plusLine(lineSubtotal: 40.0, appliedTaxAmount: 6.0);

        $this->assertSame(140.0, $sale->lineSubtotalInBaseCurrency(), 'the lines, without their tax');
        $this->assertSame(16100, $sale->minorUnits(), 'while the sale costs the lines and the tax');
        $this->assertSame(14700, $sale->lessCartDiscounts(couponDiscount: 0, extraDiscount: 14.0)->minorUnits(), 'ten per cent of the lines comes off the sale');
    }

    /** The exponent comes from the store's setting, and the sale is floored at it rather than rounded. */
    public function test_the_sale_is_floored_at_the_stores_own_decimal_places(): void
    {
        $this->assertSame(27284, PosSaleTotal::inDefaultCurrency()
            ->plusLine(lineSubtotal: 237.26, appliedTaxAmount: 35.589)->minorUnits(), 'a sale costing 272.849 costs 272.84, as every other figure this platform shows is floored');

        $this->givenThreeDecimalCurrency();
        $this->assertSame(272849, PosSaleTotal::inDefaultCurrency()
            ->plusLine(lineSubtotal: 237.26, appliedTaxAmount: 35.589)->minorUnits(), 'and 272.849 where the currency has three places');
    }

    #[DataProvider('tills')]
    public function test_a_cart_float_addition_loses_a_halala_on_is_charged_what_its_lines_come_to(string $till): void
    {
        $this->shelfProduct(id: 711, stock: 20, till: $till, price: 4.35);
        $this->givenCartAtTill($till, [711 => ['quantity' => 7, 'price' => 4.35]]);

        // 30.44 is what the panel's own cart summary renders into the hidden `amount` field for this cart.
        $this->placeSaleAtTill($till, amount: 30.44);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605 through this same route: 3044 halalas.
        $this->assertSame(3045, $this->chargedMinorUnits(), 'the sale costs what its line costs, to the halala');
        $this->assertSame(3044, $this->paidMinorUnits(), 'the cash the cashier says was handed over is recorded as handed over, not rewritten');
    }

    /**
     * A byte-identity proof, not a discriminating one: this test passes at 60f5a605 too, and is here to say that a
     * shipped installation's figure does NOT move on a cart whose float addition never crossed a minor unit.
     */
    #[DataProvider('tills')]
    public function test_a_cart_float_addition_gets_right_is_charged_exactly_what_it_was_charged_before(string $till): void
    {
        $this->shelfProduct(id: 712, stock: 20, till: $till, price: 19.99);
        $this->shelfProduct(id: 713, stock: 20, till: $till, price: 5.25);
        $this->givenCartAtTill($till, [
            712 => ['quantity' => 3, 'price' => 19.99],
            713 => ['quantity' => 2, 'price' => 5.25],
        ]);

        $this->assertSame(70.47, (float) usdToDefaultCurrency(amount: 19.99 * 3 + 5.25 * 2), 'float addition does not cross a halala on this cart');

        $this->placeSaleAtTill($till, amount: 70.47);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605 through this same route: 7047 halalas. A shipped installation's figure does not move.
        $this->assertSame(7047, $this->chargedMinorUnits());
    }

    #[DataProvider('tills')]
    public function test_the_same_cart_in_a_three_decimal_currency_is_charged_to_the_fils(string $till): void
    {
        $this->givenThreeDecimalCurrency();
        $this->shelfProduct(id: 714, stock: 20, till: $till, price: 4.35);
        $this->givenCartAtTill($till, [714 => ['quantity' => 7, 'price' => 4.35]]);

        // At three decimal places the panel used to floor the same float one thousandth low instead of one hundredth
        // low, and post 30.449. With the helper fixed it posts the figure the lines come to, in whole fils.
        $this->assertSame(30.45, (float) usdToDefaultCurrency(amount: 4.35 * 7));

        $this->placeSaleAtTill($till, amount: 30.45);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605 with the same setting: 30449 fils.
        $this->assertSame(30450, $this->chargedMinorUnits(exponent: 3), 'whole fils, not whole halalas and not the floored float');
    }

    /**
     * The third decimal place is kept only when the platform is told the currency has one — and the request does not
     * get a say either way.
     *
     * The same cart, 2.125 + 2 x 1.230, costs 4.585 in a three-decimal currency and 4.58 in a two-decimal one, where
     * the half-fils is floored away as every other figure in a two-decimal installation is. Each half posts the OTHER
     * currency's figure, which is the mistake a client makes on a six-market platform, and is charged its own.
     */
    #[DataProvider('tills')]
    public function test_the_decimal_places_the_sale_is_charged_in_are_the_stores_not_the_requests(string $till): void
    {
        $this->shelfProduct(id: 715, stock: 20, till: $till, price: 2.125);
        $this->shelfProduct(id: 716, stock: 20, till: $till, price: 1.230);
        $lines = [715 => ['quantity' => 1, 'price' => 2.125], 716 => ['quantity' => 2, 'price' => 1.230]];

        // Two decimal places, and a request carrying the three-decimal figure. Measured at 60f5a605: 4585.
        $this->givenCartAtTill($till, $lines);
        $this->placeSaleAtTill($till, amount: 4.585);
        $this->assertSame(4580, $this->chargedMinorUnits(exponent: 3), 'two decimal places: the half-fils is floored away');

        // Three decimal places, and a request carrying the two-decimal figure. Measured at 60f5a605: 4580.
        $this->givenThreeDecimalCurrency();
        $this->givenCartAtTill($till, $lines);
        $this->placeSaleAtTill($till, amount: 4.58);
        $this->assertSame(4585, $this->chargedMinorUnits(exponent: 3), 'three decimal places: it is charged');
    }

    // ---- the figure is the server's, whatever the browser posted -------------------------------------------------

    /**
     * The other byte-identity proof, and it passes at 60f5a605 as well: when the browser posts what the sale costs,
     * every one of the four readings is the figure it was before. This is the Saudi path, unchanged.
     */
    #[DataProvider('tills')]
    public function test_a_sale_whose_posted_amount_is_what_it_costs_is_charged_exactly_what_it_was_charged_before(string $till): void
    {
        $this->shelfProduct(id: 721, stock: 10, till: $till);
        $this->givenCartAtTill($till, [721 => 3]);

        $this->placeSaleAtTill($till, amount: 300.0);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605 through this same route: one order, 300.00 charged, stock 7, one movement of -3.
        $this->assertSame([
            'orders' => 1,
            'order_details' => 1,
            'charged' => 300.0,
            'current_stock' => 7,
            'movements' => [['qty_change' => -3, 'balance_after' => 7]],
        ], $this->readings(721));
        $this->assertSame(30000, $this->paidMinorUnits());
    }

    #[DataProvider('tills')]
    public function test_a_sale_posting_less_than_it_costs_is_not_charged_the_lower_figure(string $till): void
    {
        $this->shelfProduct(id: 722, stock: 10, till: $till);
        $this->givenCartAtTill($till, [722 => 3]);

        $this->placeSaleAtTill($till, amount: 1.0);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605: charged 1.00 for three products at 100.00.
        $this->assertSame(30000, $this->chargedMinorUnits(), 'the sale costs 300.00 whatever the request said');
        $this->assertSame(30000, $this->initialMinorUnits(), 'and the amount the order started at says the same');
        // The deliberate consequence of leaving the tendered figure alone: the order records 300.00 owed against the
        // 1.00 the request claimed was handed over. At 60f5a605 both read 1.00 and the 299.00 went unrecorded.
        $this->assertSame(100, $this->paidMinorUnits(), 'what the cashier says was taken is recorded as said, and the shortfall is now visible');
    }

    #[DataProvider('tills')]
    public function test_a_sale_posting_more_than_it_costs_is_not_charged_the_higher_figure(string $till): void
    {
        $this->shelfProduct(id: 723, stock: 10, till: $till);
        $this->givenCartAtTill($till, [723 => 3]);

        $this->placeSaleAtTill($till, amount: 5000.0);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605: charged 5000.00.
        $this->assertSame(30000, $this->chargedMinorUnits());
        $this->assertSame(500000, $this->paidMinorUnits(), 'and the overpayment the request claimed is recorded as claimed, against a sale of 300.00');
    }

    /**
     * A cart carrying the discounts the cashier added to it, not to a line: they come off the server's figure.
     *
     * The extra discount is a PERCENTAGE, which `CartService::getTotalCalculation` resolves against what the lines come
     * to — so this is the test that the integer subtotal reaches it and that the resolved amount is taken off.
     */
    #[DataProvider('tills')]
    public function test_the_carts_own_coupon_and_percentage_discount_come_off_the_server_figure(string $till): void
    {
        $this->shelfProduct(id: 731, stock: 10, till: $till);
        $cartId = $this->givenCartAtTill($till, [731 => 3]);
        $this->withSession([$cartId => array_merge((array) session($cartId), [
            'coupon_discount' => 25.0,
            'ext_discount' => 10,
            'ext_discount_type' => 'percent',
        ])]);

        // 300.00 of lines, 25.00 off for the coupon, and the platform's own rule takes the percentage on what is left
        // of the lines after the coupon: ten per cent of 275.00 is 27.50, so the sale costs 247.50.
        $this->placeSaleAtTill($till, amount: 300.0);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605: charged 30000, the figure the request carried.
        $this->assertSame(24750, $this->chargedMinorUnits(), 'the lines, less the coupon, less ten per cent of what the coupon left');
    }

    /** An amount extra discount, which needs no resolving, comes off as well. */
    #[DataProvider('tills')]
    public function test_an_amount_extra_discount_comes_off_the_server_figure(string $till): void
    {
        $this->shelfProduct(id: 732, stock: 10, till: $till);
        $cartId = $this->givenCartAtTill($till, [732 => 3]);
        $this->withSession([$cartId => array_merge((array) session($cartId), [
            'ext_discount' => 12.5,
            'ext_discount_type' => 'amount',
        ])]);

        $this->placeSaleAtTill($till, amount: 300.0);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605: charged 30000.
        $this->assertSame(28750, $this->chargedMinorUnits());
    }

    /**
     * A wallet sale that does not cost a positive amount is refused instead of CREDITING the customer.
     *
     * `$balance < -100` is false of every balance, so the balance comparison alone lets such a sale through, and
     * `createWalletTransaction` treats `order_place` as a debit — a debit of a negative amount ADDS to the balance. At
     * 60f5a605 the posted figure was what was debited, so the same cart with `amount=300.00` took 300.00 from a
     * customer for a cart worth less than nothing; with the server's figure and no guard it would hand them 100.00.
     *
     * The cart is crafted: the panel's own discount route refuses a discount this large
     * (POSController::updateDiscount answers `amount_low` when the total would go below zero), so this is the second
     * line of that defence.
     */
    #[DataProvider('tills')]
    public function test_a_wallet_sale_discounted_past_nothing_is_refused_and_credits_no_one(string $till): void
    {
        $this->walletCustomer(id: 57, balance: 300.0);
        $this->shelfProduct(id: 733, stock: 10, till: $till);
        $cartId = $this->givenCartAtTill($till, [733 => 3], customerId: 57);
        $this->withSession([$cartId => array_merge((array) session($cartId), [
            'ext_discount' => 400.0,
            'ext_discount_type' => 'amount',
        ])]);

        $response = $this->placeSaleAtTill($till, amount: 300.0, extra: ['type' => 'wallet', 'paid_amount' => 300.0]);

        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame('[]', $response->content(), 'the answer the panel already handles');
        $this->assertSame(300.0, (float) DB::table('users')->where('id', 57)->value('wallet_balance'), 'neither taken nor given');
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame([
            'orders' => 0,
            'order_details' => 0,
            'charged' => null,
            'current_stock' => 10,
            'movements' => [],
        ], $this->readings(733), 'no order and nothing taken off the shelf');
    }

    // ---- the wallet ---------------------------------------------------------------------------------------------

    #[DataProvider('tills')]
    public function test_a_wallet_sale_posting_less_than_it_costs_debits_what_it_costs(string $till): void
    {
        $this->walletCustomer(id: 51, balance: 900.0);
        $this->shelfProduct(id: 724, stock: 10, till: $till);
        $this->givenCartAtTill($till, [724 => 3], customerId: 51);

        $this->placeSaleAtTill($till, amount: 1.0, extra: ['type' => 'wallet', 'paid_amount' => 1.0]);

        $this->assertSame(['success'], $this->toastTypes());
        // Measured at 60f5a605: balance 899.00, one wallet row debiting 1.00.
        $this->assertSame(600.0, (float) DB::table('users')->where('id', 51)->value('wallet_balance'));
        $this->assertSame([['debit' => 30000, 'balance' => 60000]], $this->walletRowsInMinorUnits());
        $this->assertSame(30000, $this->chargedMinorUnits());
    }

    /**
     * The refusal this criterion is really about: a balance that covers the figure the browser posted and not the sale.
     *
     * Everything is asserted absent, not assumed absent: the balance, the wallet rows, the order rows, the order lines,
     * the shelf and the movement rows.
     */
    #[DataProvider('tills')]
    public function test_a_wallet_sale_the_balance_cannot_cover_is_refused_and_leaves_everything_as_it_was(string $till): void
    {
        $this->walletCustomer(id: 52, balance: 50.0);
        $this->shelfProduct(id: 725, stock: 10, till: $till);
        $cartId = $this->givenCartAtTill($till, [725 => 3], customerId: 52);
        $this->withSession([$cartId => session($cartId) + ['coupon_discount' => 0.0, 'coupon_code' => 'KEEPME']]);

        $response = $this->placeSaleAtTill($till, amount: 50.0, extra: ['type' => 'wallet', 'paid_amount' => 50.0]);

        $this->assertSame(['error'], $this->toastTypes(), 'the cashier is told, in the toast the panel shows after its reload');
        $this->assertSame(200, $response->status());
        $this->assertSame('[]', $response->content(), 'the empty answer the panel JavaScript already handles: it reloads and shows the toast');

        // Measured at 60f5a605: the sale went through on the posted 50.00 — balance 0.00, one wallet row, one order
        // charged 50.00, stock 7 and a movement of -3.
        $this->assertSame(50.0, (float) DB::table('users')->where('id', 52)->value('wallet_balance'), 'the balance is untouched');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'no wallet row');
        $this->assertSame([
            'orders' => 0,
            'order_details' => 0,
            'charged' => null,
            'current_stock' => 10,
            'movements' => [],
        ], $this->readings(725), 'no order, no line, the shelf untouched and no movement row');
        $this->assertNull($this->chargedMinorUnits(), 'there is no order row to have been charged anything');

        // The four absences the first version of this test did not assert, which reviewer B checked by hand and found
        // held: an order-tax row, the sale's own `last_order` marker, and the cart with its coupon still in the
        // session under the SAME id — the base's equivalent success path forgot the cart and minted a new walk-in id,
        // so a cashier who has been refused can correct the sale and try again.
        $this->assertSame(0, DB::table('order_taxes')->count(), 'nor an order-tax row');
        $this->assertNull(session('last_order'), 'and nothing was marked as the last order placed');
        $this->assertSame('KEEPME', session($cartId)['coupon_code'] ?? null, 'the cart keeps its coupon');
        $this->assertSame(725, session($cartId)[0]['id'] ?? null, 'and its line');
        $this->assertSame($cartId, session(SessionKey::CURRENT_USER), 'under the same cart id: no new cart was minted');
    }

    /** The boundary the refusal sits on, measured against the SERVER's figure: a customer may spend the whole balance. */
    #[DataProvider('tills')]
    public function test_a_wallet_sale_for_exactly_the_balance_goes_through_on_the_server_figure(string $till): void
    {
        $this->walletCustomer(id: 53, balance: 300.0);
        $this->shelfProduct(id: 726, stock: 10, till: $till);
        $this->givenCartAtTill($till, [726 => 3], customerId: 53);

        $this->placeSaleAtTill($till, amount: 1.0, extra: ['type' => 'wallet', 'paid_amount' => 1.0]);

        $this->assertSame(['success'], $this->toastTypes(), 'the balance covers the sale exactly, so it is not refused');
        $this->assertSame(0.0, (float) DB::table('users')->where('id', 53)->value('wallet_balance'));
        $this->assertSame(30000, $this->chargedMinorUnits());
    }

    /** A wallet sale records as paid what it took, not what the request claimed was paid. */
    #[DataProvider('tills')]
    public function test_a_wallet_sale_records_as_paid_the_figure_it_debited(string $till): void
    {
        $this->walletCustomer(id: 54, balance: 900.0);
        $this->shelfProduct(id: 727, stock: 10, till: $till);
        $this->givenCartAtTill($till, [727 => 3], customerId: 54);

        $this->placeSaleAtTill($till, amount: 7.0, extra: ['type' => 'wallet', 'paid_amount' => 7.0]);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame(30000, $this->paidMinorUnits(), 'measured at 60f5a605: 7.00 recorded as paid');
    }

    // ---- what the base branch settled, still settled -------------------------------------------------------------

    /**
     * Cash for a delivery order is collected on delivery, so no tendered amount is required or recorded — the rule the
     * base branch wrote down. The sale is still charged the server's figure.
     */
    #[DataProvider('tills')]
    public function test_a_delivery_order_records_no_tendered_cash_and_is_still_charged_the_server_total(string $till): void
    {
        $this->walletCustomer(id: 55, balance: 0.0);
        $this->shelfProduct(id: 728, stock: 10, till: $till);
        $this->givenCartAtTill($till, [728 => 3], customerId: 55);

        $this->actingAtTill($till)->post($this->placeOrderUrl($till), [
            'amount' => 1.0, 'type' => 'cash', 'fulfillment' => 'delivery',
        ]);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame(30000, $this->chargedMinorUnits());
        $this->assertSame(0, $this->paidMinorUnits(), 'a delivery order collects its cash on delivery');
        $this->assertSame('cash_on_delivery', DB::table('orders')->orderByDesc('id')->value('payment_method'));
    }

    /**
     * A cart whose every line names a product that is no longer there takes no money from a wallet.
     *
     * At 60f5a605 the order was charged whatever `amount` the request carried and a WALLET sale debited it: 300.00
     * taken for an order with no lines at all. The server's figure for a cart with nothing sellable in it is nothing,
     * and a wallet sale that costs nothing is refused, so the balance is untouched and no order is written either.
     *
     * A CASH sale of the same cart is refused too, as of the fix round. It was not before: the
     * `count($cartListSession) > 0` guard counts cart LINES, not lines the till can sell, so an order row with no lines
     * was written — charged the posted 300.00 at 60f5a605, and 0.00 at this lane's first head with the tendered 300.00
     * still recorded beside it, which left paid_amount ABOVE order_amount (reviewer B, F8). The seller app's till was
     * given a refusal for exactly this in the base branch; the two panel tills have it now, with the same key.
     */
    #[DataProvider('tills')]
    public function test_a_wallet_sale_whose_lines_name_no_product_that_exists_takes_no_money(string $till): void
    {
        $this->walletCustomer(id: 56, balance: 300.0);
        $this->givenCartAtTill($till, [729 => 3], customerId: 56);

        $this->placeSaleAtTill($till, amount: 300.0, extra: ['type' => 'wallet', 'paid_amount' => 300.0]);

        // Measured at 60f5a605: balance 0.00, one wallet row debiting 300.00, one order charged 300.00, no lines.
        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame(300.0, (float) DB::table('users')->where('id', 56)->value('wallet_balance'), 'nothing was taken for nothing');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'and no wallet row at all');
        $this->assertSame(0, DB::table('orders')->count(), 'nor an order');
    }

    /** The same cart in cash: refused outright, no junk order row at all. */
    #[DataProvider('tills')]
    public function test_a_cash_sale_whose_lines_name_no_product_that_exists_is_refused(string $till): void
    {
        $this->givenCartAtTill($till, [729 => 3]);

        $response = $this->placeSaleAtTill($till, amount: 300.0);

        // Measured at 60f5a605: one order charged 300.00 with no lines. At a096d9dd: charged 0.00, tendered 300.00.
        $this->assertSame('[]', $response->content(), 'the shape the panel already reloads on');
        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame(0, DB::table('orders')->count(), 'and no order row at all');
        $this->assertSame(0, DB::table('order_details')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /**
     * And the refusal above cannot pre-empt the one the base branch pins for a line of fewer than one unit.
     *
     * That line's product EXISTS, so the server can price it and the sale reaches InventoryService::takeForSale, which
     * is where a quantity the shelf cannot cover belongs. The implementer's first attempt at the non-positive guard put
     * it on the sale as a whole and broke exactly this; the test is here so the next reader does not repeat it.
     *
     * This one PASSES at 60f5a605, because the base branch is where the `stockShortfall` refusal came from. It is a
     * regression guard on the lineless refusal added above it, not a discriminating test.
     */
    #[DataProvider('tills')]
    public function test_a_line_of_fewer_than_one_unit_is_still_the_shelfs_refusal_and_not_the_lineless_one(string $till): void
    {
        $this->shelfProduct(id: 731, stock: 10, till: $till);
        $this->givenCartAtTill($till, [731 => ['quantity' => -4]]);

        $response = $this->placeSaleAtTill($till, amount: 100.0);

        $this->assertSame(
            ['stockShortfall' => true, 'message' => 'Not enough stock to place this order'],
            $response->json(),
            'the shelf answers, with the key the base branch pins',
        );
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(10, (int) DB::table('products')->where('id', 731)->value('current_stock'));
    }

    // ---- what the till COLLECTS, not only what it records ---------------------------------------------------------

    /**
     * The figure the panel asks the cashier for IS the figure the sale charges, so the drawer and the books agree.
     *
     * This is the finding both reviewers raised first and it was the lane's own worst defect. The first version charged
     * the server's figure and left the TENDERED figure the panel's, so an ordinary cash sale recorded order_amount 0.58
     * against paid_amount 0.57, payment_status 'paid' — a halala of revenue the drawer never held, on a sale the
     * cashier considers settled, booked by no report. Measured on both tills and both engines at a096d9dd. And the
     * cashier could not tender the right figure: the panel's own `min` was the low one too.
     *
     * The cart is deliberately the smallest one that shows it: ONE line, 0.29 at a quantity of 2, with no accumulation
     * to blame. What the panel posts is computed here through the very helper the panel's blade calls, so this is the
     * body the panel's own JavaScript sends and not a figure invented for the test.
     */
    #[DataProvider('tills')]
    public function test_what_the_panel_asks_the_cashier_for_is_what_the_sale_charges(string $till): void
    {
        $this->shelfProduct(id: 740, stock: 10, till: $till, price: 0.29);
        $this->givenCartAtTill($till, [740 => ['quantity' => 2, 'price' => 0.29]]);

        // resources/views/{admin-views,vendor-views}/pos/partials/_cart.blade.php renders the hidden `amount`, the
        // tendered input's value and its `min` from exactly this call.
        $posted = (float) usdToDefaultCurrency(amount: 0.29 * 2);
        $this->assertSame(0.58, $posted, 'the panel used to render, post and demand 0.57');

        $this->placeSaleAtTill($till, amount: $posted);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame(58, $this->chargedMinorUnits(), 'charged what the lines come to');
        $this->assertSame(58, $this->paidMinorUnits(), 'and collected the same figure: no gap on the row');
        $this->assertSame($this->chargedMinorUnits(), $this->paidMinorUnits(), 'an instant cash sale marked paid in full really is');
    }

    /**
     * The same agreement on the cart whose float addition crosses a halala, which is the other way in.
     */
    #[DataProvider('tills')]
    public function test_the_float_error_cart_collects_what_it_charges(string $till): void
    {
        $this->shelfProduct(id: 741, stock: 20, till: $till, price: 4.35);
        $this->givenCartAtTill($till, [741 => ['quantity' => 7, 'price' => 4.35]]);

        $posted = (float) usdToDefaultCurrency(amount: 4.35 * 7);
        $this->assertSame(30.45, $posted, 'the panel used to render, post and demand 30.44');

        $this->placeSaleAtTill($till, amount: $posted);

        // Measured at a096d9dd: charged 3045, tendered 3044. At 60f5a605: charged 3044, tendered 3044.
        $this->assertSame(3045, $this->chargedMinorUnits());
        $this->assertSame(3045, $this->paidMinorUnits(), 'the halala the panel used to lose is collected, not recorded as owed');
    }

    // ---- tax: the sale's own VAT, in the same whole minor units ---------------------------------------------------

    /**
     * A real 15 per cent VAT through the real route, which the first version of this lane said it could not build.
     *
     * It can: one row in `taxes` and one in `system_tax_setups` — the module's own tables, which the till harness
     * already migrates — plus a cache flush. Reviewer B found that and it matters, because WITHOUT a tax fixture the
     * guard-digit count at the heart of the arithmetic was pinned only at "not zero": GUARD_DIGITS 6 -> 1 survived
     * every test in this file and changed a VAT-bearing charge by a halala.
     *
     * The cart: 7 x 4.35 and 3 x 19.99, subtotal 90.42, VAT 13.563, total 103.983. At two decimal places the sale
     * floors to 103.98 and the VAT the order records is the same integer arithmetic, 13.56 — not the 13.563 the float
     * accumulator used to write into a two-decimal store (reviewer A's F4, reviewer B's F3).
     */
    #[DataProvider('tills')]
    public function test_a_real_vat_is_charged_and_recorded_in_whole_minor_units(string $till): void
    {
        $this->givenOrderWiseVat(rate: 15.0);
        $this->shelfProduct(id: 742, stock: 20, till: $till, price: 4.35);
        $this->shelfProduct(id: 743, stock: 20, till: $till, price: 19.99);
        $this->givenCartAtTill($till, [742 => ['quantity' => 7, 'price' => 4.35], 743 => ['quantity' => 3, 'price' => 19.99]]);

        $this->placeSaleAtTill($till, amount: (float) usdToDefaultCurrency(amount: 90.42 * 1.15));

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame(10398, $this->chargedMinorUnits(), 'the lines and the VAT, floored once');
        $this->assertSame(10398, $this->paidMinorUnits(), 'collected what was charged');
        // Read at THREE places in a TWO-place store, which is the whole point: the stored tax used to be 13.563, a
        // figure with a third decimal in a store that has two. Reading it in halalas would round 13.563 to 1356 and
        // hide the difference — measured, and it is why the first version of this assertion passed at 60f5a605.
        $this->assertSame(13560, $this->taxMinorUnits(exponent: 3), 'a whole number of halalas: 60f5a605 stored 13563');
    }

    /**
     * The same cart in a three-decimal currency: 103.983, and a VAT of 13.563 that is now exactly 13563 fils.
     */
    #[DataProvider('tills')]
    public function test_a_real_vat_in_a_three_decimal_currency_is_charged_to_the_fils(string $till): void
    {
        $this->givenThreeDecimalCurrency();
        $this->givenOrderWiseVat(rate: 15.0);
        $this->shelfProduct(id: 744, stock: 20, till: $till, price: 4.35);
        $this->shelfProduct(id: 745, stock: 20, till: $till, price: 19.99);
        $this->givenCartAtTill($till, [744 => ['quantity' => 7, 'price' => 4.35], 745 => ['quantity' => 3, 'price' => 19.99]]);

        $this->placeSaleAtTill($till, amount: (float) usdToDefaultCurrency(amount: 90.42 * 1.15));

        // Measured at 60f5a605 with the same setting: charged 103982, i.e. one fils short of what the lines come to.
        $this->assertSame(103983, $this->chargedMinorUnits(exponent: 3));
        $this->assertSame(13563, $this->taxMinorUnits(exponent: 3));
        $this->assertSame(103983, $this->paidMinorUnits(exponent: 3));
    }

    /**
     * And the guard-digit count is pinned by a figure that moves if it is wrong.
     *
     * One line of 0.33 with 15 per cent VAT comes to 0.33 + 0.0495 = 0.3795, which floors to 0.37. With the guard cut
     * to one digit below a minor unit the tax rounds to 0.05 before the addition and the customer is charged 0.38 —
     * reviewer A measured exactly that through the real route with mutation MA1.
     */
    #[DataProvider('tills')]
    public function test_a_tax_finer_than_a_minor_unit_is_not_rounded_before_the_sale_is_added_up(string $till): void
    {
        $this->givenOrderWiseVat(rate: 15.0);
        $this->shelfProduct(id: 746, stock: 10, till: $till, price: 0.33);
        $this->givenCartAtTill($till, [746 => ['quantity' => 1, 'price' => 0.33]]);

        $this->placeSaleAtTill($till, amount: (float) usdToDefaultCurrency(amount: 0.3795));

        $this->assertSame(37, $this->chargedMinorUnits(), 'the exact 0.3795 floored once, not a tax rounded up first');
        $this->assertSame(4, $this->taxMinorUnits(), 'and the recorded VAT floors with it');
    }

    // ---- multi-currency: what it does, measured rather than assumed -----------------------------------------------

    /**
     * What a converted currency actually stores, which is NOT a whole number of minor units — and is pinned here so
     * that deleting the conversion cannot pass unnoticed.
     *
     * Both reviewers found the same hole: no test in this repository put the tills in multi-currency mode, so mutation
     * ME / R6 — `$rate = 1.0` in PosSaleTotal::minorUnits, which deletes the whole conversion — left all 37 tests
     * green while a 114.18 SAR sale charged 8.12. Reviewer B's R4, dropping currencyConverter from the balance
     * comparison, survived for the same reason.
     *
     * What this pins is the behaviour as it is, honestly: the sale is added up and CHARGED in whole minor units of the
     * currency the store charges in (11418 halalas of SAR at a rate of 3.75), and then STORED in base units by
     * `getPOSOrderData`'s own currencyConverter — 30.448, which is a whole number of minor units of nothing. That
     * round trip is the platform's existing storage convention and the open owner decisions DC-06/DC-07; this lane
     * preserves it rather than changing figures in multi-currency installations, and the criterion is ruled on
     * accordingly. The figures below are identical at 60f5a605.
     */
    #[DataProvider('tills')]
    public function test_a_converted_currency_charges_whole_minor_units_and_stores_the_base_round_trip(string $till): void
    {
        $this->givenConvertedCurrency(rate: 3.75);
        $this->walletCustomer(id: 58, balance: 1000.0);
        $this->shelfProduct(id: 747, stock: 20, till: $till, price: 4.35);
        $this->givenCartAtTill($till, [747 => ['quantity' => 7, 'price' => 4.35]], customerId: 58);

        $this->assertSame(3.75, defaultCurrencyRate(), 'the rate the whole platform converts at');
        $this->assertSame(114.18, (float) usdToDefaultCurrency(amount: 4.35 * 7), 'what the panel shows and posts');

        $this->placeSaleAtTill($till, amount: 114.18, extra: ['type' => 'wallet', 'customer_id' => 58]);

        $this->assertSame(['success'], $this->toastTypes());
        // 11418 halalas of SAR is the figure CHARGED; 30.448 base units is what the column holds after the round trip.
        $this->assertSame(3044800, $this->chargedMinorUnits(exponent: 5), 'the stored base figure, to five places');
        $this->assertSame(11418, (int) round(((float) DB::table('orders')->orderByDesc('id')->value('order_amount')) * 3.75 * 100), 'which converts back to a whole number of halalas of SAR');
        $this->assertSame(
            [['debit' => 3045, 'balance' => 96955]],
            $this->walletRowsInMinorUnits(),
            'and the wallet was debited the same base figure, not the unconverted one',
        );
    }

    /**
     * The balance a converted-currency wallet sale is refused on is the converted one.
     *
     * A balance of 100.00 base units is 375.00 SAR, which does not cover a 114.18 SAR sale costing 30.448 base
     * units... it does. The figures that matter are the ones either side of the boundary: 30.00 base units does not
     * cover it and 31.00 does. Dropping the conversion from either side of that comparison changes which sales are
     * refused, and nothing used to notice.
     *
     * This one PASSES at 60f5a605 — the base refused the same cart, on the posted figure instead of the server's. It
     * is here to kill reviewer B's mutations R4 and R6, not to show a change.
     */
    #[DataProvider('tills')]
    public function test_a_converted_currency_wallet_sale_is_refused_on_the_converted_balance(string $till): void
    {
        $this->givenConvertedCurrency(rate: 3.75);
        $this->walletCustomer(id: 59, balance: 30.0);
        $this->shelfProduct(id: 748, stock: 20, till: $till, price: 4.35);
        $this->givenCartAtTill($till, [748 => ['quantity' => 7, 'price' => 4.35]], customerId: 59);

        $this->placeSaleAtTill($till, amount: 114.18, extra: ['type' => 'wallet', 'customer_id' => 59]);

        $this->assertSame(['error'], $this->toastTypes(), '30.00 base units does not cover 30.448');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(30.0, (float) DB::table('users')->where('id', 59)->value('wallet_balance'));
        $this->assertSame(20, (int) DB::table('products')->where('id', 748)->value('current_stock'));
    }

    // ---- which lines the sale is priced from ----------------------------------------------------------------------

    /**
     * The server total covers every line the sale SHIPS, which is not the set the panel's summary displays.
     *
     * Reviewer B found the divergence: `calculateCartItemsData`, which builds the panel's total and the hidden
     * `amount`, filters cart lines by `customerId`; the sale's own loop does not, and writes an order_details row for
     * every line whose product exists. So on a cart holding a line for another customer the panel shows 20.00 while
     * 120.00 of goods leave the shelf. `getPosSaleTotal` follows the SALE, because what a sale costs is what it ships
     * — and that is the rule pinned here rather than left to chance: reviewer B's mutation R7, which gives
     * getPosSaleTotal the panel's filter, charged 20.00 for 120.00 of goods with every test still green.
     *
     * Neither reviewer could reach this cart through a panel route, and nor can I: POSService's
     * UpdateSessionWhenCustomerChange rewrites every line's customerId. It is recorded as the invariant it is.
     */
    #[DataProvider('tills')]
    public function test_the_sale_is_priced_from_every_line_it_ships(string $till): void
    {
        $this->walletCustomer(id: 60, balance: 900.0);
        $this->shelfProduct(id: 749, stock: 100, till: $till, price: 10.0);
        $this->shelfProduct(id: 750, stock: 100, till: $till, price: 25.0);
        $this->givenMixedCustomerCartAtTill($till, servedCustomerId: 60, servedLine: [749 => ['quantity' => 2, 'price' => 10.0]], otherLine: [750 => ['quantity' => 4, 'price' => 25.0]]);

        $this->placeSaleAtTill($till, amount: 20.0, extra: ['type' => 'wallet', 'customer_id' => 60]);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame(2, DB::table('order_details')->count(), 'both lines ship');
        $this->assertSame(12000, $this->chargedMinorUnits(), 'so both lines are charged: 20.00 at 60f5a605');
        $this->assertSame([['debit' => 12000, 'balance' => 78000]], $this->walletRowsInMinorUnits());
    }

    /**
     * A percentage extra discount is a share of what EVERY shipped line comes to, not of the served customer's alone.
     *
     * This is what `lineSubtotalInBaseCurrency()` is for, and without it the figure is `getCartTotalDiscountPrice`'s
     * float, which counts only the served customer's lines — reviewer A's mutation MS, which survived every test in
     * the first version because no cart there held a line for a second customer.
     */
    #[DataProvider('tills')]
    public function test_a_percentage_discount_is_a_share_of_every_line_the_sale_ships(string $till): void
    {
        $this->walletCustomer(id: 61, balance: 900.0);
        $this->shelfProduct(id: 751, stock: 100, till: $till, price: 10.0);
        $this->shelfProduct(id: 752, stock: 100, till: $till, price: 25.0);
        $cartId = $this->givenMixedCustomerCartAtTill($till, servedCustomerId: 61, servedLine: [751 => ['quantity' => 2, 'price' => 10.0]], otherLine: [752 => ['quantity' => 4, 'price' => 25.0]]);
        // The keys CartService::getTotalCalculation reads, which live inside the cart array itself.
        $this->withSession([$cartId => session($cartId) + ['ext_discount' => 10, 'ext_discount_type' => 'percent']]);

        $this->placeSaleAtTill($till, amount: 20.0, extra: ['type' => 'wallet', 'customer_id' => 61]);

        // 10 % of 120.00 is 12.00, so the sale costs 108.00. 10 % of the served customer's 20.00 would leave 118.00.
        $this->assertSame(10800, $this->chargedMinorUnits(), 'the share is of the 120.00 that ships');
    }

    /**
     * A product on clearance sale is priced the same before the lock and inside it.
     *
     * The clearance relation is the stated reason the pre-flight total is safe to compute outside the transaction, and
     * reviewer B's mutation R13 — dropping that relation from `getCartProductsForPricing` — left every test green,
     * because no fixture had a product on clearance. With the relation gone the pre-flight prices the line at its full
     * price while the sale writes the discounted one, so the customer is charged more than the order's lines say.
     *
     * This one PASSES at 60f5a605 and is not a discriminating test: it is an invariant the lane must not break, and
     * the mutation it exists to kill is this branch's own.
     */
    #[DataProvider('tills')]
    public function test_a_clearance_sale_line_is_charged_the_clearance_price(string $till): void
    {
        $this->walletCustomer(id: 62, balance: 900.0);
        $this->shelfProduct(id: 753, stock: 100, till: $till, price: 100.0);
        $this->givenClearanceSale(productId: 753, till: $till, discount: 25.0);
        $this->givenCartAtTill($till, [753 => ['quantity' => 2, 'price' => 100.0]], customerId: 62);

        $this->placeSaleAtTill($till, amount: 150.0, extra: ['type' => 'wallet', 'customer_id' => 62]);

        $this->assertSame(['success'], $this->toastTypes());
        $this->assertSame(15000, $this->chargedMinorUnits(), '25 per cent off two at 100.00');
        $this->assertSame([['debit' => 15000, 'balance' => 75000]], $this->walletRowsInMinorUnits());
    }

    // ---- a wallet sale that took nothing is not a sale ------------------------------------------------------------

    /**
     * A wallet sale whose debit did not happen is refused, not recorded as paid.
     *
     * `CustomerTrait::createWalletTransaction` returns false outright when the `wallet_status` business setting is off,
     * and the two tills discarded the answer — so the till wrote an order marked payment_status 'paid' with no wallet
     * row and the customer's balance untouched. Reviewer A's F6, measured identically at 60f5a605 and at a096d9dd:
     * order_amount 300.00, paid_amount 300.00, 'paid', balance still 900.00, zero wallet rows. This lane is what made
     * that call the authoritative debit of the server's own figure, so it is this lane's to answer.
     */
    #[DataProvider('tills')]
    public function test_a_wallet_sale_whose_debit_did_not_happen_is_refused(string $till): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'wallet_status'], ['value' => 0]);
        Cache::flush();
        $this->walletCustomer(id: 63, balance: 900.0);
        $this->shelfProduct(id: 754, stock: 10, till: $till);
        $this->givenCartAtTill($till, [754 => 3], customerId: 63);

        $response = $this->placeSaleAtTill($till, amount: 300.0, extra: ['type' => 'wallet', 'customer_id' => 63]);

        $this->assertSame('[]', $response->content(), 'the shape the panel already reloads on');
        $this->assertSame(['error'], $this->toastTypes());
        $this->assertSame(0, DB::table('orders')->count(), 'no order at 60f5a605 wrote one charged 300.00 and marked paid');
        $this->assertSame(0, DB::table('order_details')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(10, (int) DB::table('products')->where('id', 754)->value('current_stock'));
        $this->assertSame(900.0, (float) DB::table('users')->where('id', 63)->value('wallet_balance'));
    }

    // ---- readings -------------------------------------------------------------------------------------------------

    /**
     * A money column in WHOLE MINOR UNITS, which is the unit this criterion is about and the only unit two engines
     * agree on.
     *
     * The column is DECIMAL(40,20), so MariaDB hands back '30.45000000000000000000' and SQLite, which has no decimal
     * type at all, hands back '30.45'. Comparing the strings would be comparing engines. Rounding onto the minor-unit
     * grid, which is what App\Services\MyFatoorah\Money already does for a payment check, compares the money.
     */
    private function minorUnitsOfLastOrder(string $column, int $exponent = 2): ?int
    {
        $value = DB::table('orders')->orderByDesc('id')->value($column);

        return $value === null ? null : (int) round(((float) $value) * (10 ** $exponent));
    }

    private function chargedMinorUnits(int $exponent = 2): ?int
    {
        return $this->minorUnitsOfLastOrder(column: 'order_amount', exponent: $exponent);
    }

    private function initialMinorUnits(int $exponent = 2): ?int
    {
        return $this->minorUnitsOfLastOrder(column: 'init_order_amount', exponent: $exponent);
    }

    private function paidMinorUnits(int $exponent = 2): ?int
    {
        return $this->minorUnitsOfLastOrder(column: 'paid_amount', exponent: $exponent);
    }

    /** @return list<array{debit: int, balance: int}> every wallet row, in whole minor units, oldest first */
    private function walletRowsInMinorUnits(): array
    {
        return DB::table('wallet_transactions')->orderBy('id')->get(['debit', 'balance'])
            ->map(static fn ($row): array => [
                'debit' => (int) round(((float) $row->debit) * 100),
                'balance' => (int) round(((float) $row->balance) * 100),
            ])->all();
    }

    /**
     * A Kuwait installation: three decimal places, and the default currency row to match.
     *
     * The exponent is the business setting, not anything the currency row says — which is the finding this test rests
     * on. The row is set to KWD so the fixture reads as the installation it is standing in for.
     */
    private function givenThreeDecimalCurrency(): void
    {
        DB::table('currencies')->where('id', 1)->update(['name' => 'Kuwaiti Dinar', 'code' => 'KWD', 'symbol' => 'KWD']);
        DB::table('business_settings')->updateOrInsert(['type' => 'decimal_point_settings'], ['value' => json_encode(3)]);
        Cache::flush();
    }

    private function taxMinorUnits(int $exponent = 2): ?int
    {
        return $this->minorUnitsOfLastOrder(column: 'total_tax_amount', exponent: $exponent);
    }

    /**
     * A real order-wise VAT through the tax module's own tables, which the till harness already migrates.
     *
     * Two rows and a cache flush. The first version of this lane recorded that it could not do this without standing
     * up the whole module fixture; reviewer B showed otherwise, and the two mutations that survived for want of a tax
     * cart are what it cost.
     */
    private function givenOrderWiseVat(float $rate): void
    {
        DB::table('taxes')->insert([
            'id' => 1, 'name' => 'VAT', 'tax_rate' => $rate, 'is_active' => 1, 'is_default' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('system_tax_setups')->insert([
            'id' => 1, 'tax_type' => 'order_wise', 'tax_payer' => 'vendor', 'tax_ids' => json_encode([1]),
            'is_default' => 1, 'is_active' => 1, 'is_included' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::flush();
    }

    /**
     * A store charging in a currency that is not the base one, at a given exchange rate.
     *
     * The platform's multi-currency mode: `defaultCurrencyRate()` is the system default currency's rate over USD's, so
     * the USD row is the one that makes the ratio meaningful.
     */
    private function givenConvertedCurrency(float $rate): void
    {
        DB::table('currencies')->where('id', 1)->update(['exchange_rate' => $rate]);
        DB::table('currencies')->updateOrInsert(
            ['id' => 2],
            ['name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1],
        );
        DB::table('business_settings')->updateOrInsert(['type' => 'currency_model'], ['value' => json_encode(MULTI_CURRENCY)]);
        DB::table('business_settings')->updateOrInsert(['type' => 'system_default_currency'], ['value' => json_encode(1)]);
        Cache::flush();
        session()->forget(['default', 'usd']);
    }

    /**
     * The cart `calculateCartItemsData` and the sale itself disagree about: one line carrying the served customer's id
     * and one carrying another's. The panel's summary counts the first; the sale ships both.
     *
     * @param array<int, array<string, mixed>> $servedLine
     * @param array<int, array<string, mixed>> $otherLine
     */
    private function givenMixedCustomerCartAtTill(string $till, int $servedCustomerId, array $servedLine, array $otherLine): string
    {
        $cartId = $this->givenCartAtTill($till, $servedLine, customerId: $servedCustomerId);
        $cart = session($cartId);
        foreach ($otherLine as $productId => $line) {
            $cart[] = [
                'id' => $productId, 'name' => 'Product ' . $productId, 'quantity' => (int) $line['quantity'],
                'price' => (float) $line['price'], 'discount' => 0, 'tax' => 0, 'variant' => null, 'variations' => [],
                'productType' => 'physical', 'customerId' => 0, 'customerOnHold' => false, 'image' => null,
                'maximum_order_quantity' => 1000,
            ];
        }
        $this->withSession([$cartId => $cart]);

        return $cartId;
    }

    /** An active clearance sale taking a percentage off one product, which the pricing helpers read off the row. */
    private function givenClearanceSale(int $productId, string $till, float $discount): void
    {
        $setupBy = $till === self::ADMIN_TILL ? 'admin' : 'vendor';
        $setupId = DB::table('stock_clearance_setups')->insertGetId([
            'setup_by' => $setupBy,
            'user_id' => $till === self::ADMIN_TILL ? 0 : self::SELLER_ID,
            'shop_id' => $till === self::ADMIN_TILL ? 1 : 2,
            'is_active' => 1,
            'discount_type' => 'percentage',
            'discount_amount' => $discount,
            'offer_active_time' => 'always',
            'duration_start_date' => now()->subDay(),
            'duration_end_date' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_clearance_products')->insert([
            'added_by' => $setupBy,
            'product_id' => $productId,
            'setup_id' => $setupId,
            'user_id' => $till === self::ADMIN_TILL ? 0 : self::SELLER_ID,
            'shop_id' => $till === self::ADMIN_TILL ? 1 : 2,
            'is_active' => 1,
            'discount_type' => 'percentage',
            'discount_amount' => $discount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::flush();
    }
}
