<?php

namespace App\Services\Storefront;

use App\Models\StorefrontLegalAcceptance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Records a customer's acceptance of the text a storefront shows now (GCC-11). Only the document types the configuration marks as
 * needing explicit acceptance are recorded, and identity is kept only when the privacy policy allows it. A guest is kept as a hash.
 *
 * The receipt is scoped by STOREFRONT and by SELLING COMPANY (GCC-13). The storefront has always been on it; the company was the one
 * platform-wide string `GCC_LEGAL_ENTITY`, so a receipt for the Kuwaiti terms named whatever company the installation had been configured
 * with -- which is no answer at all on a platform that sells under six. The company now comes from the registry that answers the same
 * question for an order's own stamp (App\Services\Storefront\LegalEntityRegistry), so a customer's receipt and the sale it belongs to name
 * the same company, and a frozen copy of that company's identity is kept beside its id for the same reason an order keeps one: a receipt
 * is read years later, after the company's name or tax number has been changed, and it has to say what the customer was told THEN.
 *
 * The `legal_entity` text column is left exactly as it was, holding the configured value. It is the contractual label an operator sets and
 * no reader of it changes behaviour here; whether it should be per storefront or retired now the registry answers is the owner's, and it is
 * written down in this track's handoff rather than decided in code.
 *
 * Nothing new is recorded while legal entities are off (the default), before the registry's migrations have run, before this table has the
 * two columns, or while the storefront has no active company: the row is then byte for byte the row that was written before any of this.
 */
final class StorefrontLegalAcceptanceRecorder
{
    private const ENTITY_COLUMN = 'legal_entity_id';

    private const SNAPSHOT_COLUMN = 'entity_snapshot';

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private ?array $columnsAnswer = null;

    public function __construct(
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly LegalEntityRegistry $companies,
    ) {
    }

    public function recordIfRequired(
        string $storefrontCountry,
        string $documentType,
        string $locale,
        ?int $customerId = null,
        ?string $guestId = null,
        ?int $orderId = null,
        ?CarbonImmutable $acceptedAt = null,
    ): ?StorefrontLegalAcceptance {
        if (!in_array($documentType, (array) config('gcc.storefronts.legal.explicit_acceptance', []), true)) {
            return null;
        }

        $text = $this->legalReadiness->currentTextFor($storefrontCountry, $documentType, $locale);
        if ($text === null) {
            throw new RuntimeException("No effective [{$documentType}] text in [{$locale}] for storefront [{$storefrontCountry}].");
        }

        $storeIdentity = (bool) config('gcc.storefronts.legal.store_identity', false);

        $acceptance = StorefrontLegalAcceptance::query()->make([
            'storefront_country' => strtoupper($storefrontCountry),
            'legal_entity' => config('gcc.storefronts.legal.legal_entity'),
            'document_type' => $documentType,
            'locale' => strtolower($locale),
            'legal_document_id' => $text->id,
            'document_version' => $text->version,
            'document_hash' => $text->body_hash,
            'customer_id' => $storeIdentity ? $customerId : null,
            'guest_hash' => $storeIdentity && $guestId !== null ? hash('sha256', $guestId) : null,
            'order_id' => $orderId,
            'accepted_at' => $acceptedAt ?? CarbonImmutable::now(),
        ]);

        $this->nameTheSellingCompany(acceptance: $acceptance, storefrontCountry: $storefrontCountry);
        $acceptance->save();

        return $acceptance;
    }

    /**
     * Puts the storefront's selling company on the receipt: its id, and the frozen copy of its identity the registry hands out, which is
     * the same copy an order's own stamp carries.
     *
     * Set as attributes rather than passed to make(): they are not in the model's fillable list, because mass assignment is how a column of
     * a write-once receipt gets filled from whatever array a caller happened to hold, and these two say which company a customer contracted
     * with. Nothing is set while the registry answers nothing, so a row then carries two nulls and is read as every row written before this.
     */
    private function nameTheSellingCompany(StorefrontLegalAcceptance $acceptance, string $storefrontCountry): void
    {
        if (!$this->columnsReady()) {
            return;
        }

        $entity = $this->companies->forCountry($storefrontCountry);

        if ($entity === null) {
            return;
        }

        $acceptance->{self::ENTITY_COLUMN} = (int) $entity->id;
        $acceptance->{self::SNAPSHOT_COLUMN} = json_encode($this->companies->identityOf($entity), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Whether this table can hold a company at all, that is whether its migration has run. A "no" is re-asked so a worker notices the migration without a restart. */
    private function columnsReady(): bool
    {
        $answer = $this->columnsAnswer;

        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasColumn('storefront_legal_acceptances', self::ENTITY_COLUMN)
            && Schema::hasColumn('storefront_legal_acceptances', self::SNAPSHOT_COLUMN);
        $this->columnsAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
