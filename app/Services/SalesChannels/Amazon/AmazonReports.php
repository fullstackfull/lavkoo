<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Support\ChannelHttp;
use Carbon\CarbonImmutable;

/**
 * Reports 2021-06-30: asking Amazon for a document, finding one it made by itself, and fetching the contents.
 *
 * ## Two flows, and conflating them would be the bug
 *
 *  - **A requestable report** (`GET_MERCHANT_LISTINGS_ALL_DATA`, `GET_AFN_INVENTORY_DATA`): `createReport` → poll `getReport`
 *    → `getReportDocument` → download.
 *  - **A settlement report**: `getReports` filtered by type → `getReportDocument` → download. **There is no request step at
 *    all** — "Settlement reports cannot be requested or scheduled. They are automatically scheduled by Amazon." So `request()`
 *    refuses such a type by name instead of sending a call that could never succeed, which also matters because `createReport`
 *    is rated at one call a minute and a retry loop against it would spend the whole account's budget.
 *
 * ## What the rate limits force
 *
 * `createReport` 0.0167/s (one a minute), `getReports` 0.0222/s, `getReportDocument` 0.0167/s, `getReport` 2/s. So the one call
 * that may be made freely is the poll, and every other step is precious — which is why a `reportId` is recorded the moment
 * Amazon gives one (`AmazonReportRun`) and a document is fetched once.
 *
 * ## And the document is fetched, read and dropped
 *
 * The presigned URL "expires after 5 minutes", and `/sp-api/docs/retrieve-a-report` says: "You must always maintain encryption
 * at rest. Unencrypted report content must never be stored on disk, even temporarily." So the contents are returned to the
 * caller in memory and this class writes no file. The size ceiling below is what keeps that honest: a document larger than it
 * is refused by name rather than loaded, because the HTTP client hands the whole body over at once.
 */
class AmazonReports
{
    /** Only `GZIP` is documented as a `compressionAlgorithm` value; anything else is refused rather than guessed at. */
    private const GZIP = 'GZIP';

    public function __construct(
        private readonly AmazonClient $client,
        private readonly ChannelHttp $http,
    ) {
    }

