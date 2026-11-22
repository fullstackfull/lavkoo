<?php

namespace App\Models;

use App\Services\Marketing\MarketingConsent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One marketing decision a person made, kept for ever.
 *
 * APPEND-ONLY, AND ENFORCED, not merely implied. `const UPDATED_AT = null` says only that the table has no
 * updated_at column; on its own the model can still be saved over and deleted, which is exactly the hole an
 * independent review found in the legal-acceptance receipt (tracker A2-16). So the two model events that could
 * change history REFUSE instead: an update or a delete raises, and the caller has to write a new decision.
 *
 * `decision` is the answer; the answer in force is the newest row for a subject, market, channel and purpose.
 */
class CustomerMarketingConsent extends Model
{
    public const UPDATED_AT = null;

    public const DECISION_GRANTED = 'granted';

    public const DECISION_WITHDRAWN = 'withdrawn';

    /**
     * A CLAIM, not a decision: somebody typed an address into the newsletter box without being signed in, so nobody
     * has shown they own it. Kept because it is what happened, and because the carry-over question A2-13 records as
     * open needs to know who asked; never treated as permission to send.
     */
    public const DECISION_PENDING = 'pending';

    /** The two values that answer the question. A `pending` claim is not one of them. */
    public const DECISIONS_IN_FORCE = [self::DECISION_GRANTED, self::DECISION_WITHDRAWN];

    protected $fillable = [
        'customer_id',
        'subject_hash',
        'market',
        'storefront',
        'legal_entity_id',
        'channel',
        'purpose',
        'decision',
        'text_version',
        'text_hash',
        'source',
        'recorded_at',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'legal_entity_id' => 'integer',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $consent): void {
            throw new RuntimeException('A marketing consent decision cannot be changed. Record a new decision instead.');
        });

        static::deleting(static function (self $consent): void {
            throw new RuntimeException('A marketing consent decision cannot be deleted. Record a withdrawal instead.');
        });
    }

    /**
     * The answer in force for one subject, market, channel and purpose: the newest decision, or null when the person
     * has never answered.
     *
     * Only a granted or a withdrawn row answers. An unverified `pending` claim is skipped, so a stranger typing
     * somebody else's address into the newsletter box neither permits a send nor suppresses one — the market's policy
     * still decides, exactly as it did before the claim existed.
     *
     * A plain static rather than an Eloquent scope, because a scope is reached through Builder::__call, where a named
     * argument is not a named argument of this method, and this project's standard is to call with names.
     */
    public static function latestDecision(string $subjectHash, string $market, string $channel, string $purpose): ?self
    {
        return static::forSubject(subjectHash: $subjectHash, market: $market, channel: $channel, purpose: $purpose)
            ->whereIn('decision', self::DECISIONS_IN_FORCE)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The newest row of ANY kind, an unverified claim included.
     *
     * For a form that is about to write a row and wants to know whether there is anything there already. The gate's
     * own question uses latestDecision instead, because a claim must not answer it.
     */
    public static function latestRecord(string $subjectHash, string $market, string $channel, string $purpose): ?self
    {
        return static::forSubject(subjectHash: $subjectHash, market: $market, channel: $channel, purpose: $purpose)
            ->orderByDesc('id')
            ->first();
    }

    /** The rows about one subject, market, channel and purpose. One place, so the two reads above cannot drift. */
    private static function forSubject(string $subjectHash, string $market, string $channel, string $purpose): Builder
    {
        return static::query()
            ->where('subject_hash', $subjectHash)
            ->where('market', MarketingConsent::market($market))
            ->where('channel', $channel)
            ->where('purpose', $purpose);
    }

    public function granted(): bool
    {
        return $this->decision === self::DECISION_GRANTED;
    }
}
