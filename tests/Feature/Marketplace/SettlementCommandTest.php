<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * `marketplace:settle`, the command the schedule runs every night with `--release` (app/Console/ScheduleDefinition.php), and the operator runs by hand.
 *
 * With `gcc.settlements.one_currency` off it prints and draws exactly what it did before the switch existed: one line and one settlement for each vendor, whatever currencies wait (the tests named "current
 * behaviour" were written and run green against the command as it was, and are not an endorsement).
 */
class SettlementCommandTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorSettlementTables();
        $this->useDefaultCurrency(code: 'SAR');
    }

    /** @return array{header: list<string>, rows: list<list<string>>, lines: list<string>} the table a run printed and the sentences around it; the rows are in the order of their vendor, since the command asks no order of the database */
    private function settle(array $options = []): array
    {
        Artisan::call('marketplace:settle', $options);

        $printed = ['header' => [], 'rows' => [], 'lines' => []];
        foreach (array_filter(array_map('trim', explode("\n", Artisan::output()))) as $line) {
            if (str_starts_with($line, '+')) {
                continue;
            }
            if (!str_starts_with($line, '|')) {
                $printed['lines'][] = $line;
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            $printed['header'] === [] ? $printed['header'] = $cells : $printed['rows'][] = $cells;
        }
        $vendor = (int) array_search('vendor', $printed['header'], true);
        usort($printed['rows'], static fn (array $one, array $other): int => [$one[$vendor], $one] <=> [$other[$vendor], $other]);

        return $printed;
    }

    /** Seller 7 with money waiting in two currencies and seller 8 with money waiting in one. */
    private function twoVendorsWithMoneyWaiting(): void
    {
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.25, currency: 'KWD');
        $this->charge(amount: 10.0, currency: 'SAR');
        $this->earning(amount: 30.0, currency: 'SAR', changes: ['sellerId' => 8]);
    }

    public function test_current_behaviour_with_the_switch_off_the_dry_run_prints_one_line_for_each_vendor_and_writes_nothing(): void
    {
        $this->twoVendorsWithMoneyWaiting();

        $printed = $this->settle(options: ['--dry-run' => true]);

        $this->assertSame(['vendor', 'entries', 'credits', 'debits', 'net'], $printed['header']);
        $this->assertSame([['7', '3', '112.75', '10.00', '102.75'], ['8', '1', '30.00', '0.00', '30.00']], $printed['rows']);
        $this->assertSame(['Dry run: 2 settlement(s) would be created. Nothing was written.'], $printed['lines']);
        $this->assertSame(0, VendorSettlement::count());
        $this->assertCount(4, $this->unclaimedEntries());
    }

    public function test_current_behaviour_with_the_switch_off_a_run_draws_one_settlement_for_each_vendor(): void
    {
        $this->twoVendorsWithMoneyWaiting();

        $printed = $this->settle();

        $this->assertSame(['reference', 'vendor', 'entries', 'net', 'status'], $printed['header']);
        $this->assertSame([['7', '3', '102.75', 'calculated'], ['8', '1', '30.00', 'calculated']], array_map(static fn (array $row): array => array_slice($row, 1), $printed['rows']));
        $this->assertSame(VendorSettlement::query()->orderBy('seller_id')->pluck('reference')->all(), array_column($printed['rows'], 0));
        $this->assertSame(['2 settlement(s) calculated. None is paid — each needs approval.'], $printed['lines']);
        $this->assertSame(2, VendorSettlement::count());
        $this->assertSame([], $this->unclaimedEntries());
    }

    public function test_current_behaviour_with_the_switch_off_a_run_for_one_vendor_draws_one_settlement_and_leaves_the_others(): void
    {
        $this->twoVendorsWithMoneyWaiting();

        $printed = $this->settle(options: ['--seller' => '7']);

        $this->assertSame([['7', '3', '102.75', 'calculated']], array_map(static fn (array $row): array => array_slice($row, 1), $printed['rows']));
        $this->assertSame(['1 settlement(s) calculated. None is paid — each needs approval.'], $printed['lines']);
        $this->assertSame([8], VendorLedgerEntry::query()->whereNull('settlement_id')->pluck('seller_id')->all());
    }

    public function test_a_run_with_nothing_waiting_says_so(): void
    {
        $this->earning(amount: 250.0, changes: ['status' => VendorLedgerEntry::STATUS_PENDING]);

        $this->assertSame(['Nothing is waiting to be settled.'], $this->settle()['lines']);
        $this->assertSame(['Nothing is waiting to be settled.'], $this->settle(options: ['--dry-run' => true])['lines']);
        $this->assertSame(0, VendorSettlement::count());
    }

    public function test_current_behaviour_with_the_switch_off_the_nightly_run_releases_what_has_matured_and_settles_it_in_one_settlement(): void
    {
        $this->earning(amount: 100.5, currency: 'SAR', changes: ['status' => VendorLedgerEntry::STATUS_PENDING, 'availableAt' => now()->subDay()]);
        $this->earning(amount: 12.25, currency: 'KWD', changes: ['status' => VendorLedgerEntry::STATUS_PENDING, 'availableAt' => now()->subDay()]);
        $this->earning(amount: 40.0, currency: 'SAR', changes: ['status' => VendorLedgerEntry::STATUS_PENDING, 'availableAt' => now()->addDay()]);

        $printed = $this->settle(options: ['--release' => true]);

        $this->assertSame(['Released 2 matured entries to available.', '1 settlement(s) calculated. None is paid — each needs approval.'], $printed['lines']);
        $this->assertSame([['7', '2', '112.75', 'calculated']], array_map(static fn (array $row): array => array_slice($row, 1), $printed['rows']));
        $this->assertSame(['available', 'available', 'pending'], VendorLedgerEntry::query()->orderBy('id')->pluck('status')->all());
    }
}