    /**
     * Asks Amazon to make a report. **Refuses a type Amazon schedules itself.**
     *
     * @param list<string> $marketplaceIds for the all-listings report Amazon accepts only the first, by its own note, so one
     *                                     marketplace is passed and the caller is told rather than having four silently ignored
     * @param array<string, scalar> $options only keys Amazon documents for the type survive
     * @return array{report_id: string|null, problem: string|null}
     * @throws ChannelPermissionDenied
     */
    public function request(
        SalesChannelAccount $account,
        string $reportType,
        array $marketplaceIds,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        array $options = [],
    ): array {
        if (!AmazonReportTypes::known($reportType)) {
            return ['report_id' => null, 'problem' => 'amazon_report_type_not_declared:' . $reportType];
        }
        if (!AmazonReportTypes::isRequestable($reportType)) {
            // Amazon's own words, and the reason this check exists rather than a hopeful call.
            return ['report_id' => null, 'problem' => 'amazon_report_type_is_not_requestable:' . $reportType];
        }
        $marketplaceIds = array_values(array_filter(array_map('trim', $marketplaceIds)));
        if ($marketplaceIds === []) {
            return ['report_id' => null, 'problem' => 'amazon_report_needs_a_marketplace'];
        }

        $body = array_filter([
            'reportType' => $reportType,
            // maxItems 25 on the schema; the all-listings page warns that only the first is accepted for that type, which the
            // caller handles by passing one.
            'marketplaceIds' => array_slice($marketplaceIds, 0, 25),
            'dataStartTime' => $from?->toIso8601ZuluString(),
            'dataEndTime' => $to?->toIso8601ZuluString(),
            'reportOptions' => AmazonReportTypes::optionsFor($reportType, $options),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $response = $this->client->call($account, 'amazon.reports_create', 'POST', '/reports/2021-06-30/reports', json: $body);
        if (!$response->ok()) {
            return ['report_id' => null, 'problem' => $this->problemOf($response, 'amazon_report_could_not_be_requested')];
        }

        $reportId = trim((string) $response->get('reportId', ''));

        // A 202 with no id is not a report we can ever find again: Amazon may well be making one, and we would never know
        // which. Named, so a person can look in Seller Central rather than us requesting a second.
        return $reportId === ''
            ? ['report_id' => null, 'problem' => 'amazon_report_was_accepted_without_an_id']
            : ['report_id' => $reportId, 'problem' => null];
    }

    /** What Amazon says about one report. @throws ChannelPermissionDenied */
    public function status(SalesChannelAccount $account, string $reportId): AmazonReportState
    {
        $response = $this->client->call($account, 'amazon.reports_get', 'GET', '/reports/2021-06-30/reports/' . rawurlencode($reportId));
        if (!$response->ok()) {
            return AmazonReportState::couldNotBeAsked($response, $reportId);
        }

        $report = $response->json ?? [];

        return AmazonReportState::of(is_array($report['payload'] ?? null) ? $report['payload'] : $report);
    }

    /**
     * The reports Amazon already holds of these types — the ONLY way a settlement report is ever found.
     *
     * One page, deliberately: `getReports` is rated at 0.0222 calls a second and its `nextToken` "with any other parameters
     * will cause the request to fail", so paging is the caller's decision and each page costs a call. The token is handed back
     * so a caller that wants the next page asks for it.
     *
     * @param list<string> $reportTypes maxItems 10 on the schema
     * @return array{reports: list<AmazonReportState>, next_token: string|null, problem: string|null}
     * @throws ChannelPermissionDenied
     */
    public function find(
        SalesChannelAccount $account,
        array $reportTypes = [],
        ?CarbonImmutable $createdSince = null,
        ?CarbonImmutable $createdUntil = null,
        int $pageSize = 10,
        ?string $nextToken = null,
    ): array {
        if ($nextToken !== null && trim($nextToken) !== '') {
            // Amazon's rule, not a preference: the token travels alone.
            $query = ['nextToken' => trim($nextToken)];
        } else {
            $types = array_values(array_slice(array_filter(array_map('trim', $reportTypes)), 0, 10));
            if ($types === []) {
                // "Either reportTypes or nextToken is required."
                return ['reports' => [], 'next_token' => null, 'problem' => 'amazon_report_search_needs_a_type_or_a_token'];
            }
            $query = array_filter([
                'reportTypes' => implode(',', $types),
                'pageSize' => (string) max(1, min(100, $pageSize)),
                'createdSince' => $createdSince?->toIso8601ZuluString(),
                'createdUntil' => $createdUntil?->toIso8601ZuluString(),
            ]);
        }

        $response = $this->client->call($account, 'amazon.reports_list', 'GET', '/reports/2021-06-30/reports', query: $query);
        if (!$response->ok()) {
            return ['reports' => [], 'next_token' => null, 'problem' => $this->problemOf($response, 'amazon_reports_could_not_be_listed')];
        }

        $payload = is_array($response->json['payload'] ?? null) ? $response->json['payload'] : ($response->json ?? []);
        $rows = is_array($payload['reports'] ?? null) ? $payload['reports'] : [];
        $token = trim((string) ($payload['nextToken'] ?? ''));

        return [
            'reports' => array_values(array_map(
                static fn (array $report): AmazonReportState => AmazonReportState::of($report),
                array_filter($rows, 'is_array'),
            )),
            'next_token' => $token === '' ? null : $token,
            'problem' => null,
        ];
    }

    /**
     * Fetches the contents of one report document.
     *
     * Two calls: `getReportDocument` for the presigned URL, then the download itself. The download carries **no Amazon
     * credential** — it is a presigned S3 URL and adding `x-amz-access-token` to it is both pointless and a credential sent
     * somewhere it was not needed.
     *
     * @return array{contents: string|null, problem: string|null}
     * @throws ChannelPermissionDenied
     */
    public function contents(SalesChannelAccount $account, string $documentId): array
    {
        $response = $this->client->call($account, 'amazon.reports_document', 'GET', '/reports/2021-06-30/documents/' . rawurlencode($documentId));
        if (!$response->ok()) {
            return ['contents' => null, 'problem' => $this->problemOf($response, 'amazon_report_document_could_not_be_read')];
        }

        $payload = is_array($response->json['payload'] ?? null) ? $response->json['payload'] : ($response->json ?? []);
        $url = trim((string) ($payload['url'] ?? ''));
        if ($url === '') {
            return ['contents' => null, 'problem' => 'amazon_report_document_has_no_url'];
        }
        $compression = trim((string) ($payload['compressionAlgorithm'] ?? ''));
        if ($compression !== '' && $compression !== self::GZIP) {
            // GZIP is the only value Amazon's enum carries. An unknown algorithm is named rather than fed to a decoder that
            // would hand back bytes nobody could tell from a corrupt document.
            return ['contents' => null, 'problem' => 'amazon_report_compression_not_understood:' . $compression];
        }

        return $this->download($account, $url, $compression === self::GZIP);
    }

    /**
     * The presigned download, and the one thing that keeps it from taking the process down.
     *
     * The client buffers the whole body, so a report bigger than the ceiling is refused AFTER it arrives rather than before —
     * which is a real limit and is named as one: it bounds what gets parsed and tells the operator, but it does not bound what
     * gets received. A seller whose catalogue outgrows it needs the streaming download, which is a declared next step rather
     * than a thing quietly half-done here.
     *
     * @return array{contents: string|null, problem: string|null}
     */
    private function download(SalesChannelAccount $account, string $url, bool $gzipped): array
    {
        $response = $this->http->send($account, 'amazon.report_download', 'GET', $url);
        if ($response->isDryRun()) {
            return ['contents' => null, 'problem' => 'amazon_rehearsal_downloaded_nothing'];
        }
        if (!$response->ok()) {
            // Five minutes is all the URL is good for, so "it expired" is the likely reason and the caller must ask for a fresh
            // document rather than retrying this URL.
            return ['contents' => null, 'problem' => $this->problemOf($response, 'amazon_report_download_failed')];
        }

        $ceiling = max(1, (int) config('sales_channels.amazon.report_max_bytes', 33554432));
        if (strlen($response->body) > $ceiling) {
            return ['contents' => null, 'problem' => 'amazon_report_is_larger_than_we_will_read:' . strlen($response->body)];
        }

        if (!$gzipped) {
            return ['contents' => $response->body, 'problem' => null];
        }

        // `enableContentEncodingUrlHeader` is NOT asked for, so the body arrives compressed and is decoded here. Decoding is
        // allowed to fail: a body that is not gzip after Amazon said it was tells us nothing we can parse.
        $plain = @gzdecode($response->body);
        if ($plain === false) {
            return ['contents' => null, 'problem' => 'amazon_report_gzip_could_not_be_read'];
        }
        if (strlen($plain) > $ceiling) {
            return ['contents' => null, 'problem' => 'amazon_report_is_larger_than_we_will_read:' . strlen($plain)];
        }

        return ['contents' => $plain, 'problem' => null];
    }

    private function problemOf(ChannelResponse $response, string $code): string
    {
        return $response->isDryRun() ? 'amazon_rehearsal_asked_nothing' : $code . ': ' . $response->message();
    }
}
