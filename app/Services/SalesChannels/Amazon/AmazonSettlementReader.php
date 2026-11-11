<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\AmazonReportRun;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Invoices\InvoiceResult;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;

/**
 * Turns one Amazon settlement report into a **marketplace statement** in the cost ledger — a document, not a set of costs.
 *
 * ## Why a statement and not cost entries
 *
 * The Finances API is already read, and it already records every fee it finds as a ledger entry. A second path that also wrote
 * entries from the settlement report would book the same commission twice, which is the one mistake the owner named outright.
 *
 * So the settlement report lands where the ledger already has a place for it: `kind = marketplace_statement`,
 * `party_type = marketplace`. That is the **source** leg of a reconciliation — the channel's own published document — and the
 * Finances entries are the **ledger** leg. They are meant to be compared, and `ReconciliationService` is what compares them.
 * The reconciliation this gives Amazon is the one it did not have: until now there was no Amazon statement to compare against.
 *
 * ## Nothing here is approved, and nothing here is a cost until a person says so
 *
 * `InvoiceService::create()` writes it `draft` or `under_review` and approval is its own step with its own audit record. This
 * reader never approves, for the same reason no estimate is posted to Odoo: a figure that entered the books because a file
 * arrived is a figure nobody checked.
 *
 * ## What becomes a line, and what is only counted
 *
 * A settlement holds fees, sales, refunds, payouts and whatever Amazon invents next. Only what classifies as a **fee or an
 * adjustment** becomes an invoice item, because an invoice item carries a `cost_component` and a sale is not a cost. The rest
 * is counted by category and said in the notes — **including the labels that matched nothing**, because an amount type nobody
 * recognised is exactly what a person needs to see, and the same label is UNCLASSIFIED in the Finances reader too.
 *
 * The classification is `AmazonFinanceMapper::classify()`, not a second set of patterns: one place decides what one of Amazon's
 * money labels means.
 *
 * ## And the totals say two different things on purpose
 *
 * `total` is the sum of the lines WE read — the fees in this settlement — so the ledger's own "do the items add up" check
 * actually checks our arithmetic. Amazon's `total-amount` is the net deposit for the period, which includes sales and
 * transfers, so it is recorded in the notes rather than as the invoice's total. Putting the deposit in `total` would make every
 * statement look like it failed to add up, and the check would be worthless from the first one.
 */
