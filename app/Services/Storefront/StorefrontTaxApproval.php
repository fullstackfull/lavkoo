<?php

namespace App\Services\Storefront;

use App\Models\BusinessSetting;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * D1-07 and GCC-05: a storefront other than the default one does not sell until a person with the authority has approved the tax its orders carry, or has decided, in a
 * document, that they carry none. The Saudi setup belongs to the default storefront and is never a legal substitute for another country's, so a storefront with no
 * decision on record cannot sell however complete its currency and its legal texts are, and a storefront taxed by nothing sells only on a recorded decision of no tax.
 *
 * The decision is a business setting per storefront (`storefront_tax_approval_<country>`): what was decided, who approved it, where it is written down and when, and for a
 * setup the fingerprint of what the storefront is taxed with at that moment. A decision counts only while it still describes what the storefront is taxed with: changing
 * a rate, the type, the included flag or the shipping tax after the approval makes it stale, and so does a storefront that was approved as taxed-by-nothing getting a rate.
 * The rates of a product-wise setup sit on each product, so the approval covers the setup and not the products' own rates.
 *
 * This service records what an operator tells it. It does not and cannot approve anything: the person, the document and the rates are the owner's.
 */
#[Singleton]
final class StorefrontTaxApproval
{
    /** The rates the storefront has now were reviewed and approved. */
    public const DECISION_SETUP = 'setup';

    /** It was decided, in a document, that the storefront charges no tax. */
    public const DECISION_NO_TAX = 'no_tax';

    public const DECISIONS = [self::DECISION_SETUP, self::DECISION_NO_TAX];

    /** The default storefront is taxed by the platform's own setup, which is set in the admin panel. */
    public const NOT_REQUIRED = 'not_required';

    public const MISSING = 'missing';

    /** A row that does not say what was decided, by whom, where it is written or when approves nothing. */
    public const INCOMPLETE = 'incomplete';

    /** The decision no longer describes what the storefront is taxed with. */
    public const STALE = 'stale';

    public const APPROVED = 'approved';

    private const SETTING_PREFIX = 'storefront_tax_approval_';

    private const MAXIMUM_TEXT_LENGTH = 191;

    /** A tool, a pipeline or an assistant is not a person with the authority to approve a tax. Best effort on free text: the owner is who runs the command. */
    private const AUTOMATED_IDENTITY = '/\b(claude|anthropic|chatgpt|openai|bot|ci|automation|automated|github[\s_-]*actions?)\b/i';

    public function __construct(private readonly StorefrontTax $tax)
    {
    }

    /** The name of the business setting that holds the decision of one storefront. */
    public static function settingName(string $country): string
    {
        return self::SETTING_PREFIX . strtolower(trim($country));
    }

    /** Whether the storefront has a tax decision that still holds. The default storefront needs none. */
    public function isApproved(string $country): bool
    {
        return in_array($this->statusOf($country)['status'], [self::NOT_REQUIRED, self::APPROVED], true);
    }

    /**
     * @return array{status: string, decision: ?string, approved_by: ?string, reference: ?string, approved_at: ?string}
     */
    public function statusOf(string $country): array
    {
        $code = strtoupper(trim($country));

        if ($code === StorefrontResolver::fromConfig()->defaultCountry()) {
            return $this->status(status: self::NOT_REQUIRED);
        }

        $record = getWebConfig(name: self::settingName($code));

        if ($record === null) {
            return $this->status(status: self::MISSING);
        }

        if (!is_array($record)) {
            return $this->status(status: self::INCOMPLETE);
        }

        $given = [
            'decision' => $this->textOf($record['decision'] ?? null),
            'approved_by' => $this->textOf($record['approved_by'] ?? null),
            'reference' => $this->textOf($record['reference'] ?? null),
            'approved_at' => $this->textOf($record['approved_at'] ?? null),
        ];

        if (!$this->isComplete(given: $given, fingerprint: $this->textOf($record['fingerprint'] ?? null))) {
            return ['status' => self::INCOMPLETE] + $given;
        }

        return ['status' => $this->stillHolds(country: $code, decision: (string) $given['decision'], fingerprint: $this->textOf($record['fingerprint'] ?? null)) ? self::APPROVED : self::STALE] + $given;
    }

