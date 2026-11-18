<?php

namespace App\Services\Storefront;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * I2-07 and GCC-11: a storefront other than the one that keeps its old documents does not sell until an independent legal review of its texts is on record: who reviewed
 * them (a lawyer or a firm of that country), where the opinion is written down and the day it was given. The texts being approved inside the platform (by someone other than their
 * author) is not that review: the platform cannot know what a country's law requires, and a market opened on texts nobody qualified read is a legal risk the owner never took.
 *
 * The review is a business setting per storefront (`storefront_legal_review_<country>`). It is kept with the audit line of whoever recorded it, in the same transaction. This service
 * records what an operator tells it. It does not and cannot review anything: the reviewer, the opinion and the date are the owner's, and a tool or an assistant is refused as
 * a reviewer. Whether a new version of a text needs a new review is the owner's decision too; today a recorded review stays until it is withdrawn.
 */
#[Singleton]
final class StorefrontLegalReview
{
    /** The storefront keeps the documents it has always had and needs no review recorded here. */
    public const NOT_REQUIRED = 'not_required';

    public const MISSING = 'missing';

    /** A row that does not say who reviewed, where the opinion is written or on which day counts for nothing. */
    public const INCOMPLETE = 'incomplete';

    public const RECORDED = 'recorded';

    private const SETTING_PREFIX = 'storefront_legal_review_';

    public function __construct(
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly StorefrontDecisionRecord $decisions,
    ) {
    }

    /** The name of the business setting that holds the review of one storefront. */
    public static function settingName(string $country): string
    {
        return self::SETTING_PREFIX . strtolower(trim($country));
    }

    /** Whether the storefront has a legal review on record. A storefront that keeps its old documents needs none. */
    public function isRecorded(string $country): bool
    {
        return in_array($this->statusOf($country)['status'], [self::NOT_REQUIRED, self::RECORDED], true);
    }

    /**
     * @return array{status: string, reviewed_by: ?string, reference: ?string, reviewed_on: ?string, recorded_at: ?string}
     */
    public function statusOf(string $country): array
    {
        $code = strtoupper(trim($country));

        if ($this->legalReadiness->isGrandfathered($code)) {
            return $this->status(status: self::NOT_REQUIRED);
        }

        $record = $this->decisions->of(setting: self::settingName($code));

        if ($record === null) {
            return $this->status(status: self::MISSING);
        }

        if (!is_array($record)) {
            return $this->status(status: self::INCOMPLETE);
        }

        $given = [
            'reviewed_by' => $this->textOf($record['reviewed_by'] ?? null),
            'reference' => $this->textOf($record['reference'] ?? null),
            'reviewed_on' => $this->textOf($record['reviewed_on'] ?? null),
            'recorded_at' => $this->textOf($record['recorded_at'] ?? null),
        ];

        return ['status' => in_array(null, $given, true) ? self::INCOMPLETE : self::RECORDED] + $given;
    }

    /**
     * Records the review for one storefront, with its audit line, in one transaction: a review whose trace cannot be written is not recorded.
     *
     * @return array{reviewed_by: string, reference: string, reviewed_on: string, recorded_at: string}
     * @throws InvalidArgumentException with the reason, when the review cannot be recorded
     */
    public function record(string $country, string $reviewedBy, string $reference, string $reviewedOn): array
    {
        $code = strtoupper(trim($country));
        $refusal = $this->refusalToRecord(country: $code, reviewedBy: trim($reviewedBy), reference: trim($reference), reviewedOn: trim($reviewedOn));

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        $record = [
            'reviewed_by' => trim($reviewedBy),
            'reference' => trim($reference),
            'reviewed_on' => trim($reviewedOn),
            'recorded_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $this->decisions->write(setting: self::settingName($code), record: $record, action: 'storefront.legal_review.recorded', country: $code);

        return $record;
    }

    /**
     * Withdraws the review, so that the storefront stops selling. False when there was none.
     *
     * @throws InvalidArgumentException when the storefront keeps its old documents or there is no settings table
     */
    public function revoke(string $country, ?string $revokedBy = null): bool
    {
        $code = strtoupper(trim($country));
        $refusal = $this->refusalToRevoke(country: $code);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        return $this->decisions->withdraw(
            setting: self::settingName($code),
            action: 'storefront.legal_review.revoked',
            country: $code,
            context: ['revoked_by' => $revokedBy === null || trim($revokedBy) === '' ? null : trim($revokedBy)],
        );
    }

    private function refusalToRecord(string $country, string $reviewedBy, string $reference, string $reviewedOn): ?string
    {
        return match (true) {
            $this->legalReadiness->isGrandfathered($country) => "{$country} keeps the documents it has always had and needs no review recorded here.",
            !in_array($country, StorefrontResolver::fromConfig()->configuredCountries(), true) => "{$country} is not a configured storefront.",
            $reviewedBy === '' || mb_strlen($reviewedBy) > StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH => 'Say who reviewed the texts: the name of the lawyer or of the firm, in at most ' . StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH . ' characters.',
            StorefrontDecisionRecord::namesATool($reviewedBy) => 'A review names a lawyer or a firm that read the texts, not a tool or an assistant.',
            $reference === '' || mb_strlen($reference) > StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH => 'Say where the opinion is written down: the opinion, the ticket or the minutes, in at most ' . StorefrontDecisionRecord::MAXIMUM_TEXT_LENGTH . ' characters.',
            !$this->isADay($reviewedOn) => 'Give the day the review was done as YYYY-MM-DD.',
            $reviewedOn > CarbonImmutable::now()->toDateString() => 'The day of a review cannot be in the future.',
            !Schema::hasTable('business_settings') => 'There is no business_settings table.',
            default => null,
        };
    }

    private function refusalToRevoke(string $country): ?string
    {
        return match (true) {
            $this->legalReadiness->isGrandfathered($country) => "{$country} keeps the documents it has always had and has no review to withdraw.",
            !Schema::hasTable('business_settings') => 'There is no business_settings table.',
            default => null,
        };
    }

    private function isADay(string $text): bool
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $day !== false && $day->format('Y-m-d') === $text;
    }

    /** @return array{status: string, reviewed_by: null, reference: null, reviewed_on: null, recorded_at: null} */
    private function status(string $status): array
    {
        return ['status' => $status, 'reviewed_by' => null, 'reference' => null, 'reviewed_on' => null, 'recorded_at' => null];
    }

    private function textOf(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
