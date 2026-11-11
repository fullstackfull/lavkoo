<?php

namespace App\Services\SalesChannels\Amazon;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;
use Carbon\CarbonInterface;

/**
 * Amazon's settlement periods as Amazon itself identifies them, instead of as a date window we guessed.
 *
 * `GET /finances/v0/financialEventGroups`. The statements are already read and reconciled by date window; this gives each
 * period its own identity — `FinancialEventGroupId`, when it opened and closed, its beginning balance and what was actually
 * transferred — so a payout can be matched to a period rather than inferred from overlapping dates.
 *
 * ## Four things the published model says, and every one of them is a wrong answer if ignored
 *
 *  1. **A window wider than 180 days returns NOTHING, and is not an error.** Amazon: "If `FinancialEventGroupStartedAfter`
 *     and `FinancialEventGroupStartedBefore` are more than 180 days apart, no financial event groups are returned." So a
 *     caller asking about last year gets an empty list and a clean 200 — indistinguishable from a seller with no
 *     settlements. It is refused here, locally, before the call.
 *  2. **Both dates must be more than two minutes in the past.** Amazon says so of each of them. A window that ends "now" is
 *     an `InvalidInput`, which is the obvious thing for a nightly job to ask for.
 *  3. **`FundTransferDate` is "Only present for closed settlements".** Its absence means the period is still open, NOT that
 *     no money moved. Read the other way, every open period looks like an unpaid one.
 *  4. **`FundTransferStatus` has no documented value list at all** — the model gives the field and says only "The status of
 *     the fund transfer". So it is carried verbatim and never interpreted. `ProcessingStatus` does have one, exactly
 *     `Open` and `Closed`, and anything else is UNKNOWN rather than forced into one of the two.
 *
 * ## And one field that does not get stored
 *
 * `AccountTail` is "the account tail of the payment instrument" — the last digits of the seller's own bank account. It is
 * read and deliberately dropped: nothing in this system needs it, and the one thing worse than not having it is having it in
 * a log or a column nobody remembered was there.
 */
class AmazonFinanceGroups
{
    /** Amazon's own cap on `MaxResultsPerPage`. */
    private const MOST_PER_PAGE = 100;

    /** Past this, Amazon returns no groups at all rather than an error. */
    public const MOST_DAYS_APART = 180;

    /** Amazon requires both bounds to be more than two minutes old. A little more is asked, so a slow queue cannot miss it. */
    private const MUST_BE_OLDER_THAN_SECONDS = 180;

    /** The whole of Amazon's documented `ProcessingStatus`. */
    public const OPEN = 'Open';

    public const CLOSED = 'Closed';

    public function __construct(private readonly AmazonClient $client)
    {
    }

