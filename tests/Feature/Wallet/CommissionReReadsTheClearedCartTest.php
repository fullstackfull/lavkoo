<?php

namespace Tests\Feature\Wallet;

use App\Utils\Helpers;
use ErrorException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsWalletCheckoutWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A REPRODUCER, not a fix: the order builder re-reads the cart from the database INSIDE the order's transaction and indexes it at `[0]`
 * with no guard, so a checkout whose cart has been cleared by another checkout of the same customer dies of `Undefined array key 0`
 * instead of being refused.
 *
 * **What it is the root cause of, MEASURED and not inferred.**
 * `tests/Feature/Concurrency/WalletCheckoutConcurrencyTest::test_two_simultaneous_checkouts_of_sixty_from_one_hundred_place_exactly_one_order`
 * lost a worker to `ErrorException: Undefined array key 0` once in a 125-test concurrency run on 2026-11-27 and three solo re-runs were
 * green. It fired again in a 125-test run on MariaDB 10.11.14 the same day, this time with the frame recorder that had been added for it
 * in place, and reported:
 *
 *     {"pid":24736,"ok":false,"error":"ErrorException","message":"Undefined array key 0","where":[
 *        "app/Utils/Helpers.php:606",
 *        "app/Utils/OrderManager.php:1398",
 *        "app/Utils/OrderManager.php:2170",
 *        "app/Utils/OrderManager.php:2046",
 *        "tests/Support/Concurrency/WalletCheckoutOperations.php:32",
 *        "tests/Support/Concurrency/Operations.php:37"]}
 *     {"pid":24737,"result":"placed","ok":true}
 *
 * which is the path, outermost last: the operation, the order transaction (`OrderManager.php:2046`), the order row being assembled
 * (`:2170` into `getOrderAddData`), the commission that row needs (`:1398`), and the line that threw --
 *
 *     Helpers::sales_commission_before_order()                             app/Utils/Helpers.php:604-606
 *       $carts = CartManager::getCartListQuery(groupId: $cart_group_id);   // a FRESH read of the carts table
 *       ... $carts[0]['seller_is'], $carts[0]['seller_id'] ...             // no guard, and an empty Collection throws here
 *
 * The two `[0]` sites that had been read before -- `OrderManager.php:106` and `CartManager.php:1064` -- were both correctly ruled out.
 * This is the third, and it is the one on the path. The sibling worker's report says `placed`, and its own timestamps put its finish
 * 23 milliseconds BEFORE the throw, which is the window in the next paragraph, measured.
 *
 * **Why the window exists.** The winner's own cart rows are deleted by `CartManager::cartCleanByCartGroupIds()`, which `generateOrder`
 * calls AFTER its transaction has committed. Two checkouts of one customer share one cart, so:
 *
 *   1. both read the cart outside the transaction and both see it, which is what gets the loser past the empty-cart guard
 *      ({@see EmptyCartWalletOrderTest}, which is the other consequence of this same window);
 *   2. the loser enters its transaction and blocks on `User::query()->lockForUpdate()` -- the wallet row, taken at the top of the
 *      transaction body and held by the winner;
 *   3. the winner commits and then, outside its transaction, deletes the cart rows;
 *   4. the loser takes the lock. Its first statement in the transaction was a LOCKING read, which reads the latest committed row rather
 *      than a snapshot, so the consistent snapshot for its plain reads is taken afterwards -- after the delete. Its re-read of the cart
 *      comes back EMPTY;
 *   5. `$carts[0]` throws, before the wallet debit at the foot of the transaction, so the loser never reaches the refusal that was the
 *      correct answer for it.
 *
 * **What it costs and what it does NOT.** It is not an over-draw and not a partial order: the loser throws inside its transaction, which
 * rolls back, and it dies before the debit -- which is exactly why the failing run had one worker place an order and the other die, with
 * at most one order in the database. What it costs is the answer: a customer who should be told their balance is not enough gets a server
 * error, and an operator reading the log sees `Undefined array key 0` rather than a refusal.
 *
 * **No production code is changed here.** The condition is pinned at the line it throws on, with no timing in it, so the fix -- whatever it
 * turns out to be, a guard here or a cart read that cannot come back empty -- has something to turn red first. It needs the owner's word,
 * because `sales_commission_before_order()` is on the commission path of every checkout on this platform and not only a wallet one.
 */
