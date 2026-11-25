<?php

namespace Tests\Feature\Checkout;

use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The one rule that picks an order's id, and the four tills that all have to use it (D1-307).
 *
 * `orders.id` is not auto-increment on this platform: something has to choose the number. The rule used to be
 * `100000 + count(orders) + 1`, and it was replaced for the web checkout and both panel tills after a duplicate primary
 * key was measured on the running store -- "Duplicate entry '100002' for key 'PRIMARY'", a hard 500 at the last step of
 * checkout, after the customer pressed pay. `OrderManager::generateNewOrderID()` is the replacement and its read takes
 * the row lock.
 *
 * The seller app's till kept a private copy of the OLD rule until this change, which differed in three ways that all
 * matter: it loaded EVERY order row into memory to count them, once per sale; its read took no lock; and counting is not
 * the same function as "the next one after the highest" the moment the ids have a gap in them.
 *
 * That last one is the difference this class measures, because it is deterministic and a race is not. With orders 100001
 * and 100005 on the books, counting gives 100003 -- a number BELOW the highest, which a deleted order's invoice may
 * already name -- and the rule gives 100006. There is no test in this repository for the picker itself, so this is it,
 * and it covers all four tills at once because all four now ask the same function.
 */
class OrderIdPickerTest extends TestCase
{
    /** The first id a platform with no orders hands out, which is the rule's own base and must not move. */
    private const FIRST_ID = 100001;

    protected function setUp(): void
    {
        parent::setUp();

        // `orders` and `order_details` have no create migration: they come from installation/backup/database.sql, so
        // ABSENT is their migrated state in a test database and dropping them again is the restore. Only the columns the
        // picker reads are built, which is the id of one and the order_id of the other.
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_details');

        Schema::create('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });

        // `OrderDetail` eager-loads `storage` (`protected $with`), so hydrating ONE row asks for this table. The picker reads a
        // line through the model, which is why a test of the picker needs it; it stays empty.
        Schema::dropIfExists('storages');
        Schema::create('storages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('data_id')->nullable();
            $table->string('data_type')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('storages');
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');

        parent::tearDown();
    }

    /** @param list<int> $ids orders to put on the books, each with one line, as a real order has */
    private function booksHolding(array $ids): void
    {
        foreach ($ids as $id) {
            DB::table('orders')->insert(['id' => $id, 'customer_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('order_details')->insert(['order_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** What counting the rows would have answered: the rule this replaced, written out so the difference is visible. */
    private function whatCountingWouldHaveGiven(): int
    {
        return 100000 + (int) DB::table('orders')->count() + 1;
    }

    public function test_a_platform_with_no_orders_hands_out_the_first_id_and_that_has_not_moved(): void
    {
        $this->assertSame(self::FIRST_ID, (int) OrderManager::generateNewOrderID());
    }

    public function test_the_next_id_is_the_one_after_the_highest(): void
    {
        $this->booksHolding([self::FIRST_ID, 100002, 100003]);

        $this->assertSame(100004, (int) OrderManager::generateNewOrderID());
    }

    /**
     * The gap, which is the whole point.
     *
     * An order is deleted, or an id was skipped by a rolled-back attempt, and the books have a hole in them. Counting
     * the rows then hands out a number that is already spoken for by the records: an invoice, a refund, a courier's
     * reference. The rule reads the HIGHEST id instead, so a hole stays a hole.
     */
    public function test_a_gap_in_the_books_is_not_filled_and_counting_would_have_filled_it(): void
    {
        $this->booksHolding([self::FIRST_ID, 100005]);

        $this->assertSame(100006, (int) OrderManager::generateNewOrderID(), 'the next one after the highest');
        $this->assertSame(100003, $this->whatCountingWouldHaveGiven(), 'and counting would have reached back into the gap');
    }

    /** One number, whoever asks: the web checkout, the admin till, the vendor till and the seller app's till. */
    public function test_every_till_asking_at_the_same_moment_is_given_the_same_number(): void
    {
        $this->booksHolding([self::FIRST_ID, 100002]);

        $asked = array_map(static fn (): int => (int) OrderManager::generateNewOrderID(), range(1, 4));

        $this->assertSame([100003, 100003, 100003, 100003], $asked, 'the picker is a read: it reserves nothing by being called');
    }

    /**
     * No till picks an id by arithmetic of its own any more.
     *
     * The seller app's till carried a copy of the old rule for as long as it did because nothing said it must not. This
     * is what says it: the four files that create an order ask `OrderManager` for the number, and none of them contains
     * the shape `100000 +` that the old rule was written in.
     */
    public function test_no_order_writer_picks_an_id_by_counting_the_rows(): void
    {
        $tills = [
            'app/Http/Controllers/RestAPI/v3/seller/POSController.php',
            'app/Http/Controllers/Admin/POS/POSOrderController.php',
            'app/Http/Controllers/Vendor/POS/POSOrderController.php',
            'app/Utils/OrderManager.php',
        ];

        foreach ($tills as $relative) {
            $source = (string) file_get_contents(base_path($relative));
            // Comments stripped first, because what a file SAYS about the rule it replaced does not count -- the commit that
            // made this change explains the old rule in a comment that quotes it verbatim, and an unstripped scan read that
            // comment as the rule still being there.
            $code = $this->withoutComments($source);

            $this->assertStringNotContainsString(
                'Order::all()->count()',
                $code,
                $relative . ' must not count every order row to pick an id: it loads the whole table and it fills gaps',
            );
            $this->assertStringContainsString(
                'generateNewOrderID',
                $code,
                $relative . ' creates orders, so it asks the one picker for the number',
            );
        }
    }

    /** The file's code with its comments taken out, so a scan judges what runs rather than what is written about it. */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