    /**
     * Records the decision of a person with the authority for one storefront, with its audit line, in one transaction: a decision whose trace cannot be written is not
     * recorded. A setup is approved as it is now, so its rates must exist; a decision of no tax needs a storefront that is taxed by nothing.
     *
     * @return array{decision: string, approved_by: string, reference: string, approved_at: string, fingerprint: ?string}
     * @throws InvalidArgumentException with the reason, when the decision cannot be recorded
     */
    public function approve(string $country, string $decision, string $approvedBy, string $reference): array
    {
        $code = strtoupper(trim($country));
        $effective = $this->tax->of(country: $code);
        $refusal = $this->refusalToApprove(country: $code, decision: $decision, approvedBy: trim($approvedBy), reference: trim($reference), effective: $effective);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        $record = [
            'decision' => $decision,
            'approved_by' => trim($approvedBy),
            'reference' => trim($reference),
            'approved_at' => CarbonImmutable::now()->toIso8601String(),
            'fingerprint' => $decision === self::DECISION_SETUP ? $this->fingerprintOf(country: $code) : null,
        ];

        DB::transaction(function () use ($code, $record, $effective): void {
            $name = self::settingName($code);
            $before = json_decode((string) BusinessSetting::query()->where('type', $name)->lockForUpdate()->value('value'), true);

            BusinessSetting::query()->updateOrInsert(['type' => $name], ['value' => json_encode($record), 'updated_at' => now()]);

            app(AuditLogger::class)->recordOrFail(
                action: 'storefront.tax_decision.approved',
                subject: ['type' => 'storefront', 'id' => $code],
                before: is_array($before) ? $before : null,
                after: $record,
                context: ['taxed_with' => $effective],
            );
        });

        clearWebConfigCacheKeys();

        return $record;
    }

    /**
     * Withdraws the decision, so that the storefront stops selling. False when there was none.
     *
     * @throws InvalidArgumentException when the storefront is the default one or there is no settings table
     */
    public function revoke(string $country, ?string $revokedBy = null): bool
    {
        $code = strtoupper(trim($country));
        $refusal = $this->refusalToRevoke(country: $code);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        $revoked = DB::transaction(function () use ($code, $revokedBy): bool {
            $name = self::settingName($code);
            $before = json_decode((string) BusinessSetting::query()->where('type', $name)->lockForUpdate()->value('value'), true);

            if (BusinessSetting::query()->where('type', $name)->delete() === 0) {
                return false;
            }

            app(AuditLogger::class)->recordOrFail(
                action: 'storefront.tax_decision.revoked',
                subject: ['type' => 'storefront', 'id' => $code],
                before: is_array($before) ? $before : null,
                context: ['revoked_by' => $revokedBy === null || trim($revokedBy) === '' ? null : trim($revokedBy)],
            );

            return true;
        });

        clearWebConfigCacheKeys();

        return $revoked;
    }

    /** What the storefront is taxed with now, as a value that changes when anything an approval covers changes. */
    public function fingerprintOf(string $country): string
    {
        $code = strtoupper(trim($country));
        $effective = $this->tax->of(country: $code);

        return hash('sha256', json_encode([
            'country' => $code,
            'type' => $effective['type'],
            'included' => $effective['included'],
            'rates' => $this->normalised($effective['rates']),
            'shipping' => $this->normalised($effective['shipping']),
        ], JSON_THROW_ON_ERROR));
    }

    /** Whether the setup charges nothing: no rate on the order and none on shipping, and no rates left to the products. */
    public function chargesNothing(array $effective): bool
    {
        return $effective['type'] === StorefrontTax::NO_TAX_TYPE && $effective['rates'] === [] && $effective['shipping'] === [];
    }