class CommissionReReadsTheClearedCartTest extends TestCase
{
    use BuildsWalletCheckoutWorld;
    use RestoresLanguageFiles;

    private const BALANCE = '100.00';

    private const PRICE = '60.00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->buildWalletCheckoutWorld();
        $this->useStoreCurrency(code: 'SAR', rate: '3.75', decimals: 2);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** With the cart there, the commission is worked out and nothing is wrong: this is the control, so the failure below is the delete. */
    public function test_the_commission_is_answered_while_the_cart_is_there(): void
    {
        $customer = $this->shopperWithCart(walletBalance: self::BALANCE, price: self::PRICE);

        $this->assertNotSame(
            '',
            (string) Helpers::sales_commission_before_order($this->cartGroupOf($customer->id), 0),
            'the commission is not answered even with the cart in place, so this test is not measuring the delete',
        );
    }

    /**
     * THE REPRODUCER. The cart rows are deleted exactly as `CartManager::cartCleanByCartGroupIds()` deletes them, and the commission the
     * order builder asks for throws instead of answering.
     *
     * The assertion is on the FILE and the source of the throwing line rather than on a line number, so it keeps naming the right place
     * when the file moves and fails if that line stops being the one that throws.
     */
    public function test_the_commission_throws_once_another_checkout_has_cleared_the_cart(): void
    {
        $customer = $this->shopperWithCart(walletBalance: self::BALANCE, price: self::PRICE);
        $group = $this->cartGroupOf($customer->id);

        // What the winner's checkout does after its own transaction has committed (CartManager::cartCleanByCartGroupIds).
        DB::table('carts')->where('cart_group_id', $group)->where('is_checked', 1)->delete();

        try {
            Helpers::sales_commission_before_order($group, 0);
            $this->fail('the commission no longer throws on a cleared cart: the defect this reproducer exists for has been fixed, and it should now assert the new behaviour');
        } catch (ErrorException $theDefect) {
            [$file, $line] = $this->firstProjectFrameOf($theDefect);

            $this->assertSame('Undefined array key 0', $theDefect->getMessage());
            $this->assertSame('app/Utils/Helpers.php', $file, 'something else in this project raised it');
            $this->assertStringContainsString(
                '$carts[0]',
                $this->sourceLine($file, $line),
                'the line that raised it is not the unguarded index this reproducer is about',
            );
        }
    }

    /** And the caller is the order builder, which asks for it while the order's transaction is open. */
    public function test_the_order_builder_is_what_asks_for_it(): void
    {
        $builder = (string) file_get_contents(base_path('app/Utils/OrderManager.php'));

        $this->assertStringContainsString(
            'Helpers::sales_commission_before_order($cartData[\'cart_group_id\']',
            $builder,
            'getOrderAddData no longer asks for the commission by cart group, so the path in this docblock is out of date',
        );
    }

    private function cartGroupOf(int $customerId): string
    {
        return (string) DB::table('carts')->where('customer_id', $customerId)->value('cart_group_id');
    }

    /**
     * The first frame of this project, which is what `tests/Support/Concurrency/worker.php` records as `where[0]` -- so the reproducer and
     * the field recorder name the same place.
     *
     * The exception's own file is `Illuminate\Collections\Collection.php`, where `$this->items[$key]` lives: an empty Eloquent collection
     * indexed at `[0]` raises the notice inside the framework. That frame says nothing about which of this platform's hundreds of `[0]`
     * reads it was, which is the whole reason the recorder filters the vendor frames out.
     *
     * @return array{0: string, 1: int} the path relative to the project root, and the line
     */
    private function firstProjectFrameOf(ErrorException $exception): array
    {
        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line']) && !str_contains($frame['file'], '/vendor/')) {
                return [str_replace(base_path() . '/', '', $frame['file']), (int) $frame['line']];
            }
        }

        return ['', 0];
    }

    private function sourceLine(string $file, int $line): string
    {
        $lines = file(base_path($file)) ?: [];

        return $lines[$line - 1] ?? '';
    }
}
