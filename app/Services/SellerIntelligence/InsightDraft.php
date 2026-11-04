<?php

namespace App\Services\SellerIntelligence;

use App\Models\SellerInsight;
use App\Services\SellerIntelligence\Severity\ImpactSignals;

/**
 * What a producer returns: one thing worth a seller's attention.
 *
 * A draft, not a row — the engine decides whether it is new, an update to one already standing, or
 * unchanged. That keeps producers free of persistence concerns and keeps identity in one place.
 *
 * Severity can be declared or measured. A detector that supplies `signals` is scored by the severity
 * engine against the seller's own business, which is what makes a stockout on a best seller
 * different from a stockout on something that sells twice a year. A detector that supplies only
 * `severity` keeps the old behaviour — deliberately, so the producers written before the engine
 * existed keep working and can be moved over one at a time rather than all at once.
 */
final class InsightDraft
{
    /**
     * @param  array<string, mixed>|null  $actionParams
     */
    public function __construct(
        public readonly int|string $sellerId,
        public readonly string $type,
        public readonly string $severity,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
        public readonly ?float $metric = null,
        public readonly ?float $impact = null,
        public readonly ?string $actionKey = null,
        public readonly ?array $actionParams = null,
        public readonly ?\DateTimeInterface $expiresAt = null,

        /** Which domain this belongs to, for the Control Tower's sections and the right denominator. */
        public readonly ?string $category = null,

        /** How many things it is about. One issue for forty orders, not forty issues. */
        public readonly int $affectedCount = 1,

        /** When it stops being fixable in time. Distinct from `expiresAt`, which is when it stops being news. */
        public readonly ?\DateTimeInterface $dueAt = null,

        /**
         * What the detector measured about how much this matters.
         *
         * The seller-relative halves — their turnover, their catalogue size — are filled in by the
         * engine, because a detector should not have to know how big the shop is to report a
         * problem in it.
         */
        public readonly ?ImpactSignals $signals = null,

        /** Whatever the issue needs to explain itself later: the figures behind the score. */
        public readonly ?array $metadata = null,
    ) {
    }

    /**
     * Identity: the seller, the kind of problem, and the thing it is about.
     *
     * Deliberately not the title or the numbers — a product that drops from 4 units to 2 is the same
     * warning, updated, not a second one. Producers that address a seller rather than an entity
     * (an account-level warning) collapse to one row per type, which is right.
     */
    public function fingerprint(): string
    {
        return implode('|', [
            $this->sellerId,
            $this->type,
            $this->entityType ?? '-',
            $this->entityId ?? '-',
        ]);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'seller_id' => $this->sellerId,
            'type' => $this->type,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->body,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId === null ? null : (string) $this->entityId,
            'metric' => $this->metric,
            'impact' => $this->impact,
            'affected_count' => max(1, $this->affectedCount),
            'action_key' => $this->actionKey,
            'action_params' => $this->actionParams,
            'metadata' => $this->metadata,
            'expires_at' => $this->expiresAt,
            'due_at' => $this->dueAt,
        ];
    }

    public static function severities(): array
    {
        return array_keys(SellerInsight::SEVERITY_ORDER);
    }
}
