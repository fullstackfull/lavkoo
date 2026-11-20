<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use App\Services\Marketplace\LedgerChain;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionParameter;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * E-17.2 of the master plan: a running balance on the seller ledger belongs to one seller, one selling company and one currency, and a settlement is drawn per chain.
 *
 * Everything in the first group runs with `gcc.ledgers.keyed_by_entity_and_currency` on. The second group runs with it off and pins the shipped platform, where a seller has
 * one chain over everything — which is the live Saudi position and has to stay exactly as it is. The third group is an installation whose `vendor_ledger_entries` has no
 * company column at all, which is every installation that has not run a migration for one: `vendor_ledger_entries` has no migration in this repository, so the live table came
 * from the installation dump and the tables here are built from the columns the code writes.
 *
 * NOT COVERED, AND IT IS A GATE RATHER THAN A GAP: a payout. PayoutService draws on `withdrawable()` for the seller as a whole and its reserve names no currency, so on a
 * two-currency ledger the reserve lands in the base-currency chain and takes it negative by the amount paid out of the other chain, which goes on advertising money that has
 * gone. Nothing here pins that, because the right answer — draw per chain, or refuse while a seller holds more than one — is the owner's to choose; until it is chosen this
 * switch must not be thrown where a second currency is already on the ledger (config/gcc.php says so too).
 *
 * The lock is pinned under real concurrency in tests/Feature/Concurrency/VendorLedgerConcurrencyTest.php, which needs a real engine and several processes. What is NOT claimed
 * anywhere is that narrowing the lock stops two markets waiting for each other: measured, it does not, and LedgerChain's docblock says why.
 */
class LedgerChainTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private const COMPANY_SA = 11;

    private const COMPANY_AE = 12;

    private VendorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorSettlementTables();
        $this->useDefaultCurrency('SAR');
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);
        $this->ledger = app(VendorLedger::class);
    }

    private function chain(?int $company, ?string $currency, int $sellerId = self::SELLER): LedgerChain
    {
        return $this->ledger->chain(sellerId: $sellerId, legalEntityId: $company, currency: $currency);
    }

    private function balanceOf(?int $company, ?string $currency): float
    {
        return $this->ledger->balances(sellerId: self::SELLER, chain: $this->chain(company: $company, currency: $currency))['balance'];
    }

    public function test_money_of_two_currencies_runs_in_two_balances_of_its_own(): void
    {
        $riyals = $this->earning(100, 'SAR');
        $dirhams = $this->earning(40, 'AED');
        $moreRiyals = $this->earning(25, 'SAR');

        $this->assertSame(100.0, $riyals->balance_after);
        $this->assertSame(40.0, $dirhams->balance_after, 'the first dirham entry opens a balance of its own and does not read the riyal one');
        $this->assertSame(125.0, $moreRiyals->balance_after, 'the second riyal entry reads the riyal balance, not the sum of everything');
    }

    public function test_money_of_two_selling_companies_runs_in_two_balances_of_its_own(): void
    {
        $saudi = $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $emirati = $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertSame(100.0, $saudi->balance_after);
        $this->assertSame(60.0, $emirati->balance_after, 'the same currency sold by another company is another chain');
    }

    public function test_an_entry_that_names_no_currency_belongs_to_the_base_currency_chain(): void
    {
        $unlabelled = $this->withoutACurrency($this->earning(100, null));
        $riyals = $this->earning(30, 'SAR');

        $this->assertNull($unlabelled->currency);
        $this->assertSame(130.0, $riyals->balance_after, 'years of unlabelled history stay in one piece with the base currency');
        $this->assertSame(130.0, $this->balanceOf(company: null, currency: 'SAR'));
        $this->assertSame(130.0, $this->balanceOf(company: null, currency: null), 'the chain of no currency is the chain of the base currency');
    }

    /**
     * The STORED label is the dirty one here, so this is the SQL half of the rule: `UPPER(TRIM(COALESCE(currency, ?)))`.
     *
     * Only a case-sensitive engine can see the UPPER. MySQL and MariaDB compare a `varchar` through the column's collation, which is case-blind by default
     * (`latin1_swedish_ci` answers 1 to `'sar' = 'SAR'`), so deleting the UPPER leaves them green and the SQLite job is the one that catches it. The incoming half of the rule
     * is pinned on every engine by test_an_incoming_currency_label_in_another_case_or_with_spaces_joins_the_same_chain, which goes through PHP.
     */
    public function test_a_currency_written_in_another_case_or_with_spaces_is_the_same_chain(): void
    {
        $this->withoutACurrency($this->earning(100, 'SAR'), ' sar ');
        $next = $this->earning(10, 'SAR');

        $this->assertSame(110.0, $next->balance_after);
    }

    /** And the same of a label coming IN, which `VendorLedger::currencyCode()` spells and no engine's collation can rescue: a chain keyed on `' sar '` would match nothing and reset the running balance on every such write. */
    public function test_an_incoming_currency_label_in_another_case_or_with_spaces_joins_the_same_chain(): void
    {
        $this->earning(100, 'SAR');
        $next = $this->earning(10, ' sar ');

        $this->assertSame(110.0, $next->balance_after, 'a label with spaces and lower case opened a chain of its own');
    }

    public function test_a_blank_currency_belongs_to_the_base_currency_chain(): void
    {
        $this->withoutACurrency($this->earning(100, 'SAR'), '   ');
        $next = $this->earning(10, 'SAR');

        $this->assertSame(110.0, $next->balance_after);
    }

    public function test_another_sellers_money_is_never_in_this_sellers_chain(): void
    {
        $this->earning(100, 'SAR');
        $other = $this->earning(500, 'SAR', sellerId: self::ANOTHER_SELLER);

        $this->assertSame(500.0, $other->balance_after);
        $this->assertSame(100.0, $this->balanceOf(company: null, currency: 'SAR'));
    }

    public function test_a_chain_answers_for_its_own_money_only(): void
    {
        $this->earning(100, 'SAR');
        $this->charge(30, 'SAR');
        $this->earning(40, 'AED');

        $this->assertSame(70.0, $this->balanceOf(company: null, currency: 'SAR'));
        $this->assertSame(40.0, $this->balanceOf(company: null, currency: 'AED'));
        $this->assertSame(0.0, $this->balanceOf(company: null, currency: 'KWD'), 'a chain with nothing in it is empty, not the seller total');
    }

    public function test_a_reader_that_names_no_chain_still_gets_the_sellers_whole_position(): void
    {
        $this->earning(100, 'SAR');
        $this->earning(40, 'AED');

        $this->assertSame(140.0, $this->ledger->balances(self::SELLER)['balance'], 'the screens that show a seller everything they have are not narrowed by this change');
        $this->assertSame(140.0, $this->ledger->withdrawable(self::SELLER));
    }

    public function test_what_can_be_withdrawn_out_of_one_chain_excludes_the_other_chains(): void
    {
        $this->earning(100, 'SAR');
        $this->earning(40, 'AED');
        $this->earning(70, 'SAR', ['status' => VendorLedgerEntry::STATUS_PENDING]);

        $this->assertSame(
            100.0,
            $this->ledger->withdrawable(sellerId: self::SELLER, chain: $this->chain(company: null, currency: 'SAR')),
            'the riyal chain is its own available money less its own pending money',
        );
        $this->assertSame(40.0, $this->ledger->withdrawable(sellerId: self::SELLER, chain: $this->chain(company: null, currency: 'AED')));
    }

    /** A reference is only unique within a chain. Matching it on the seller alone handed the dirham write the riyal row and the dirhams never reached their chain. */
    public function test_an_entry_of_one_chain_is_not_taken_for_an_entry_of_another(): void
    {
        $riyals = $this->ledger->record(
            sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 900,
            currency: 'SAR', legalEntityId: self::COMPANY_SA,
        );
        $dirhams = $this->ledger->record(
            sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 40,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 900,
            currency: 'AED', legalEntityId: self::COMPANY_AE,
        );

        $this->assertNotSame($riyals->id, $dirhams->id, 'the dirham entry returned the riyal row');
        $this->assertSame(40.0, $this->balanceOf(company: self::COMPANY_AE, currency: 'AED'), 'the dirhams never reached their chain');
    }

    /** Two entries of the SAME chain for the same event are still one entry: the idempotency the ledger has always had, now asked of the chain. */
    public function test_the_same_event_recorded_twice_in_one_chain_is_still_one_entry(): void
    {
        $first = $this->ledger->record(
            sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 900,
            currency: 'SAR', legalEntityId: self::COMPANY_SA,
        );
        $again = $this->ledger->record(
            sellerId: self::SELLER, entryType: VendorLedgerEntry::TYPE_ORDER_EARNING, credit: 100,
            status: VendorLedgerEntry::STATUS_AVAILABLE, referenceType: 'order_details', referenceId: 900,
            currency: 'SAR', legalEntityId: self::COMPANY_SA,
        );

        $this->assertSame($first->id, $again->id);
        $this->assertSame(100.0, $this->balanceOf(company: self::COMPANY_SA, currency: 'SAR'), 'the second call credited the chain a second time');
    }

    /** The unlabelled rows belong to the base-currency chain and to no other, or a seller selling in a second currency would see years of riyal history in their dirham balance. */
    public function test_only_the_base_currency_chain_takes_the_rows_that_name_no_currency(): void
    {
        $this->withoutACurrency($this->earning(100, 'SAR'));
        $this->earning(40, 'AED');

        $this->assertSame(100.0, $this->balanceOf(company: null, currency: 'SAR'), 'the unlabelled riyals belong to the base chain');
        $this->assertSame(40.0, $this->balanceOf(company: null, currency: 'AED'), 'the dirham chain took the unlabelled riyals');
    }

    /**
     * A caller cannot ask LedgerChain for a chain without saying whether its currency is the base one.
     *
     * The factory used to default that to true, so `LedgerChain::of(sellerId: 7, currency: 'AED')` built a dirham chain that also read every row naming no currency — the
     * base currency's history, in another currency's balance. A required argument is what makes that unwritable rather than merely undocumented.
     */
    public function test_the_public_factory_cannot_name_a_chain_without_saying_whether_its_currency_is_the_base_one(): void
    {
        $required = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            array_values(array_filter(
                (new ReflectionMethod(LedgerChain::class, 'of'))->getParameters(),
                static fn (ReflectionParameter $parameter): bool => !$parameter->isOptional(),
            )),
        );

        $this->assertSame(['sellerId', 'currency', 'currencyIsTheBaseOne'], $required);
    }

    /** And told that its currency is not the base one, the chain it builds is the one the ledger would have named. */
    public function test_a_chain_of_a_currency_that_is_not_the_base_one_reads_only_its_own_rows(): void
    {
        $this->withoutACurrency($this->earning(100, 'SAR'));
        $this->earning(40, 'AED');

        $dirhams = LedgerChain::of(sellerId: self::SELLER, currency: 'AED', currencyIsTheBaseOne: false);

        $this->assertSame(40.0, $this->ledger->balances(sellerId: self::SELLER, chain: $dirhams)['balance']);
        $this->assertSame($this->balanceOf(company: null, currency: 'AED'), $this->ledger->balances(sellerId: self::SELLER, chain: $dirhams)['balance'], 'the factory named a different chain from the ledger');
    }

    public function test_an_entry_records_the_company_that_sold_it(): void
    {
        $entry = $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertSame(self::COMPANY_AE, $entry->refresh()->legal_entity_id);
    }

    public function test_a_settlement_never_nets_two_companies_together(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(2, $settlements, 'one settlement for each company, not one of 160');
        $this->assertSame(
            [self::COMPANY_SA => 100.0, self::COMPANY_AE => 60.0],
            collect($settlements)->mapWithKeys(fn ($settlement): array => [(int) $settlement->legal_entity_id => (float) $settlement->net_amount])->all(),
        );
    }

    /**
     * A settlement is drawn out of ONE chain, whatever `gcc.settlements.one_currency` says.
     *
     * That switch used to be the only thing telling currencies apart, so with this one on and that one off — the state this switch is meant to be thrown in first — a
     * settlement took the riyals and the dirhams together and netted 140 of nothing. The two switches now give one answer (SettlementCurrency::areToldApart), because the
     * ledger keeping a balance per currency is itself a reason a settlement cannot run across two of them.
     */
    public function test_a_settlement_is_never_drawn_across_two_chains_whatever_the_settlement_switch_says(): void
    {
        config(['gcc.settlements.one_currency' => false]);
        $this->earning(100, 'SAR');
        $this->earning(40, 'AED');

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(2, $settlements, 'a settlement took both the riyals and the dirhams');
        $this->assertSame(
            ['SAR' => 100.0, 'AED' => 40.0],
            collect($settlements)->mapWithKeys(fn (VendorSettlement $drawn): array => [(string) $drawn->currency => (float) $drawn->net_amount])->all(),
        );
    }

    /**
     * And a settlement's closing balance is its own opening balance plus its own net, or it records none at all.
     *
     * `balance_after` runs per chain once chains are kept apart, so a settlement that read it off a chain holding only part of its money reported 0 + 140 = 40. The riyal
     * settlement's history was one chain up to its last entry, so it states its balances; the dirham one's was not, so it states neither.
     */
    public function test_a_settlement_records_its_own_opening_balance_plus_its_own_net_or_no_balance_at_all(): void
    {
        config(['gcc.settlements.one_currency' => false]);
        $this->earning(100, 'SAR');
        $this->earning(40, 'AED');

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $stated = 0;
        foreach ($settlements as $settlement) {
            if ($settlement->closing_balance === null) {
                continue;
            }

            $stated++;
            $this->assertSame(
                round((float) $settlement->opening_balance + (float) $settlement->net_amount, 4),
                round((float) $settlement->closing_balance, 4),
                'a settlement recorded a closing balance that is not its own opening balance plus its own net',
            );
        }

        $this->assertSame(1, $stated, 'the riyal settlement, whose history was one chain up to its last entry, states its balances');
    }

    /** What one chain's settlement claimed is netted out of that chain's withdrawable amount and not out of any other's, or settling the riyals would hide the dirhams. */
    public function test_a_settlement_in_one_chain_does_not_reduce_another_chains_withdrawable(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR');
        $this->earning(40, 'AED');
        app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);
        $this->earning(70, 'SAR');

        $this->assertSame(70.0, $this->ledger->withdrawable(sellerId: self::SELLER, chain: $this->chain(company: null, currency: 'SAR')), 'what the riyal settlement claimed is still withdrawable, or is netted out twice');
        $this->assertSame(0.0, $this->ledger->withdrawable(sellerId: self::SELLER, chain: $this->chain(company: null, currency: 'AED')), 'the dirham settlement claimed the dirhams');
    }

    /**
     * An installation whose `vendor_settlements` cannot name a company still keeps two companies' money apart.
     *
     * Both tables came from the installation dump and neither has a migration here, so one may have the column and the other may not. Money is what matters and money is not
     * mixed; what the operator loses is the label, and they can still tell the two apart by the entries each claimed.
     */
    public function test_a_settlement_table_that_cannot_name_a_company_still_keeps_two_companies_money_apart(): void
    {
        Schema::table('vendor_settlements', fn ($table) => $table->dropColumn(LedgerChain::ENTITY_COLUMN));
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(2, $settlements, 'nowhere to record the company is not a reason to net two companies together');
        $this->assertSame([100.0, 60.0], array_map(static fn (VendorSettlement $drawn): float => (float) $drawn->net_amount, $settlements));
        $this->assertSame(
            [[1, 100.0], [1, 60.0]],
            array_map(fn (VendorSettlement $drawn): array => [count($this->entriesClaimedBy($drawn->id)), (float) $drawn->net_amount], $settlements),
            'each settlement claims only its own company\'s entry',
        );
    }

    public function test_the_waiting_report_names_a_line_for_each_company_and_currency(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);
        $this->earning(40, 'AED', ['legalEntityId' => self::COMPANY_AE]);

        $lines = collect(app(SettlementEngine::class)->waiting(sellerId: self::SELLER))
            ->map(fn (array $line): array => [$line['legal_entity_id'], $line['currency'], $line['net']])
            ->sortBy([1, 0])
            ->values()
            ->all();

        $this->assertSame([
            [self::COMPANY_AE, 'AED', 40.0],
            [self::COMPANY_SA, 'SAR', 100.0],
            [self::COMPANY_AE, 'SAR', 60.0],
        ], $lines);
    }

    /**
     * And the report names the currency of each line, even with the settlement switch off.
     *
     * `SettlementCurrency::of()` answers the empty code while currencies are not told apart, which is how the platform printed money before either switch existed. Once the
     * ledger keeps a balance per currency they ARE told apart, so a report that drew a line per currency while labelling every line with nothing would leave an operator two
     * lines of the same seller and no way to tell which money each is.
     */
    public function test_the_waiting_report_names_each_chains_currency_even_with_the_settlement_switch_off(): void
    {
        config(['gcc.settlements.one_currency' => false]);
        $this->earning(100, 'SAR');
        $this->earning(40, 'AED');

        $lines = collect(app(SettlementEngine::class)->waiting(sellerId: self::SELLER))
            ->map(fn (array $line): array => [$line['currency'], $line['net']])
            ->sortBy(0)
            ->values()
            ->all();

        $this->assertSame([['AED', 40.0], ['SAR', 100.0]], $lines);
    }

    /**
     * History decides whether a settlement can report balances, and it decides it per settlement.
     *
     * These two entries were written before the chains were told apart, so `balance_after` ran 100 then
     * 160 over both companies. The Saudi settlement's last entry is the first one, and up to there the
     * ledger really was one chain, so its 0 and 100 are its own opening and closing balance. The Emirati
     * settlement's last entry is the second, and by then the running balance had both companies in it,
     * so it is not that settlement's closing balance and the settlement records neither.
     */
    public function test_a_settlement_drawn_after_the_running_balance_mixed_companies_reports_no_balances(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => false, 'gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);

        $balances = collect(app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER))
            ->mapWithKeys(fn ($settlement): array => [
                (int) $settlement->legal_entity_id => [$settlement->opening_balance, $settlement->closing_balance],
            ])
            ->all();

        $this->assertSame([self::COMPANY_SA => [0.0, 100.0], self::COMPANY_AE => [null, null]], $balances);
    }

    public function test_the_company_is_recorded_even_while_the_chains_are_not_told_apart(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => false]);

        $entry = $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertSame(self::COMPANY_AE, $entry->refresh()->legal_entity_id, 'the column is filled before the switch is thrown, or there would be no history to split');
    }

    public function test_with_the_switch_off_a_seller_has_the_one_chain_the_platform_has_always_had(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => false]);

        $riyals = $this->earning(100, 'SAR');
        $dirhams = $this->earning(40, 'AED');
        $emirati = $this->earning(10, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertSame(100.0, $riyals->balance_after);
        $this->assertSame(140.0, $dirhams->balance_after, 'off means the balance runs over everything, which is the platform of today');
        $this->assertSame(150.0, $emirati->balance_after);
    }

    public function test_with_the_switch_off_one_settlement_takes_both_companies(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => false, 'gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $settlements);
        $this->assertSame(160.0, (float) $settlements[0]->net_amount);
    }

    public function test_a_ledger_with_no_company_column_is_keyed_by_seller_and_currency_alone(): void
    {
        Schema::table('vendor_ledger_entries', fn ($table) => $table->dropColumn(LedgerChain::ENTITY_COLUMN));

        $saudi = $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $emirati = $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);
        $dirhams = $this->earning(40, 'AED', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertFalse(LedgerChain::entityIsRecorded());
        $this->assertSame(100.0, $saudi->balance_after);
        $this->assertSame(160.0, $emirati->balance_after, 'with nowhere to record the company, the currency is the whole of the key it can use');
        $this->assertSame(40.0, $dirhams->balance_after, 'the currency still tells one chain from another');
    }
}
