<?php

namespace Tests\Feature\Pos;

use App\Services\Pos\PosSaleTotal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     */
    public function test_seven_items_at_four_thirty_five_come_to_one_halala_less_in_binary_floating_point(): void
    {
        $this->assertSame(30.449999999999996, 4.35 * 7, 'the float product of the line');
        $this->assertSame(30.44, (float) usdToDefaultCurrency(amount: 4.35 * 7), 'which is what the panel shows, posts and used to be charged');
        $this->assertSame(30.45, (float) usdToDefaultCurrency(amount: 30.45), 'while the figure the line actually comes to is 30.45');
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

        // At three decimal places the panel floors the same float one thousandth low instead of one hundredth low.
        $this->assertSame(30.449, (float) usdToDefaultCurrency(amount: 4.35 * 7));

        $this->placeSaleAtTill($till, amount: 30.449);

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
     * (POSController::extraDiscount answers `amount_low`), so this is the second line of that defence.
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
        $this->givenCartAtTill($till, [725 => 3], customerId: 52);

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
     * A CASH sale of the same cart is NOT refused: the `count($cartListSession) > 0` guard counts cart lines, not lines
     * the till can sell, and the sale's own loop skips a line whose product it cannot find, so an order row with no
     * lines is still written — charged 0.00 now rather than the 300.00 the request asked for. The seller app's till was
     * given a refusal for exactly this in the base branch (`a_sale_needs_at_least_one_product`); the two panel tills
     * were not. That is recorded, not fixed here.
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

    /** The same cart in cash: still charged, now nothing rather than what the request asked for. */
    #[DataProvider('tills')]
    public function test_a_cash_sale_whose_lines_name_no_product_that_exists_is_charged_nothing(string $till): void
    {
        $this->givenCartAtTill($till, [729 => 3]);

        $this->placeSaleAtTill($till, amount: 300.0);

        // Measured at 60f5a605: one order charged 300.00 with no lines.
        $this->assertSame(0, $this->chargedMinorUnits(), 'the order is charged nothing');
        $this->assertSame(0, DB::table('order_details')->count(), 'and still has no lines: the order row itself is a separate defect, recorded');
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
}
