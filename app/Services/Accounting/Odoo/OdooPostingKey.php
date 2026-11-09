<?php

namespace App\Services\Accounting\Odoo;

use App\Models\OperationalCostEntry;

/**
 * The identity of one fact being posted to Odoo — and the only thing standing between a retry and a duplicate entry in the
 * customer's books.
 *
 * **ODOO DOCUMENTS NO IDEMPOTENCY MECHANISM.** Its external-API reference describes no external-reference field, no uniqueness
 * constraint and no upsert: `create` creates, every time it is called. So a second `create` of the same fact produces a second
 * journal entry, and the customer's accountant finds the same cost twice with nothing in Odoo to have prevented it.
 *
 * Three things together prevent that, and none of them is optional:
 *
 *  1. **A unique key on OUR side.** `(source_type, source_id, source_version)` is unique in `odoo_posting_queue`, so the same fact
 *     at the same version cannot even be queued twice.
 *  2. **A reference Odoo can be SEARCHED by.** That key is written into the entry's `ref` field at Odoo, so before any create the
 *     question "did my last attempt land?" has an answer that comes from Odoo rather than from our own hopes. This is the nearest
 *     thing to uniqueness Odoo can be asked for, and it is why the key has to be a string a `search_read` domain can match
 *     exactly — not a hash of something unstable, and not anything a person might edit.
 *  3. **A version that changes when the fact changes.** A corrected figure is a NEW version and therefore a new entry (by
 *     reversal, per the accounting policy), never a second posting of the same version and never an edit of a final entry.
 *
 * The version is taken from what the ledger itself freezes. `OperationalCostEntry::FROZEN_WHEN_APPROVED` covers the amount, the
 * currency, the quantity and the dates, so an approved entry's figures cannot move underneath a posted one; the version therefore
 * only has to distinguish "this entry as approved" from "this entry after somebody reopened and re-approved it".
 */
final readonly class OdooPostingKey
{
    public const SOURCE_COST_ENTRY = 'operational_cost_entry';

    /** Long enough to be unmistakable, short enough for Odoo's `ref` and our own indexed column. */
    private const DIGEST_LENGTH = 16;

    private function __construct(
        public string $sourceType,
        public int $sourceId,
        public string $sourceVersion,
        public string $reference,
    ) {
    }

    /**
     * The key for one approved cost entry.
     *
     * `approved_at` is part of the version on purpose: an entry that was approved, reversed and approved again is a different
     * fact from the first approval, and posting it under the first version's reference would silently drop it.
     */
    public static function forCostEntry(OperationalCostEntry $entry): self
    {
        $version = self::versionOf($entry);

        return new self(
            sourceType: self::SOURCE_COST_ENTRY,
            sourceId: (int) $entry->id,
            sourceVersion: $version,
            // Readable at a glance in Odoo, and exactly matchable in a search domain. The prefix says where it came from, so an
            // accountant looking at the entry can tell it was posted by this application rather than typed by somebody.
            reference: 'PKSA-COST-' . (int) $entry->id . '-' . $version,
        );
    }

    /**
     * What makes this version of the entry different from another.
     *
     * Deliberately NOT `updated_at`: a touch that changes nothing would mint a new version and post the same cost twice. It is
     * the figures and the approval that matter, and the ledger freezes those once approved.
     */
    private static function versionOf(OperationalCostEntry $entry): string
    {
        return substr(hash('sha256', implode('|', [
            (int) $entry->id,
            (string) $entry->amount,
            (string) $entry->currency,
            (string) ($entry->base_amount ?? ''),
            (string) ($entry->state?->value ?? ''),
            (string) ($entry->approved_at?->toIso8601String() ?? ''),
        ])), 0, self::DIGEST_LENGTH);
    }

    /** The domain that asks Odoo whether it already holds this entry. One field, one exact value: no fuzzy matching on money. */
    public function searchDomain(): array
    {
        return [['ref', '=', $this->reference]];
    }
}
