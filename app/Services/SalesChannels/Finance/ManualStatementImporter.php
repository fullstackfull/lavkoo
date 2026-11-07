<?php

namespace App\Services\SalesChannels\Finance;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;

/**
 * Reads a statement a person exported from a channel's seller portal (for what its API does not give) into the same facts the API would have produced, and hands them to
 * the ChannelFeeImporter: a fact already imported is not imported again, a payout is not revenue, an unclassifiable row is kept for a person.
 *
 * Columns (header row required, comma or semicolon separated): order_id, package_id, posted_at, category (fee, adjustment, sale, refund, payout, unclassified),
 * component (a cost component such as marketplace_commission), amount (a cost is positive, a credit negative), currency, description, and optionally settlement_id (what a charge
 * of no order is about). A bad row refuses the WHOLE file and names the line: a statement is never half-imported. Dates are written as 2026-11-05 (optionally with a time and a
 * zone), never as a word or a day that does not exist.
 *
 * Importing the same file again records nothing twice, and 25, 25.0 and 25.00 are the same amount (a row's identity is built from the amount through Money). Two lines that are
 * exactly alike within one file are two charges (a fee per unit of a two-unit order), so the second one carries its place among the alike ones in its identity.
 *
 * A fee a row repeats that the channel API already gave is not counted a second time: ChannelFeeImporter records it as a duplicate (see ChargeOverlapMatcher), and the result says
 * how many rows were skipped for that reason and how many may overlap with the API and wait for a person.
 */
class ManualStatementImporter
{
    private const CATEGORIES = [
        RemoteFinancialEntry::FEE, RemoteFinancialEntry::ADJUSTMENT, RemoteFinancialEntry::SALE, RemoteFinancialEntry::REFUND, RemoteFinancialEntry::PAYOUT, RemoteFinancialEntry::UNCLASSIFIED,
    ];

    public function __construct(private readonly ChannelFeeImporter $importer)
    {
    }

    /**
     * @return array{rows: int, new: int, duplicates: int, costs_recorded: int, needs_review: int, already_via_api: int, possible_overlap: int}
     * @throws InvalidArgumentException naming the first bad line
     */
    public function import(SalesChannelAccount $account, string $csv): array
    {
        $entries = $this->parse($account, $csv);
        $before = $this->storedIdentities($account, $entries);
        $stats = $this->importer->import($account, $entries, ChannelFeeImporter::SOURCE_MANUAL);
        $stored = SalesChannelEvent::where('account_id', $account->id)->whereIn('dedupe_key', array_diff(array_map(static fn (RemoteFinancialEntry $entry): string => $entry->identity, $entries), $before));

        return ['rows' => count($entries)] + $stats + [
            'already_via_api' => (clone $stored)->where('outcome', SalesChannelEvent::DUPLICATE)->count(),
            'possible_overlap' => (clone $stored)->where('outcome_note', SalesChannelEvent::NOTE_POSSIBLE_OVERLAP)->count(),
        ];
    }

    /**
     * @param list<RemoteFinancialEntry> $entries
     * @return list<string> the identities of the rows that were stored before this import
     */
    private function storedIdentities(SalesChannelAccount $account, array $entries): array
    {
        $known = [];
        foreach (array_chunk(array_map(static fn (RemoteFinancialEntry $entry): string => $entry->identity, $entries), 500) as $identities) {
            array_push($known, ...SalesChannelEvent::where('account_id', $account->id)->whereIn('dedupe_key', $identities)->pluck('dedupe_key')->all());
        }

        return $known;
    }

