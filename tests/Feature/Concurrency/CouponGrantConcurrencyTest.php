<?php

namespace Tests\Feature\Concurrency;

use App\Models\User;
use App\Services\Coupon\CouponAllowance;
use App\Services\Coupon\CouponRedemption;
use App\Services\Storefront\CouponCountry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\AssertsNoLanguageFileWasWritten;
use Tests\Support\BuildsCouponCheckoutWorld;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontCouponWorld;
use Tests\Support\Concurrency\ConcurrentRunner;

/**
 * Two checkouts racing the last use of one coupon, each in its own PHP process on its own connection, against a real MySQL or MariaDB
 * (I1-14, the owner's decision of 2026-11-26: "concurrency must be proved with two independent connections against an engine that locks,
 * not by a sequential test").
 *
 * The cap, the budget and the per-customer limit were read where every other coupon refusal is read -- the eligibility functions, before
 * the order's money is computed. That is a READ, and two checkouts both pass a read before either records a redemption, so a coupon capped
 * at one redemption was granted twice and its budget could be spent twice over. {@see \App\Services\Coupon\CouponGrant} asks the same
 * questions inside the order's transaction under a lock on the coupon's row, and this is the file that can tell the difference.
 *
 * **Why the processes are held at a meeting point.** Released from the common start barrier and left to run free, the faster process
 * commits its redemption before the slower one QUOTES, and the slower one is then refused by the eligibility check -- the very read that
 * was never atomic. Such a run is green whatever the gate does. So each process is held INSIDE its order transaction, at the moment the
 * products are locked, until the other has reached the same point: both then meet the gate with both transactions open and neither
 * committed, which is the interleaving a race produces and the only one that measures the lock. The two carts hold DIFFERENT products in
 * those cases, so that the product locks cannot serialise the two transactions and the coupon's own row is the only thing between them.
 *
 * The last case is the opposite kind: four checkouts of the SAME product off the barrier alone, asserting the invariant over whatever
 * interleaving the machine picks.
 *
 * Needs a real engine: SQLite in memory is private to one process and compiles no lock clause at all, so every test here skips there.
 *
 * TEETH, each mutation applied and restored on MariaDB 10.11.14: taking the coupon row's `lockForUpdate` out -> 2 red, on the DEADLOCK
 * counter and nothing else, which is the finding written into `assertNothingDeadlocked()` below -- the money came out right without that
 * lock, by way of InnoDB killing a transaction and Laravel retrying the closure. Taking the ledger's locking reads out -> 0 red in these
 * races, because nothing in today's order transaction reads before the gate; that is why there is a test of the two read modes of their
 * own, and it goes red when they are taken out.
 */
