<?php

namespace App\Services\SalesChannels\Finance;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\SalesChannelAccount;
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
 * component (a cost component such as marketplace_commission), amount (a cost is positive, a credit negative), currency, description. A bad row refuses the WHOLE file and
 * names the line: a statement is never half-imported.
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
     * @return array{rows: int, new: int, duplicates: int, costs_recorded: int, needs_review: int}
     * @throws InvalidArgumentException naming the first bad line
     */
    public function import(SalesChannelAccount $account, string $csv): array
    {
        $entries = $this->parse($account, $csv);
        $stats = $this->importer->import($account, $entries);

        return ['rows' => count($entries)] + $stats;
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
        foreach ($lines as $index => $line) {
            $row = array_combine($header, array_pad(array_slice(str_getcsv($line, $delimiter, '"', ''), 0, count($header)), count($header), ''));
            $entries[] = $this->entry($account, $row, $index + 2);
        }

        return $entries;
    }

    /** @param array<string, string> $row */
    private function entry(SalesChannelAccount $account, array $row, int $lineNumber): RemoteFinancialEntry
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
        if (preg_match('/^-?\d+(\.\d{1,6})?$/', $amount) !== 1) {
            throw new InvalidArgumentException("Line {$lineNumber}: the amount must be a plain decimal number.");
        }
        $postedAt = $this->date($row['posted_at'], $lineNumber);
        $orderId = trim($row['order_id']) === '' ? null : trim($row['order_id']);
        $packageId = trim($row['package_id']) === '' ? null : trim($row['package_id']);

        return new RemoteFinancialEntry(
            identity: hash('sha256', json_encode(['manual', $account->id, $orderId, $packageId, $postedAt->toIso8601String(), $category, $component->value, $amount, $currency, trim($row['description'])], JSON_THROW_ON_ERROR)),
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
            meta: ['description' => mb_substr(trim($row['description']), 0, 250), 'source' => 'manual_statement', 'line' => $lineNumber],
        );
    }

    private function date(string $value, int $lineNumber): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(trim($value));
        } catch (InvalidFormatException) {
            throw new InvalidArgumentException("Line {$lineNumber}: '{$value}' is not a date.");
        }
    }
}