    /** @return list<RemoteFinancialEntry> */
    public function parse(SalesChannelAccount $account, string $csv): array
    {
        $lines = preg_split('/\R/u', ltrim($csv, "\xEF\xBB\xBF"), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($lines === []) {
            throw new InvalidArgumentException('The statement is empty.');
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn (?string $name): string => strtolower(trim((string) $name)), str_getcsv(array_shift($lines), $delimiter, '"', ''));
        $missing = array_diff(['order_id', 'package_id', 'posted_at', 'category', 'component', 'amount', 'currency', 'description'], $header);
        if ($missing !== []) {
            throw new InvalidArgumentException('The header row lacks: ' . implode(', ', $missing) . '.');
        }

        $entries = [];
        $alike = [];
        foreach ($lines as $index => $line) {
            $row = array_combine($header, array_pad(array_slice(str_getcsv($line, $delimiter, '"', ''), 0, count($header)), count($header), ''));
            $signature = hash('sha256', json_encode(['amount' => $this->canonicalAmount($row['amount'])] + array_map('trim', $row), JSON_THROW_ON_ERROR));
            $alike[$signature] = ($alike[$signature] ?? -1) + 1;
            $entries[] = $this->entry($account, $row, $index + 2, occurrence: $alike[$signature]);
        }

        return $entries;
    }

    /** @param array<string, string> $row */
    private function entry(SalesChannelAccount $account, array $row, int $lineNumber, int $occurrence): RemoteFinancialEntry
    {
        $category = strtolower(trim($row['category']));
        $component = CostComponent::tryFrom(strtolower(trim($row['component'])));
        $currency = strtoupper(trim($row['currency']));
        $amount = trim($row['amount']);
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Line {$lineNumber}: '{$row['category']}' is not a category.");
        }
        if ($component === null) {
            throw new InvalidArgumentException("Line {$lineNumber}: '{$row['component']}' is not a cost component.");
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Line {$lineNumber}: the currency must be a three-letter code.");
        }
        if (preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', $amount) !== 1) {
            throw new InvalidArgumentException("Line {$lineNumber}: the amount must be a plain decimal number of at most 14 digits before the point.");
        }
        $postedAt = $this->date($row['posted_at'], $lineNumber);
        $orderId = trim($row['order_id']) === '' ? null : trim($row['order_id']);
        $packageId = trim($row['package_id']) === '' ? null : trim($row['package_id']);
        $settlementId = trim($row['settlement_id'] ?? '') === '' ? null : trim($row['settlement_id']);
        $canonicalAmount = $this->canonicalAmount($amount);

        return new RemoteFinancialEntry(
            identity: hash('sha256', json_encode(array_filter(['manual', $account->id, $orderId, $packageId, $settlementId, $postedAt->toIso8601String(), $category, $component->value, $canonicalAmount, $currency, trim($row['description']), $occurrence > 0 ? 'alike#' . $occurrence : null], static fn ($part): bool => $part !== null), JSON_THROW_ON_ERROR)),
            category: $category,
            component: $component,
            amount: Money::of($amount),
            currency: $currency,
            orderId: $orderId,
            packageId: $packageId,
            lineId: null,
            sku: null,
            postedAt: $postedAt,
            rawType: 'manual_statement',
            meta: array_filter(['description' => mb_substr(trim($row['description']), 0, 250), 'source' => 'manual_statement', 'line' => $lineNumber, 'settlement_id' => $settlementId], static fn ($value): bool => $value !== null),
        );
    }

    /** The amount of a row as its identity sees it: 25, 25.0 and 25.00 are the same; what is not a number is left for the row's own check to refuse. */
    private function canonicalAmount(string $amount): string
    {
        $amount = trim($amount);

        return preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', $amount) === 1 ? Money::of($amount)->toStorage() : $amount;
    }

    private function date(string $value, int $lineNumber): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Line {$lineNumber}: '{$value}' is not a date; write it as 2026-11-05 or 2026-11-05 14:30:00.");
        }
        try {
            $parsed = CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            throw new InvalidArgumentException("Line {$lineNumber}: '{$value}' is not a date.");
        }
        // 2026-02-31 reads as the 3rd of March: a day that does not exist is refused, not moved.
        if ($parsed->format('Y-m-d') !== substr($value, 0, 10)) {
            throw new InvalidArgumentException("Line {$lineNumber}: '{$value}' is not a day of the calendar.");
        }

        return $parsed;
    }
}
