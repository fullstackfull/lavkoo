<?php

namespace App\Services\SellerIntelligence;

/**
 * One kind of insight, computed for one seller.
 *
 * A producer answers exactly one question about a seller's business and returns what it found.
 * It computes from real records only: a producer with nothing to measure returns nothing, so the
 * seller sees an empty Action Center rather than an invented one.
 */
interface InsightProducer
{
    /** The type this producer owns, e.g. INVENTORY_RISK. One producer per type. */
    public function type(): string;

    /**
     * Everything this seller should currently be told about, by this producer.
     *
     * Whatever it does not return is treated as resolved — a producer never has to remember what it
     * said last time.
     *
     * @return iterable<InsightDraft>
     */
    public function produce(int|string $sellerId): iterable;
}