class CouponGrantConcurrencyTest extends ApiAddressTestCase
{
    use AssertsNoLanguageFileWasWritten;
    use BuildsCouponCheckoutWorld;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontCouponWorld;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process and locks nothing.');
        }

        $this->guardAgainstWrittenLanguageFiles();
        $this->buildCouponCheckoutWorld();
        (require database_path('migrations/2027_01_08_100000_create_coupon_redemptions_table.php'))->up();
        (require database_path('migrations/2027_01_08_100100_add_cap_and_budget_to_coupons_table.php'))->up();
        CouponAllowance::forget();
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            // Built from its real migration, so it is put back: a table a class drops or leaves behind is taken from every class after it.
            Schema::dropIfExists('coupon_redemptions');
            CouponAllowance::forget();
            CouponCountry::flush();
        }

        parent::tearDown();
    }

    /**
     * The two customers of a race, each with a cart of their OWN product so that only the coupon can serialise them.
     *
     * @return array{0: User, 1: User}
     */
    private function twoCartsOfDifferentProducts(): array
    {
        $first = $this->makeCustomer(overrides: ['email' => 'racer1@example.test']);
        $this->fillTheCart($first, self::IN_HOUSE_PRODUCT);
        $second = $this->makeCustomer(overrides: ['email' => 'racer2@example.test']);
        $this->fillTheCart($second, self::SMALL_IN_HOUSE_PRODUCT);

        return [$first, $second];
    }

    /**
     * @param list<array{ok: bool, result?: mixed, error?: string, message?: string, started_at: float, finished_at: float}> $reports
     * @return array{placed: list<array{orders: list<int>, refusal: ?string}>, refused: list<array{orders: list<int>, refusal: ?string}>}
     */
    private function whatEachCheckoutGot(array $reports): array
    {
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index} let an exception escape: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the checkouts ran at the same time');

        $results = array_column($reports, 'result');

        return [
            'placed' => array_values(array_filter($results, static fn (array $result): bool => $result['orders'] !== [])),
            'refused' => array_values(array_filter($results, static fn (array $result): bool => $result['orders'] === [])),
        ];
    }

    /** What the coupon has given away, read from the ledger rather than through CouponRedemption, whose reads are silent while the switch is off in THIS process. */
    private function spentOn(string $code): float
    {
        $couponId = DB::table('coupons')->where('code', $code)->value('id');

        // The VALUE and not its spelling: MariaDB answers a SUM over decimal(18,12) as '5.000000000000' where SQLite answers 5.
        return (float) DB::table('coupon_redemptions')->where('coupon_id', $couponId)->sum('discount');
    }

    /**
     * How many deadlocks this engine has rolled back, or null where it does not keep the count.
     *
     * MariaDB and Percona publish `Innodb_deadlocks`; MySQL 8 does not, so a job running there gets null and the assertion that uses this
     * is skipped rather than guessed at.
     */
    private function deadlocksSoFar(): ?int
    {
        $row = DB::select("show global status like 'Innodb_deadlocks'");

        return $row === [] ? null : (int) $row[0]->Value;
    }

    /**
     * The second checkout WAITED for the coupon's row rather than deadlocking against the first.
     *
     * Measured, because this is the whole value of locking the coupon's row and it is invisible in the outcome: with that lock taken out,
     * this very test still goes green -- the gate's own locking read of the ledger takes a gap lock, the two inserts then collide as insert
     * intentions, InnoDB kills one transaction and Laravel's `DB::transaction(attempts: 3)` re-runs the closure, whose second attempt reads
     * the committed redemption and refuses. Same answer, by deadlock and retry: 2 deadlocks per race here against 0 with the lock, measured
     * on MariaDB 10.11.14. So the assertion is on the COUNTER as well as on the money, and taking the row lock out fails this and nothing
     * else.
     */
    private function assertNothingDeadlocked(?int $before): void
    {
        $after = $this->deadlocksSoFar();

        if ($before === null || $after === null) {
            return;
        }

        $this->assertSame($before, $after, 'the checkouts deadlocked instead of waiting on the coupon row: the row lock is not being taken');
    }

    /** @return list<array{customer: int, coupon: string, mark: string, wait_for: string}> the two processes, each held until the other reaches the gate */
    private function heldAtTheGate(string $code, int ...$customerIds): array
    {
        return [
            ['customer' => $customerIds[0], 'coupon' => $code, 'mark' => 'at-the-gate-0', 'wait_for' => 'at-the-gate-1'],
            ['customer' => $customerIds[1], 'coupon' => $code, 'mark' => 'at-the-gate-1', 'wait_for' => 'at-the-gate-0'],
        ];
    }

    public function test_two_checkouts_racing_the_last_use_of_a_coupon_place_one_discounted_order(): void
    {
        $this->giveCoupon(code: 'LAST', country: null, attributes: ['discount' => 5, 'min_purchase' => 0, 'limit' => 9, 'total_uses_limit' => 1]);
        [$first, $second] = $this->twoCartsOfDifferentProducts();
        $deadlocksBefore = $this->deadlocksSoFar();

        $reports = ConcurrentRunner::run('coupon.place_order', $this->heldAtTheGate('LAST', $first->id, $second->id));

        $this->assertNothingDeadlocked($deadlocksBefore);

        ['placed' => $placed, 'refused' => $refused] = $this->whatEachCheckoutGot($reports);
        $this->assertCount(1, $placed, 'one of the two checkouts placed its order');
        $this->assertCount(1, $refused, 'and the other was refused rather than discounted');
        $this->assertNull($placed[0]['refusal']);
        $this->assertSame(
            CouponAllowance::CAP_REACHED,
            $refused[0]['refusal'],
            'the refusal came from the gate inside the transaction and not from the eligibility read: a checkout the eligibility read '
            . 'refuses places its order WITHOUT the coupon and reports no reason at all',
        );

        $this->assertSame(1, DB::table('orders')->count(), 'the refused order rolled back whole');
        $this->assertSame('LAST', DB::table('orders')->value('coupon_code'));
        $this->assertEqualsWithDelta(5.0, (float) DB::table('orders')->value('discount_amount'), 0.000001);
        $this->assertSame(1, DB::table('coupon_redemptions')->count(), 'one redemption, which is what a cap of one means');
        $this->assertEqualsWithDelta(5.0, $this->spentOn('LAST'), 0.000001, 'and the coupon gave away five, not ten');
    }

    public function test_two_checkouts_racing_the_last_of_a_coupons_budget_give_away_that_budget_once(): void
    {
        $this->giveCoupon(code: 'BUDGET', country: null, attributes: ['discount' => 5, 'min_purchase' => 0, 'limit' => 9, 'budget' => 5]);
        [$first, $second] = $this->twoCartsOfDifferentProducts();
        $deadlocksBefore = $this->deadlocksSoFar();

        $reports = ConcurrentRunner::run('coupon.place_order', $this->heldAtTheGate('BUDGET', $first->id, $second->id));

        $this->assertNothingDeadlocked($deadlocksBefore);

        ['placed' => $placed, 'refused' => $refused] = $this->whatEachCheckoutGot($reports);
        $this->assertCount(1, $placed);
        $this->assertSame(CouponAllowance::BUDGET_SPENT, $refused[0]['refusal'], 'the budget refused the second grant inside the transaction');
        $this->assertEqualsWithDelta(5.0, $this->spentOn('BUDGET'), 0.000001, 'a budget of five gave away five');
        $this->assertSame(1, DB::table('coupon_redemptions')->count());
    }

    /**
     * Why the gate's reads of the ledger are LOCKING reads, measured on the engine rather than argued from the manual.
     *
     * InnoDB at REPEATABLE READ answers a plain read from the snapshot taken at the transaction's first consistent read. `generateOrder`'s
     * transaction is long, so a count taken late in it can describe a world from before another checkout committed -- and a cap judged on a
     * stale count is no cap. A locking read is served the latest committed version instead.
     *
     * **What this measures that the races above cannot.** Taking `locked: true` out of {@see \App\Services\Coupon\CouponGrant} leaves
     * those races GREEN, measured: today nothing in the order transaction reads before the gate, so there is no snapshot yet and the
     * gate's own first read makes one -- after the other checkout has committed. The lock is what keeps the gate right the day a read is
     * added ahead of it, which is one ordinary refactor away, and this is the test that fails if it is removed: the transaction below reads
     * first, exactly as such a refactor would, and the two read modes then answer differently.
     */
    public function test_a_plain_count_of_the_ledger_goes_stale_inside_a_transaction_and_a_locking_one_does_not(): void
    {
        config(['gcc.coupons.limits.enabled' => true]);
        CouponAllowance::forget();
        $this->giveCoupon(code: 'SEEN', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1]);
        $couponId = (int) DB::table('coupons')->where('code', 'SEEN')->value('id');

        DB::beginTransaction();

        try {
            // The read that takes the snapshot. Any read would do; this is the shape of the ones the order transaction already makes.
            DB::table('coupons')->where('id', $couponId)->count();

            $reports = ConcurrentRunner::run('coupon.record_one_redemption', [['coupon' => 'SEEN', 'group' => 'the-other-connections-group', 'discount' => 5]]);
            $this->assertTrue($reports[0]['ok'], 'the other connection recorded its redemption: ' . ($reports[0]['message'] ?? ''));

            $this->assertSame(0, CouponRedemption::totalUses($couponId), 'a plain count still answers from the snapshot, which is what makes it unsafe for a cap');
            $this->assertSame(1, CouponRedemption::totalUses($couponId, locked: true), 'the locking read is served the row the other connection committed');
        } finally {
            DB::rollBack();
        }

        $this->assertSame(1, DB::table('coupon_redemptions')->count(), 'and the other connection\'s row is its own: this transaction wrote nothing');
    }

    /**
     * And the invariant, off the start barrier alone with no meeting point: four checkouts of the SAME product, where the product's own row
     * lock is in play as well, and a cap of two.
     *
     * Whichever order the machine puts them in, exactly two orders carry the coupon and the coupon gives away exactly twice its discount.
     * The two that lost may have been refused by the gate (no order at all) or by the eligibility read (their order placed without the
     * coupon, as this platform has always done), and both are correct answers -- which is why this case asserts the money and not the
     * route the refusal took.
     */
    public function test_four_checkouts_racing_a_cap_of_two_never_exceed_it(): void
    {
        $this->giveCoupon(code: 'TWO', country: null, attributes: ['discount' => 5, 'min_purchase' => 0, 'limit' => 9, 'total_uses_limit' => 2]);
        $arguments = [];
        foreach (range(1, 4) as $racer) {
            $customer = $this->makeCustomer(overrides: ['email' => "crowd{$racer}@example.test"]);
            $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
            $arguments[] = ['customer' => $customer->id, 'coupon' => 'TWO'];
        }

        $reports = ConcurrentRunner::run('coupon.place_order', $arguments);

        ['placed' => $placed, 'refused' => $refused] = $this->whatEachCheckoutGot($reports);
        $this->assertSame(2, DB::table('orders')->where('coupon_code', 'TWO')->count(), 'a cap of two discounted exactly two orders');
        $this->assertSame(2, DB::table('coupon_redemptions')->count());
        $this->assertEqualsWithDelta(10.0, $this->spentOn('TWO'), 0.000001, 'and gave away exactly twice its discount');
        $this->assertSame(count($placed), DB::table('orders')->count(), 'every checkout that reported an order wrote one, and every one that reported none wrote none');
        $this->assertSame(10 - count($placed), (int) DB::table('products')->where('id', self::IN_HOUSE_PRODUCT)->value('current_stock'), 'one unit left the shelf per order written');

        foreach ($refused as $refusal) {
            $this->assertSame(CouponAllowance::CAP_REACHED, $refusal['refusal'], 'a checkout that placed nothing was refused over the coupon and says so');
        }
    }
}
