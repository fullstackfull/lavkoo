<?php

namespace App\Services\SalesChannels\Amazon;

/**
 * The report types this integration reads, and — the part that decides how each one is fetched — **whether Amazon lets it be
 * requested at all**.
 *
 * That distinction is not a nicety. From `/sp-api/docs/report-type-values-settlement`, verbatim:
 *
 * > "Settlement reports cannot be requested or scheduled. They are automatically scheduled by Amazon. You can search for these
 * > reports using the getReports operation."
 *
 * So a settlement report has NO `createReport` call in its life. Anything built on the assumption that every report is
 * requested would send a request that could never work, and would do it once a minute against an operation Amazon rates at
 * 0.0167 calls a second. The flag below is what keeps the two flows apart, and `AmazonReports` refuses to request a type that
 * carries `requestable: false` rather than trusting its callers.
 *
 * Every column name is what Amazon's own pages list — and **no page says its list is the document's header row**, so these are
 * the names `AmazonFlatFile` EXPECTS and the file is what decides. A column we cannot find is named, never filled in.
 */
final class AmazonReportTypes
{
    /**
     * The settlement statement: Amazon's own closed document for a payout period.
     *
     * The only one of the three settlement types not marked deprecated on Amazon's page, and the page says it "replaces the Flat
     * File Settlement Report and XML Settlement Report". Seller Central sellers only.
     */
    public const SETTLEMENT = 'GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE_V2';

    /** Every listing the seller has at Amazon. "This report can only be requested." */
    public const ALL_LISTINGS = 'GET_MERCHANT_LISTINGS_ALL_DATA';

    /** FBA stock as Amazon holds it. "This report can only be requested." "Content updated in near real-time." */
    public const FBA_INVENTORY = 'GET_AFN_INVENTORY_DATA';

    /**
     * What each type is and how it is reached.
     *
     * `columns` lists only the columns a reader here cannot work without, so a header that lacks one is a named refusal rather
     * than a parse that returns rows of empty cells. The documents carry many more.
     *
     * @return array<string, array{requestable: bool, note: string, columns: list<string>, options: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::SETTLEMENT => [
                // **The flag that keeps a settlement reader out of createReport.**
                'requestable' => false,
                'note' => 'Amazon schedules it; it is found with getReports and never requested. Seller Central sellers only.',
                // `amount` is the one money column: "Amount values always populate in a single `amount` column, so there's no
                // need to parse the rows to find a number, even if new fees or transaction types are introduced in the future."
                'columns' => ['settlement-id', 'currency', 'transaction-type', 'amount-type', 'amount'],
                'options' => [],
            ],
            self::ALL_LISTINGS => [
                'requestable' => true,
                'note' => 'A tab-delimited flat file with a UTF-8 BOM. One Amazon store per report: "only the first marketplaceId in the list will be accepted".',
                'columns' => ['seller-sku', 'quantity', 'status'],
                // Documented on the inventory page for this type, and the only two it documents.
                'options' => ['Custom', 'preferredReportDocumentLocale'],
            ],
            self::FBA_INVENTORY => [
                'requestable' => true,
                'note' => 'FBA Amazon Fulfilled Inventory Report. Amazon generates a near real-time FBA report no more than once every 30 minutes.',
                'columns' => [],
                'options' => [],
            ],
        ];
    }

    /** Is this a type we know how to read at all? A type nobody declared is never requested. */
    public static function known(string $reportType): bool
    {
        return isset(self::all()[$reportType]);
    }

    /**
     * May `createReport` be called for this type?
     *
     * False for a type Amazon schedules itself, and false for any type nobody declared — the safe answer for something unknown
     * is "do not send a request", not "try it and see what Amazon says".
     */
    public static function isRequestable(string $reportType): bool
    {
        return self::all()[$reportType]['requestable'] ?? false;
    }

    /** @return list<string> the columns a reader of this type cannot do without */
    public static function requiredColumns(string $reportType): array
    {
        return self::all()[$reportType]['columns'] ?? [];
    }

    /**
     * The `reportOptions` this type documents, filtered to what the caller asked for.
     *
     * An option Amazon does not document for a type is dropped rather than sent: `reportOptions` "varies by report type" and no
     * page lists a key for most types, so sending an invented one is a request whose behaviour nobody can predict.
     *
     * @param array<string, scalar> $wanted
     * @return array<string, string>
     */
    public static function optionsFor(string $reportType, array $wanted): array
    {
        $documented = self::all()[$reportType]['options'] ?? [];
        $options = [];
        foreach ($documented as $key) {
            if (array_key_exists($key, $wanted)) {
                $options[$key] = is_bool($wanted[$key]) ? ($wanted[$key] ? 'true' : 'false') : (string) $wanted[$key];
            }
        }

        return $options;
    }
}