    /**
     * @param array{decision: ?string, approved_by: ?string, reference: ?string, approved_at: ?string} $given
     */
    private function isComplete(array $given, ?string $fingerprint): bool
    {
        return in_array($given['decision'], self::DECISIONS, true)
            && $given['approved_by'] !== null
            && $given['reference'] !== null
            && $given['approved_at'] !== null
            && ($given['decision'] !== self::DECISION_SETUP || $fingerprint !== null);
    }

    private function stillHolds(string $country, string $decision, ?string $fingerprint): bool
    {
        $effective = $this->tax->of(country: $country);

        return match ($decision) {
            self::DECISION_NO_TAX => $this->chargesNothing($effective),
            self::DECISION_SETUP => !$this->chargesNothing($effective) && $fingerprint !== null && hash_equals($fingerprint, $this->fingerprintOf(country: $country)),
            default => false,
        };
    }

    private function refusalToApprove(string $country, string $decision, string $approvedBy, string $reference, array $effective): ?string
    {
        $resolver = StorefrontResolver::fromConfig();

        return match (true) {
            $country === $resolver->defaultCountry() => "{$country} is the default storefront: its tax is the platform's, set in the admin panel, and needs no approval here.",
            !in_array($country, $resolver->configuredCountries(), true) => "{$country} is not a configured storefront.",
            !in_array($decision, self::DECISIONS, true) => 'The decision must be ' . self::DECISION_SETUP . ' (the rates it has now are approved) or ' . self::DECISION_NO_TAX . ' (it is decided, in a document, that it charges no tax).',
            $approvedBy === '' || mb_strlen($approvedBy) > self::MAXIMUM_TEXT_LENGTH => 'Say who approved it: the name or the role of the person with the authority, in at most ' . self::MAXIMUM_TEXT_LENGTH . ' characters.',
            preg_match(self::AUTOMATED_IDENTITY, $approvedBy) === 1 => 'An approval names a person or a role with the authority to give it, not a tool or an assistant.',
            $reference === '' || mb_strlen($reference) > self::MAXIMUM_TEXT_LENGTH => 'Say where the decision is written down: the opinion, the ticket or the minutes, in at most ' . self::MAXIMUM_TEXT_LENGTH . ' characters.',
            !Schema::hasTable('business_settings') => 'There is no business_settings table.',
            $decision === self::DECISION_SETUP && $this->chargesNothing($effective) => "{$country} has no tax setup of its own with a rate, so there are no rates to approve: give it its setup with gcc:storefront:tax, or approve the decision that it charges no tax.",
            $decision === self::DECISION_NO_TAX && !$this->chargesNothing($effective) => "{$country} has a tax setup that charges a tax, or leaves its rates to each product: approve it as a setup, or switch its own setup off with gcc:storefront:tax --unset if no tax is what was decided.",
            default => null,
        };
    }

    private function refusalToRevoke(string $country): ?string
    {
        $resolver = StorefrontResolver::fromConfig();

        return match (true) {
            $country === $resolver->defaultCountry() => "{$country} is the default storefront: its tax is the platform's and has no approval to withdraw.",
            !Schema::hasTable('business_settings') => 'There is no business_settings table.',
            default => null,
        };
    }

    /** @return array{status: string, decision: null, approved_by: null, reference: null, approved_at: null} */
    private function status(string $status): array
    {
        return ['status' => $status, 'decision' => null, 'approved_by' => null, 'reference' => null, 'approved_at' => null];
    }

    private function textOf(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param list<array{name: string, rate: float}> $rates
     * @return list<string> the rates as text, in an order that does not depend on how they were read
     */
    private function normalised(array $rates): array
    {
        $texts = array_map(static fn (array $rate): string => $rate['name'] . '|' . number_format($rate['rate'], 3, '.', ''), $rates);
        sort($texts);

        return $texts;
    }
}