    /**
     * The settlement periods that opened inside a window.
     *
     * @return array{groups: list<array{id: string, status: string|null, transfer_status: string|null, opened_at: string|null, closed_at: string|null, original_total: array{amount: float|null, currency: string|null}, converted_total: array{amount: float|null, currency: string|null}, beginning_balance: array{amount: float|null, currency: string|null}, transferred_at: string|null, trace_id: string|null}>|null, next_token: string|null, problem: string|null}
     */
    public function opened(SalesChannelAccount $account, CarbonInterface $after, CarbonInterface $before, int $perPage = 100, ?string $nextToken = null): array
    {
        if (!$before->greaterThan($after)) {
            return $this->refuse('amazon_finance_group_window_ends_before_it_starts');
        }
        if ($after->diffInDays($before) > self::MOST_DAYS_APART) {
            // **The silent-empty case.** Amazon answers 200 with no groups for a window this wide, which a caller would read
            // as a seller with no settlements in the period. Refused before the call so that nobody ever sees that answer.
            return $this->refuse('amazon_finance_group_window_is_wider_than_amazon_answers:' . (int) $after->diffInDays($before) . '_days');
        }
        $cutoff = now()->subSeconds(self::MUST_BE_OLDER_THAN_SECONDS);
        if ($before->greaterThan($cutoff)) {
            // Amazon requires both bounds to be more than two minutes before the request. A nightly job asking up to "now"
            // is the ordinary way to trip this, and the answer would be an InvalidInput rather than data.
            return $this->refuse('amazon_finance_group_window_ends_too_close_to_now');
        }

        $query = [
            'MaxResultsPerPage' => max(1, min(self::MOST_PER_PAGE, $perPage)),
            'FinancialEventGroupStartedAfter' => $after->toIso8601ZuluString(),
            'FinancialEventGroupStartedBefore' => $before->toIso8601ZuluString(),
        ];
        if ($nextToken !== null && trim($nextToken) !== '') {
            // Amazon: "call the operation with this token and include the same arguments as the call that produced the token".
            $query['NextToken'] = trim($nextToken);
        }

        $response = $this->client->call($account, 'amazon.finances_groups', 'GET', '/finances/v0/financialEventGroups', $query);
        if (!$response->ok()) {
            return $this->refuse($this->problemOf($response, 'amazon_finance_groups_could_not_be_read'));
        }

        $payload = $this->payloadOf($response);
        if (!is_array($payload['FinancialEventGroupList'] ?? null)) {
            // The list is how this response is recognised. Without it nothing may be concluded -- least of all that there
            // are no settlement periods.
            return $this->refuse('amazon_finance_groups_reply_is_not_the_documented_shape');
        }

        $groups = [];
        foreach ($payload['FinancialEventGroupList'] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $id = trim((string) ($group['FinancialEventGroupId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $status = trim((string) ($group['ProcessingStatus'] ?? ''));
            $groups[] = [
                'id' => $id,
                // Exactly the two Amazon publishes, or null. A third value is something we were not told, not a guess.
                'status' => in_array($status, [self::OPEN, self::CLOSED], true) ? $status : null,
                // No documented value list, so it travels verbatim and nothing here reads meaning into it.
                'transfer_status' => ($transfer = trim((string) ($group['FundTransferStatus'] ?? ''))) === '' ? null : $transfer,
                'opened_at' => $this->dateOf($group['FinancialEventGroupStart'] ?? null),
                'closed_at' => $this->dateOf($group['FinancialEventGroupEnd'] ?? null),
                'original_total' => $this->moneyOf($group['OriginalTotal'] ?? null),
                'converted_total' => $this->moneyOf($group['ConvertedTotal'] ?? null),
                'beginning_balance' => $this->moneyOf($group['BeginningBalance'] ?? null),
                // Null means the period is not closed yet. It does NOT mean no money moved.
                'transferred_at' => $this->dateOf($group['FundTransferDate'] ?? null),
                'trace_id' => ($trace = trim((string) ($group['TraceId'] ?? ''))) === '' ? null : $trace,
                // `AccountTail` is read and dropped on purpose: see the class note.
            ];
        }

        return [
            'groups' => $groups,
            'next_token' => ($token = trim((string) ($payload['NextToken'] ?? ''))) === '' ? null : $token,
            'problem' => null,
        ];
    }

    /** Whether this period's money has actually moved, by what Amazon states rather than by a date being present. */
    public static function settled(array $group): bool
    {
        return ($group['status'] ?? null) === self::CLOSED && ($group['transferred_at'] ?? null) !== null;
    }

    /** @return array{groups: null, next_token: null, problem: string} */
    private function refuse(string $problem): array
    {
        return ['groups' => null, 'next_token' => null, 'problem' => $problem];
    }

    /** @return array{amount: float|null, currency: string|null} */
    private function moneyOf(mixed $money): array
    {
        if (!is_array($money)) {
            return ['amount' => null, 'currency' => null];
        }

        return [
            // Null rather than 0.0 where Amazon sent nothing: a missing total is not a zero total.
            'amount' => is_numeric($money['CurrencyAmount'] ?? null) ? (float) $money['CurrencyAmount'] : null,
            'currency' => ($code = trim((string) ($money['CurrencyCode'] ?? ''))) === '' ? null : $code,
        ];
    }

    private function dateOf(mixed $date): ?string
    {
        $value = is_string($date) ? trim($date) : '';

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function payloadOf(ChannelResponse $response): array
    {
        $json = $response->json;
        if (!is_array($json)) {
            return [];
        }

        return is_array($json['payload'] ?? null) ? $json['payload'] : $json;
    }

    private function problemOf(ChannelResponse $response, string $code): string
    {
        return $response->isDryRun() ? 'amazon_rehearsal_asked_nothing' : $code . ': ' . $response->message();
    }
}