class AmazonSettlementReader implements ReadsAmazonReports
{
    /** Categories that are a cost to us and therefore may carry a cost component. */
    private const COSTS = [RemoteFinancialEntry::FEE, RemoteFinancialEntry::ADJUSTMENT];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AmazonFinanceMapper $labels,
    ) {
    }

    public function handles(string $reportType): bool
    {
        return $reportType === AmazonReportTypes::SETTLEMENT;
    }

    /**
     * @return array{outcome: string|null, rows_read: int, rows_refused: int, detail: string|null}
     */
    public function read(AmazonReportRun $run, AmazonFlatFile $file, SalesChannelAccount $account): array
    {
        $header = $this->headerRow($file);
        if ($header === null) {
            return ['outcome' => 'no_settlement_header', 'rows_read' => 0, 'rows_refused' => count($file->rows), 'detail' => 'No row carries a settlement-id, so this document does not describe a settlement.'];
        }

        $items = [];
        $counted = [];
        $unmatched = [];
        $refused = 0;

        foreach ($file->rows as $row) {
            $amountCell = $file->cell($row, 'amount');
            $amount = AmazonFlatFile::amount($amountCell);
            if ($amount['problem'] !== null) {
                // A money cell nobody could read. **Counted and named, never cast**: Amazon writes amounts in the
                // marketplace's own notation and a wrong read here is a wrong figure in somebody's statement.
                $refused++;
                $unmatched['amount:' . $amount['problem']] = true;

                continue;
            }
            if ($amount['amount'] === null) {
                // The settlement's header row: it carries the period and the deposit, and no amount of its own.
                continue;
            }

            $transactionType = (string) ($file->cell($row, 'transaction-type') ?? '');
            $amountType = (string) ($file->cell($row, 'amount-type') ?? '');
            $description = (string) ($file->cell($row, 'amount-description') ?? '');
            [$category, $component] = $this->labels->classify($transactionType, array_values(array_filter([$amountType, $description])));

            $counted[$category] = ($counted[$category] ?? 0) + 1;
            if ($category === RemoteFinancialEntry::UNCLASSIFIED) {
                $label = trim($amountType . ' ' . $description);
                $unmatched[$label === '' ? $transactionType : $label] = true;

                continue;
            }
            if (!in_array($category, self::COSTS, true)) {
                // A sale, a refund or a payout. Real, and not a cost: it is counted so the notes can say what the statement
                // held, and it is not an invoice item because an item carries a cost component.
                continue;
            }
            if ($component === null) {
                $unmatched[trim($amountType . ' ' . $description)] = true;
                $refused++;

                continue;
            }

            $items[] = array_filter([
                'cost_component' => $component->value,
                // A fee is money leaving us, and Amazon writes it negative in the settlement. The ledger holds a cost as a
                // positive figure, so the sign is turned here -- once, where the document is read.
                'amount' => Money::of((string) $amount['amount'])->negated()->toStorage(),
                'currency' => (string) ($file->cell($row, 'currency') ?? $account->currency ?? ''),
                'description' => mb_substr(trim($amountType . ' ' . $description), 0, 191) ?: null,
                'service_date' => $this->date($file->cell($row, 'posted-date')),
                'external_reference' => $file->cell($row, 'order-id') ?: null,
                'sku' => $file->cell($row, 'sku') ?: null,
                'quantity' => $file->cell($row, 'quantity-purchased') ?: null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $this->write($run, $account, $header, $items, $counted, array_keys($unmatched), $refused, count($file->rows));
    }

    /**
     * Writes the statement, or says why it was not written.
     *
     * @param array<string, string|null> $header
     * @param list<array<string, mixed>> $items
     * @param array<string, int> $counted
     * @param list<string> $unmatched
     * @return array{outcome: string|null, rows_read: int, rows_refused: int, detail: string|null}
     */
    private function write(
        AmazonReportRun $run,
        SalesChannelAccount $account,
        array $header,
        array $items,
        array $counted,
        array $unmatched,
        int $refused,
        int $rowCount,
    ): array {
        $lines = Money::sum(array_map(static fn (array $item): Money => Money::of((string) $item['amount']), $items));
        $notes = $this->notes($header, $counted, $unmatched, $refused);

        $result = $this->invoices->create(
            [
                // The ledger already had a kind for exactly this: Amazon's own published statement for a payout period.
                'kind' => 'marketplace_statement',
                'party_type' => 'marketplace',
                'provider_code' => 'amazon',
                'party_name' => 'Amazon',
                // **Amazon's own settlement number is the invoice number**, which is what makes importing the same report twice
                // one statement: `InvoiceService` refuses a second invoice with the same number for the same provider. Amazon
                // offers no idempotency of its own anywhere in Reports, so this is it.
                'invoice_number' => 'SETTLEMENT-' . $header['settlement-id'],
                'invoice_date' => $header['deposit-date'] ?? $header['settlement-end-date'] ?? now()->toDateString(),
                'period_start' => $header['settlement-start-date'],
                'period_end' => $header['settlement-end-date'],
                'currency' => $header['currency'] ?? (string) ($account->currency ?? ''),
                // The lines we read, not Amazon's net deposit -- see the class note. The deposit is in the notes.
                'total' => $lines->toStorage(),
                'account_id' => (int) $account->id,
                'notes' => $notes,
                'import_batch' => 'amazon-report-' . $run->id,
                // A second defence beside the settlement number: the same document, however Amazon numbers it, is one statement.
                'file_hash' => $run->document_hash ?? hash('sha256', $header['settlement-id'] . '|' . $lines->toStorage()),
            ],
            $items,
            adminId: null,
            source: 'channel_api',
            sellerId: (int) ($account->seller_id ?? 0),
        );

        $read = $rowCount - $refused;
        if ($result->result === InvoiceResult::DUPLICATE) {
            // Already imported. Not a failure and not a second statement: the row records which one it was.
            return ['outcome' => 'statement_already_imported', 'rows_read' => $read, 'rows_refused' => $refused, 'detail' => 'Statement ' . $header['settlement-id'] . ' is already invoice #' . $result->invoice->id . '.'];
        }

        return [
            'outcome' => 'statement_' . $result->invoice->id,
            'rows_read' => $read,
            'rows_refused' => $refused,
            'detail' => 'Invoice #' . $result->invoice->id . ' (' . $result->invoice->status . ') with ' . count($items) . ' fee lines. ' . implode(' ', $result->warnings),
        ];
    }

    /**
     * The settlement's own header values, taken from the first row that carries a settlement id.
     *
     * Amazon puts the period, the currency and the deposit on one row with no `amount`, and repeats the settlement id on every
     * later row. Reading them from the first row that has the id rather than from "row 1" means a file with a blank line or a
     * reordered first row still works.
     *
     * @return array<string, string|null>|null null when no row carries a settlement id at all
     */
    private function headerRow(AmazonFlatFile $file): ?array
    {
        foreach ($file->rows as $row) {
            $id = trim((string) ($file->cell($row, 'settlement-id') ?? ''));
            if ($id === '') {
                continue;
            }

            return [
                'settlement-id' => $id,
                'settlement-start-date' => $this->date($file->cell($row, 'settlement-start-date')),
                'settlement-end-date' => $this->date($file->cell($row, 'settlement-end-date')),
                'deposit-date' => $this->date($file->cell($row, 'deposit-date')),
                'currency' => trim((string) ($file->cell($row, 'currency') ?? '')) ?: null,
                'total-amount' => AmazonFlatFile::amount($file->cell($row, 'total-amount'))['amount'],
            ];
        }

        return null;
    }

    /**
     * What the statement held, in words, including what was not understood.
     *
     * The unmatched labels are the point. Amazon's amount types are an open set — its own page says new fee and transaction
     * types will be introduced — so a label nobody matched is news, and a note that listed only what worked would hide it.
     *
     * @param array<string, string|null> $header
     * @param array<string, int> $counted
     * @param list<string> $unmatched
     */
    private function notes(array $header, array $counted, array $unmatched, int $refused): string
    {
        $parts = ['Amazon settlement ' . $header['settlement-id'] . ' read from a report document.'];
        if ($header['total-amount'] !== null) {
            $parts[] = 'Amazon states a net deposit of ' . $header['total-amount'] . ' ' . ($header['currency'] ?? '')
                . ' for the period, which includes sales and transfers; this statement totals only the fee lines.';
        }
        foreach ($counted as $category => $count) {
            $parts[] = $category . ': ' . $count . ' rows.';
        }
        if ($refused > 0) {
            $parts[] = $refused . ' rows could not be read and are not in this statement.';
        }
        if ($unmatched !== []) {
            $parts[] = 'Labels that matched no rule: ' . implode('; ', array_slice($unmatched, 0, 20)) . '.';
        }

        return mb_substr(implode(' ', $parts), 0, 2000);
    }

    /** A date cell as `Y-m-d`, or null. Amazon writes several shapes here and a cell nobody can read is better left out. */
    private function date(?string $cell): ?string
    {
        $text = trim((string) $cell);
        if ($text === '') {
            return null;
        }
        $time = strtotime($text);

        return $time === false ? null : date('Y-m-d', $time);
    }
}
