<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Console\ScheduleDefinition;
use App\Models\VendorSettlement;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * `marketplace:settle`, the command the schedule runs every night with `--release` (app/Console/ScheduleDefinition.php), and the operator runs by hand.
 *
 * With `gcc.settlements.one_currency` off it prints and draws exactly what it did before the switch existed: one line and one settlement for each vendor, whatever currencies wait (the tests named "current
 * behaviour" were written and run green against the command as it was, and are not an endorsement). With the switch on, a run draws a settlement for each currency a vendor has money waiting in, the same
 * night, and both reports name the currency of every line and print its amount as the ledger holds it, so nothing on them is a sum of two currencies (E-17.1).
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

    /**
     * @return array{header: list<string>, rows: list<list<string>>, lines: list<string>} the table a run printed and the sentences around it. The rows are put in the order of their vendor and of what follows
     *                                                                                    it, since the command asks no order of the database and a reference is random
     */
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
        usort($printed['rows'], static fn (array $one, array $other): int => array_slice($one, $vendor) <=> array_slice($other, $vendor));

        return $printed;
    }

    /** Seller 7 with money waiting in two currencies and seller 8 with money waiting in one. */
    private function twoVendorsWithMoneyWaiting(float $dinars = 12.25): void
    {
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: $dinars, currency: 'KWD');
        $this->charge(amount: 10.0, currency: 'SAR');
        $this->earning(amount: 30.0, currency: 'SAR', changes: ['sellerId' => 8]);
    }

    /** @return list<list<string>> the rows without their reference, which is random */
    private function withoutReference(array $rows): array
    {
        return array_map(static fn (array $row): array => array_slice($row, 1), $rows);
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
        $this->assertSame([['7', '3', '102.75', 'calculated'], ['8', '1', '30.00', 'calculated']], $this->withoutReference($printed['rows']));
        $this->assertSame(VendorSettlement::query()->orderBy('seller_id')->pluck('reference')->all(), array_column($printed['rows'], 0));
        $this->assertSame(['2 settlement(s) calculated. None is paid — each needs approval.'], $printed['lines']);
        $this->assertSame(2, VendorSettlement::count());
        $this->assertSame([], $this->unclaimedEntries());
    }

    public function test_current_behaviour_with_the_switch_off_a_run_for_one_vendor_draws_one_settlement_and_leaves_the_others(): void
    {
        $this->twoVendorsWithMoneyWaiting();

        $printed = $this->settle(options: ['--seller' => '7']);

        $this->assertSame([['7', '3', '102.75', 'calculated']], $this->withoutReference($printed['rows']));
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
        $this->assertSame([['7', '2', '112.75', 'calculated']], $this->withoutReference($printed['rows']));
        $this->assertSame(['available', 'available', 'pending'], VendorLedgerEntry::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_the_nightly_run_is_the_command_with_release(): void
    {
        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);

        $nightly = array_values(array_filter($schedule->events(), static fn ($event): bool => str_contains((string) $event->command, 'marketplace:settle')));

        $this->assertCount(1, $nightly);
        $this->assertStringEndsWith('marketplace:settle --release', (string) $nightly[0]->command);
    }

    public function test_with_the_switch_on_the_nightly_run_settles_every_currency_of_a_seller_in_one_run(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $riyals = [$this->earning(amount: 100.5, currency: 'SAR', changes: ['status' => VendorLedgerEntry::STATUS_PENDING, 'availableAt' => now()->subDay()])->id];
        $dinars = [$this->earning(amount: 12.345, currency: 'KWD', changes: ['status' => VendorLedgerEntry::STATUS_PENDING, 'availableAt' => now()->subDay()])->id];
        $riyals[] = $this->charge(amount: 10.0, currency: 'SAR', changes: ['status' => VendorLedgerEntry::STATUS_PENDING, 'availableAt' => now()->subDay()])->id;
        $notYet = $this->earning(amount: 40.0, currency: 'KWD', changes: ['status' => VendorLedgerEntry::STATUS_PENDING, 'availableAt' => now()->addDay()]);

        $printed = $this->settle(options: ['--release' => true]);

        $this->assertSame(['Released 3 matured entries to available.', '2 settlement(s) calculated. None is paid — each needs approval.'], $printed['lines']);
        $this->assertSame(['reference', 'vendor', 'currency', 'entries', 'net', 'status'], $printed['header']);
        $this->assertSame([['7', 'KWD', '1', '12.345', 'calculated'], ['7', 'SAR', '2', '90.50', 'calculated']], $this->withoutReference($printed['rows']));
        $this->assertSame(['KWD' => 12.345, 'SAR' => 90.5], VendorSettlement::query()->orderBy('currency')->pluck('net_amount', 'currency')->all());
        $this->assertSame($riyals, $this->entriesClaimedBy(VendorSettlement::query()->where('currency', 'SAR')->value('id')));
        $this->assertSame($dinars, $this->entriesClaimedBy(VendorSettlement::query()->where('currency', 'KWD')->value('id')));
        $this->assertSame([$notYet->id], $this->unclaimedEntries());

        $this->assertSame(['Released 0 matured entries to available.', 'Nothing is waiting to be settled.'], $this->settle(options: ['--release' => true])['lines']);
        $this->assertSame(2, VendorSettlement::count());
    }

    public function test_with_the_switch_on_a_run_draws_a_settlement_for_each_currency_of_each_vendor_and_names_it(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->twoVendorsWithMoneyWaiting(dinars: 12.345);

        $printed = $this->settle();

        $this->assertSame(['reference', 'vendor', 'currency', 'entries', 'net', 'status'], $printed['header']);
        $this->assertSame(
            [['7', 'KWD', '1', '12.345', 'calculated'], ['7', 'SAR', '2', '90.50', 'calculated'], ['8', 'SAR', '1', '30.00', 'calculated']],
            $this->withoutReference($printed['rows']),
        );
        $this->assertSame(['3 settlement(s) calculated. None is paid — each needs approval.'], $printed['lines']);
        $this->assertEqualsCanonicalizing(VendorSettlement::query()->pluck('reference')->all(), array_column($printed['rows'], 0));
        $this->assertSame([], $this->unclaimedEntries());
    }

    public function test_with_the_switch_on_a_run_for_one_vendor_draws_every_currency_of_that_vendor_and_leaves_the_others(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->twoVendorsWithMoneyWaiting(dinars: 12.345);

        $printed = $this->settle(options: ['--seller' => '7']);

        $this->assertSame([['7', 'KWD', '1', '12.345', 'calculated'], ['7', 'SAR', '2', '90.50', 'calculated']], $this->withoutReference($printed['rows']));
        $this->assertSame(['2 settlement(s) calculated. None is paid — each needs approval.'], $printed['lines']);
        $this->assertSame([8], VendorLedgerEntry::query()->whereNull('settlement_id')->pluck('seller_id')->all());
    }

    public function test_with_the_switch_on_the_dry_run_prints_a_line_for_each_settlement_the_run_would_draw_and_writes_nothing(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->twoVendorsWithMoneyWaiting(dinars: 12.345);
        $this->ledgerEntry(changes: ['entryType' => VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT, 'credit' => 50.0, 'referenceType' => 'payout_release', 'currency' => 'SAR', 'sellerId' => 9]);

        $printed = $this->settle(options: ['--dry-run' => true]);

        $this->assertSame(['vendor', 'currency', 'entries', 'credits', 'debits', 'net'], $printed['header']);
        $this->assertSame(
            [['7', 'KWD', '1', '12.345', '0.00', '12.345'], ['7', 'SAR', '2', '100.50', '10.00', '90.50'], ['8', 'SAR', '1', '30.00', '0.00', '30.00']],
            $printed['rows'],
        );
        $this->assertSame(['Dry run: 3 settlement(s) would be created. Nothing was written.'], $printed['lines']);
        $this->assertSame(0, VendorSettlement::count());
        $this->assertCount(5, $this->unclaimedEntries());

        $this->assertSame(3, count($this->settle()['rows']), 'the run draws what the dry run said it would');
    }

    public function test_with_the_switch_on_the_dry_run_for_one_vendor_reports_that_vendor_only(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->twoVendorsWithMoneyWaiting(dinars: 12.345);

        $printed = $this->settle(options: ['--dry-run' => true, '--seller' => '8']);

        $this->assertSame([['8', 'SAR', '1', '30.00', '0.00', '30.00']], $printed['rows']);
        $this->assertSame(['Dry run: 1 settlement(s) would be created. Nothing was written.'], $printed['lines']);
    }
}
